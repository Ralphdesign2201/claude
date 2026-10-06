<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\ApiError;
use App\Support\DatabaseTransfer;
use App\Support\Db;
use App\Support\Env;
use App\Support\Migrator;
use PDO;
use PDOException;
use Throwable;

/** Wechsel zwischen SQLite und MySQL/MariaDB – in beide Richtungen, mit Datenübernahme und Prüfung. */
final class DatabaseSwitch
{
    private const DB_KEYS = ['DB_DRIVER', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'];

    /** @return array<string,mixed> */
    public static function status(): array
    {
        $cfg = Db::config();
        $pdo = Db::pdo();
        $counts = DatabaseTransfer::counts($pdo);
        $out = [
            'driver' => $cfg['driver'],
            'locked' => Env::source('DB_DRIVER') === 'env',
            'rows' => array_sum($counts),
            'tables' => count($counts),
            'version' => (string) $pdo->query($cfg['driver'] === 'mysql' ? 'SELECT VERSION()' : 'SELECT sqlite_version()')->fetchColumn(),
            'sqlitePath' => self::displayPath(Db::path()),
            'mysql' => [
                'host' => $cfg['host'], 'port' => $cfg['port'], 'name' => $cfg['name'], 'user' => $cfg['user'],
                'passwordSet' => $cfg['password'] !== '',
            ],
        ];
        if ($cfg['driver'] === 'sqlite') {
            $out['size'] = is_file(Db::path()) ? (int) filesize(Db::path()) : 0;
        }
        return $out;
    }

    /**
     * Prüft, ob die Zieldatenbank benutzbar ist (nur lesend, außer einer kurzen Probetabelle).
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function test(string $target, array $input): array
    {
        if ($target === 'sqlite') {
            $path = Db::path();
            $exists = is_file($path);
            $hasData = false;
            if ($exists) {
                try {
                    $hasData = DatabaseTransfer::hasData(Db::connect(['driver' => 'sqlite', 'path' => $path]));
                } catch (Throwable) {
                    $hasData = true; // lieber nichts überschreiben, was wir nicht lesen können
                }
            } elseif (!is_writable(dirname($path)) && is_dir(dirname($path))) {
                throw ApiError::badRequest('Der Ordner für die SQLite-Datei ist nicht beschreibbar: ' . self::displayPath(dirname($path)));
            }
            return ['ok' => true, 'driver' => 'sqlite', 'path' => self::displayPath($path), 'hasTables' => $exists, 'hasData' => $hasData];
        }

        $cfg = self::mysqlConfig($input);
        $pdo = self::connectMysql($cfg);
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        self::assertVersion($version);
        try {
            $pdo->exec('CREATE TABLE "_crm_probe" ("id" VARCHAR(20) NOT NULL PRIMARY KEY) ENGINE=InnoDB');
            $pdo->exec('DROP TABLE "_crm_probe"');
        } catch (PDOException $e) {
            throw ApiError::badRequest('Dem Datenbank-Benutzer fehlt das Recht, Tabellen anzulegen (CREATE/DROP): ' . self::cleanError($e));
        }
        $hasTables = DatabaseTransfer::hasTables($pdo, 'mysql');
        return [
            'ok' => true, 'driver' => 'mysql', 'version' => $version, 'hasTables' => $hasTables,
            'hasData' => $hasTables && DatabaseTransfer::hasData($pdo),
            'same' => Db::isMysql() && $cfg['host'] === Db::config()['host'] && $cfg['port'] === Db::config()['port'] && $cfg['name'] === Db::config()['name'],
        ];
    }

    /**
     * Wechselt die Datenbank: sichert, legt das Schema im Ziel an, kopiert alle Daten, prüft die Zeilenzahlen und
     * stellt erst dann um. Die bisherige Datenbank bleibt unverändert erhalten. Scheitert etwas, bleibt alles beim Alten.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function switchTo(string $target, array $input, bool $overwrite): array
    {
        if (Env::source('DB_DRIVER') === 'env') {
            throw ApiError::conflict('Die Datenbank ist über die Server-Umgebung (DB_DRIVER) festgelegt und lässt sich hier nicht ändern.');
        }
        if (!in_array($target, ['sqlite', 'mysql'], true)) {
            throw ApiError::badRequest('Unbekannter Datenbanktyp');
        }
        $current = Db::config();
        $old = array_intersect_key(Env::settings(), array_flip(self::DB_KEYS));

        if ($target === 'mysql') {
            $cfg = self::mysqlConfig($input);
            if ($current['driver'] === 'mysql' && $cfg['host'] === $current['host'] && $cfg['port'] === $current['port'] && $cfg['name'] === $current['name']) {
                throw ApiError::conflict('Diese MySQL-Datenbank ist bereits aktiv.');
            }
            $dst = self::connectMysql($cfg);
            self::assertVersion((string) $dst->query('SELECT VERSION()')->fetchColumn());
            $newSettings = [
                'DB_DRIVER' => 'mysql', 'DB_HOST' => $cfg['host'], 'DB_PORT' => (string) $cfg['port'],
                'DB_NAME' => $cfg['name'], 'DB_USER' => $cfg['user'], 'DB_PASSWORD' => $cfg['password'],
            ];
        } else {
            if ($current['driver'] === 'sqlite') {
                throw ApiError::conflict('SQLite ist bereits aktiv.');
            }
            $path = Db::path();
            $dst = null;
            $newSettings = ['DB_DRIVER' => 'sqlite'];
        }

        $dstDriver = $target;
        $moved = null;
        $path ??= null;
        if ($target === 'sqlite') {
            if (is_file($path)) {
                $probe = Db::connect(['driver' => 'sqlite', 'path' => $path]);
                if (DatabaseTransfer::hasData($probe) && !$overwrite) {
                    throw ApiError::conflict('In der SQLite-Datei sind bereits Daten. Zum Überschreiben bitte ausdrücklich bestätigen – die alte Datei wird vorher beiseitegelegt.');
                }
                unset($probe);
            }
        } elseif (DatabaseTransfer::hasData($dst) && !$overwrite) {
            throw ApiError::conflict('Die Zieldatenbank enthält bereits Daten. Zum Überschreiben bitte ausdrücklich bestätigen.');
        }

        // Sicherung des bisherigen Standes (nur, wenn das Backup-Zubehör da ist)
        $backup = null;
        $backupWarning = null;
        try {
            $backup = BackupService::create()['name'];
        } catch (Throwable $e) {
            $backupWarning = 'Vorab-Backup nicht möglich: ' . $e->getMessage();
        }

        $src = Db::pdo();
        $rows = [];
        try {
            // Solange kopiert wird, sperrt diese Transaktion Schreibvorgänge in der bisherigen Datenbank
            $rows = Db::transaction(static function () use ($target, $path, &$dst, $dstDriver, $src, $overwrite, &$moved, $newSettings) {
                if ($target === 'sqlite') {
                    if (is_file($path)) {
                        $moved = $path . '.vor-wechsel-' . gmdate('Ymd-His');
                        foreach (['', '-wal', '-shm'] as $suffix) {
                            if (is_file($path . $suffix)) {
                                rename($path . $suffix, $moved . $suffix);
                            }
                        }
                    }
                    $dst = Db::connect(['driver' => 'sqlite', 'path' => $path]);
                }
                Migrator::run($dst, $dstDriver);
                if (DatabaseTransfer::hasData($dst)) {
                    DatabaseTransfer::wipe($dst, $dstDriver);
                }
                $counts = DatabaseTransfer::copy($src, $dst, $dstDriver);
                Env::saveSettings($newSettings);
                return $counts;
            });
        } catch (Throwable $e) {
            if ($moved !== null && !is_file($path)) {
                foreach (['', '-wal', '-shm'] as $suffix) {
                    if (is_file($moved . $suffix)) {
                        @rename($moved . $suffix, $path . $suffix);
                    }
                }
            }
            throw $e instanceof ApiError ? $e : new ApiError(500, 'Der Wechsel wurde abgebrochen, alles blieb unverändert: ' . self::cleanError($e));
        }

        // Neue Verbindung prüfen; bei Problemen die alten Einstellungen zurückschreiben
        Db::reset();
        try {
            Db::value('SELECT COUNT(*) FROM "User"');
        } catch (Throwable $e) {
            Env::saveSettings(array_fill_keys(self::DB_KEYS, null) + $old);
            Db::reset();
            throw new ApiError(500, 'Die neue Datenbank antwortet nicht – es wurde zur bisherigen zurückgekehrt: ' . self::cleanError($e));
        }

        return [
            'driver' => $target, 'rows' => array_sum($rows), 'tables' => count($rows), 'backup' => $backup, 'warning' => $backupWarning,
            'oldFile' => $moved !== null ? self::displayPath($moved) : null,
        ];
    }

    /**
     * @param array<string,mixed> $in
     * @return array{driver:string,host:string,port:int,name:string,user:string,password:string}
     */
    private static function mysqlConfig(array $in): array
    {
        $host = trim((string) ($in['host'] ?? ''));
        $name = trim((string) ($in['name'] ?? ''));
        $user = trim((string) ($in['user'] ?? ''));
        $password = (string) ($in['password'] ?? '');
        $port = (int) ($in['port'] ?? 3306);
        if ($password === '' && ($in['keepPassword'] ?? false) && Db::config()['password'] !== '') {
            $password = Db::config()['password']; // gespeichertes Passwort weiterverwenden
        }
        $errors = [];
        if ($host === '' || !preg_match('/^[A-Za-z0-9._:\-\[\]]{1,200}$/', $host)) {
            $errors['host'] = ['Bitte den Server angeben (z. B. localhost)'];
        }
        if ($port < 1 || $port > 65535) {
            $errors['port'] = ['Ungültiger Port'];
        }
        if ($name === '' || !preg_match('/^[A-Za-z0-9_$\-]{1,64}$/', $name)) {
            $errors['name'] = ['Bitte den Datenbanknamen angeben (Buchstaben, Ziffern, _ und -)'];
        }
        if ($user === '' || mb_strlen($user) > 80) {
            $errors['user'] = ['Bitte den Benutzer angeben'];
        }
        if ($errors !== []) {
            throw ApiError::badRequest('Bitte die markierten Felder prüfen', ['formErrors' => [], 'fieldErrors' => (object) $errors]);
        }
        return ['driver' => 'mysql', 'host' => $host, 'port' => $port, 'name' => $name, 'user' => $user, 'password' => $password];
    }

    /** @param array<string,mixed> $cfg */
    private static function connectMysql(array $cfg): PDO
    {
        try {
            return Db::connect($cfg);
        } catch (PDOException $e) {
            $code = (int) ($e->errorInfo[1] ?? $e->getCode());
            $hint = match ($code) {
                1045 => 'Benutzer oder Passwort stimmen nicht.',
                1049 => 'Die Datenbank „' . $cfg['name'] . '“ gibt es nicht. Bitte zuerst im Hosting-Panel anlegen.',
                1044 => 'Der Benutzer hat keinen Zugriff auf diese Datenbank.',
                2002, 2006 => 'Der Server ist unter dieser Adresse nicht erreichbar.',
                default => self::cleanError($e),
            };
            throw ApiError::badRequest('Verbindung fehlgeschlagen: ' . $hint);
        } catch (\RuntimeException $e) {
            throw ApiError::badRequest($e->getMessage());
        }
    }

    private static function assertVersion(string $version): void
    {
        if (!preg_match('/(\d+)\.(\d+)\.(\d+)/', $version, $m)) {
            return;
        }
        $v = (int) $m[1] * 10000 + (int) $m[2] * 100 + (int) $m[3];
        $maria = stripos($version, 'mariadb') !== false;
        if (($maria && $v < 100300) || (!$maria && $v < 80013)) {
            throw ApiError::badRequest('Der Datenbankserver ist zu alt (' . $version . '). Benötigt wird MySQL 8.0.13 oder neuer bzw. MariaDB 10.3 oder neuer.');
        }
    }

    private static function cleanError(Throwable $e): string
    {
        $msg = preg_replace('/^SQLSTATE\[[^\]]*\]:?\s*(\[[0-9]+\]\s*)?/', '', $e->getMessage()) ?? $e->getMessage();
        return mb_substr(trim($msg), 0, 300);
    }

    /** Pfade nur relativ zum Projekt anzeigen. */
    private static function displayPath(string $path): string
    {
        return str_starts_with($path, APP_ROOT . '/') ? substr($path, strlen(APP_ROOT) + 1) : $path;
    }
}
