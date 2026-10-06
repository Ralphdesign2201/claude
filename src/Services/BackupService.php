<?php

declare(strict_types=1);

namespace App\Services;

use App\Controllers\DocumentsController;
use App\Http\ApiError;
use App\Support\Dates;
use App\Support\Db;
use App\Support\DatabaseTransfer;
use App\Support\Env;
use PDO;
use RuntimeException;
use ZipArchive;

/**
 * Sicherungen als ZIP: konsistenter Datenbank-Snapshot (SQLite VACUUM INTO, auch bei laufendem Betrieb),
 * alle hochgeladenen Dokumente und ein Manifest. Mit BACKUP_PASSPHRASE wird das Archiv mit AES-256 verschlüsselt.
 */
final class BackupService
{
    public const NAME_PATTERN = '/^crm-backup-(\d{8})-(\d{6})\.zip$/';
    private const DB_ENTRY = 'database.sqlite';
    private const KEY_ENTRY = 'update.key';

    public static function dir(): string
    {
        return self::ensureDir(rtrim(Env::get('BACKUP_DIR', '') ?: APP_ROOT . '/backups', '/\\'));
    }

    /** @return list<array{name:string,size:int,createdAt:string}> neueste zuerst */
    public static function list(?string $dir = null): array
    {
        $dir ??= self::dir();
        $items = [];
        foreach (glob($dir . '/crm-backup-*.zip') ?: [] as $file) {
            if (preg_match(self::NAME_PATTERN, basename($file), $m)) {
                $items[] = ['name' => basename($file), 'size' => (int) filesize($file), 'createdAt' => self::timestamp($m[1], $m[2])];
            }
        }
        usort($items, static fn ($a, $b) => strcmp($b['name'], $a['name']));
        return $items;
    }

    public static function isEncrypted(): bool
    {
        return (Env::get('BACKUP_PASSPHRASE', '') ?? '') !== '';
    }

    /**
     * Erstellt ein Backup, prüft es, kopiert es optional in BACKUP_COPY_DIR und räumt alte Sicherungen auf.
     *
     * @return array{name:string,size:int,createdAt:string,uploads:int,encrypted:bool,copied:bool,copyError:?string,deleted:int}
     */
    public static function create(): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Die PHP-Erweiterung „zip“ fehlt – sie wird für Backups benötigt.');
        }
        $dir = self::dir();
        $passphrase = Env::get('BACKUP_PASSPHRASE', '') ?? '';
        $snapshot = $dir . '/.snapshot-' . bin2hex(random_bytes(6)) . '.db';
        $partial = $dir . '/.partial-' . bin2hex(random_bytes(6)) . '.zip';

        try {
            // Konsistenter Schnappschuss, ohne die laufende Anwendung zu blockieren
            if (Db::isMysql()) {
                DatabaseTransfer::exportToSqlite(Db::pdo(), $snapshot); // das Backup bleibt eine portable SQLite-Datei
            } else {
                Db::pdo()->exec('VACUUM INTO ' . Db::pdo()->quote($snapshot));
            }
            self::assertHealthy($snapshot);
            $dbHash = hash_file('sha256', $snapshot);

            $uploads = self::uploadFiles();
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $name = 'crm-backup-' . $now->format('Ymd-His') . '.zip';
            while (is_file($dir . '/' . $name)) { // zwei Backups in derselben Sekunde
                $now = $now->modify('+1 second');
                $name = 'crm-backup-' . $now->format('Ymd-His') . '.zip';
            }

            $zip = new ZipArchive();
            if ($zip->open($partial, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Backup-Archiv konnte nicht angelegt werden.');
            }
            $zip->addFile($snapshot, self::DB_ENTRY);
            foreach ($uploads as $file) {
                $zip->addFile(DocumentsController::uploadDir() . '/' . $file, 'uploads/' . $file);
            }
            $zip->addFromString('manifest.json', json_encode([
                'createdAt' => $now->format(Dates::FORMAT),
                'database' => ['file' => self::DB_ENTRY, 'sha256' => $dbHash, 'bytes' => filesize($snapshot)],
                'uploads' => count($uploads),
                'migrations' => array_column(Db::all('SELECT "name" FROM "_migrations" ORDER BY "name"'), 'name'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // Der Signaturschlüssel für Updates muss ein Backup überleben: ohne ihn können ausgelieferte Installationen keine Updates mehr prüfen
            $keyFile = UpdateSigner::keyFile();
            $withKey = trim(Env::get('UPDATE_SECRET_KEY', '') ?? '') === '' && is_file($keyFile);
            if ($withKey) {
                $zip->addFile($keyFile, self::KEY_ENTRY);
            }
            if ($passphrase !== '') {
                $zip->setPassword($passphrase);
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    if (!$zip->setEncryptionIndex($i, ZipArchive::EM_AES_256)) {
                        throw new RuntimeException('Verschlüsselung nicht möglich (libzip ohne AES-Unterstützung). Backup abgebrochen, statt unverschlüsselt zu speichern.');
                    }
                }
            }
            if (!$zip->close()) {
                throw new RuntimeException('Backup-Archiv konnte nicht geschrieben werden (Speicherplatz?).');
            }

            self::verify($partial, $passphrase, $dbHash, count($uploads) + ($withKey ? 1 : 0));
            if (!rename($partial, $dir . '/' . $name)) {
                throw new RuntimeException('Backup konnte nicht abgelegt werden.');
            }
        } finally {
            @unlink($snapshot);
            if (is_file($partial)) {
                @unlink($partial);
            }
        }

        $copied = false;
        $copyError = null;
        $copyDir = trim(Env::get('BACKUP_COPY_DIR', '') ?? '');
        if ($copyDir !== '') {
            try {
                $target = self::ensureDir(rtrim($copyDir, '/\\'));
                if (!copy($dir . '/' . $name, $target . '/' . $name)) {
                    throw new RuntimeException('Kopie nach BACKUP_COPY_DIR fehlgeschlagen.');
                }
                $copied = true;
            } catch (RuntimeException $e) {
                $copyError = $e->getMessage();
            }
        }

        return [
            'name' => $name,
            'size' => (int) filesize($dir . '/' . $name),
            'createdAt' => $now->format(Dates::FORMAT),
            'uploads' => count($uploads),
            'encrypted' => $passphrase !== '',
            'copied' => $copied,
            'copyError' => $copyError,
            'deleted' => self::rotate(),
        ];
    }

    /**
     * Behält die neuesten BACKUP_KEEP Sicherungen (Standard 14) und zusätzlich je Monat die neueste
     * für BACKUP_KEEP_MONTHS Monate (Standard 12). Gilt für das Backup- und das Kopierverzeichnis.
     */
    public static function rotate(): int
    {
        $dirs = [self::dir()];
        $copyDir = trim(Env::get('BACKUP_COPY_DIR', '') ?? '');
        if ($copyDir !== '' && is_dir($copyDir)) {
            $dirs[] = rtrim($copyDir, '/\\');
        }

        $keep = max(1, Env::int('BACKUP_KEEP', 14));
        $months = max(0, Env::int('BACKUP_KEEP_MONTHS', 12));
        $deleted = 0;
        foreach ($dirs as $dir) {
            $items = self::list($dir);
            $protected = array_map(static fn ($i) => $i['name'], array_slice($items, 0, $keep));
            $perMonth = []; // Neueste zuerst: der erste Treffer je Monat ist die jüngste Sicherung dieses Monats
            foreach ($items as $item) {
                $perMonth[substr($item['createdAt'], 0, 7)] ??= $item['name'];
            }
            foreach (array_slice($perMonth, 0, $months) as $name) {
                $protected[] = $name;
            }
            foreach ($items as $item) {
                if (!in_array($item['name'], $protected, true) && @unlink($dir . '/' . $item['name'])) {
                    $deleted++;
                }
            }
        }
        return $deleted;
    }

    /** Für den Cron-Lauf: nur ein neues Backup, wenn das letzte älter als BACKUP_INTERVAL_HOURS (Standard 24) ist. */
    public static function runIfDue(): ?array
    {
        if (!Env::bool('BACKUP_AUTO', true)) {
            return null;
        }
        $last = self::list()[0]['createdAt'] ?? null;
        $hours = max(1, Env::int('BACKUP_INTERVAL_HOURS', 24));
        if ($last !== null && $last > gmdate(Dates::FORMAT, time() - $hours * 3600 + 60)) {
            return null;
        }
        return self::create();
    }

    /** Absoluter Pfad eines Backups; nur streng geprüfte Dateinamen werden akzeptiert. */
    public static function path(string $name): string
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw ApiError::notFound('Backup nicht gefunden');
        }
        $path = self::dir() . '/' . $name;
        if (!is_file($path)) {
            throw ApiError::notFound('Backup nicht gefunden');
        }
        return $path;
    }

    public static function delete(string $name): void
    {
        unlink(self::path($name));
    }

    /**
     * Stellt ein Backup wieder her (für bin/restore.php). Die aktuelle Datenbank wird vorher gesichert.
     *
     * @return array{database:string,uploads:int,safetyCopy:?string}
     */
    public static function restore(string $archive, string $passphrase = ''): array
    {
        $zip = new ZipArchive();
        if (!is_file($archive) || $zip->open($archive) !== true) {
            throw new RuntimeException('Backup-Datei konnte nicht geöffnet werden.');
        }
        if ($passphrase !== '') {
            $zip->setPassword($passphrase);
        }
        $dbBytes = $zip->getFromName(self::DB_ENTRY);
        if ($dbBytes === false || $dbBytes === '') {
            throw new RuntimeException('Datenbank im Backup nicht lesbar – falsches Passwort (BACKUP_PASSPHRASE) oder beschädigtes Archiv.');
        }
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        if (is_array($manifest) && isset($manifest['database']['sha256']) && !hash_equals($manifest['database']['sha256'], hash('sha256', $dbBytes))) {
            throw new RuntimeException('Prüfsumme der Datenbank stimmt nicht – das Backup ist beschädigt.');
        }

        $mysql = Db::isMysql();
        $target = $mysql ? 'MySQL/MariaDB (' . Db::config()['name'] . ')' : Db::path();
        $targetDir = self::ensureDir($mysql ? self::dir() : dirname($target));
        $incoming = $targetDir . '/.restore-' . bin2hex(random_bytes(6)) . '.db';
        file_put_contents($incoming, $dbBytes);
        try {
            self::assertHealthy($incoming);

            $safety = null;
            if ($mysql) {
                $pdo = Db::pdo();
                if (DatabaseTransfer::hasData($pdo)) {
                    $safety = self::dir() . '/vor-wiederherstellung-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.sqlite';
                    DatabaseTransfer::exportToSqlite($pdo, $safety);
                }
                DatabaseTransfer::importFromSqlite($incoming, $pdo, 'mysql');
            } else {
                if (is_file($target)) {
                    $safety = self::dir() . '/vor-wiederherstellung-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.sqlite';
                    $old = new PDO('sqlite:' . $target);
                    $old->exec('VACUUM INTO ' . $old->quote($safety));
                    unset($old);
                }
                @unlink($target . '-wal');
                @unlink($target . '-shm');
                if (!rename($incoming, $target)) {
                    throw new RuntimeException('Datenbank konnte nicht ersetzt werden.');
                }
            }
        } finally {
            if (is_file($incoming)) {
                @unlink($incoming);
            }
        }

        // Signaturschlüssel für Updates zurückspielen (ein abweichender vorhandener wird als Kopie aufgehoben)
        $keyData = trim(Env::get('UPDATE_SECRET_KEY', '') ?? '') === '' ? $zip->getFromName(self::KEY_ENTRY) : false;
        if ($keyData !== false && $keyData !== '') {
            $keyFile = UpdateSigner::keyFile();
            self::ensureDir(dirname($keyFile));
            if (is_file($keyFile) && trim((string) file_get_contents($keyFile)) !== trim($keyData)) {
                copy($keyFile, $keyFile . '.vor-wiederherstellung-' . gmdate('Ymd-His'));
            }
            file_put_contents($keyFile, $keyData);
            @chmod($keyFile, 0600);
        }

        $uploadDir = self::ensureDir(DocumentsController::uploadDir());
        $restored = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            // Nur flache, harmlose Dateinamen unter uploads/ (Schutz vor „Zip Slip“)
            if (preg_match('#^uploads/((?:tickets/)?[A-Za-z0-9][A-Za-z0-9._-]*)$#', $entry, $m)) {
                $data = $zip->getFromIndex($i);
                if ($data !== false) {
                    self::ensureDir(dirname($uploadDir . '/' . $m[1]));
                    file_put_contents($uploadDir . '/' . $m[1], $data);
                    $restored++;
                }
            }
        }
        $zip->close();

        return ['database' => $target, 'uploads' => $restored, 'safetyCopy' => $safety];
    }

    private static function verify(string $archive, string $passphrase, string $dbHash, int $uploads): void
    {
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Backup-Prüfung fehlgeschlagen: Archiv nicht lesbar.');
        }
        if ($passphrase !== '') {
            $zip->setPassword($passphrase);
        }
        $db = $zip->getFromName(self::DB_ENTRY);
        $ok = $db !== false && hash('sha256', $db) === $dbHash && $zip->numFiles === $uploads + 2;
        $zip->close();
        if (!$ok) {
            throw new RuntimeException('Backup-Prüfung fehlgeschlagen: Inhalt stimmt nicht mit der Datenbank überein.');
        }
    }

    private static function assertHealthy(string $dbFile): void
    {
        $pdo = new PDO('sqlite:' . $dbFile);
        $result = $pdo->query('PRAGMA integrity_check')->fetchColumn();
        $hasUsers = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'User'")->fetchColumn();
        if ($result !== 'ok' || (int) $hasUsers !== 1) {
            throw new RuntimeException('Die Datenbank hat die Integritätsprüfung nicht bestanden (' . (is_string($result) ? $result : 'unbekannt') . ').');
        }
    }

    /** @return list<string> Dateinamen im Upload-Ordner */
    private static function uploadFiles(): array
    {
        $dir = DocumentsController::uploadDir();
        $files = [];
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $file) {
            if ($file[0] !== '.' && is_file($dir . '/' . $file)) {
                $files[] = $file;
            }
        }
        // Ticket-Anhänge liegen in einem eigenen Unterordner (nicht öffentlich abrufbar)
        $sub = $dir . '/tickets';
        foreach (is_dir($sub) ? (scandir($sub) ?: []) : [] as $file) {
            if ($file[0] !== '.' && is_file($sub . '/' . $file)) {
                $files[] = 'tickets/' . $file;
            }
        }
        return $files;
    }

    private static function timestamp(string $date, string $time): string
    {
        return sprintf('%s-%s-%sT%s:%s:%s.000Z', substr($date, 0, 4), substr($date, 4, 2), substr($date, 6, 2), substr($time, 0, 2), substr($time, 2, 2), substr($time, 4, 2));
    }

    private static function ensureDir(string $dir): string
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Ordner $dir konnte nicht angelegt werden.");
        }
        return $dir;
    }
}
