<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Dates;
use App\Support\Db;
use App\Support\Env;
use App\Support\Migrator;
use App\Support\Product;
use RuntimeException;
use ZipArchive;

/**
 * Sichere Updates: Das Paket kommt vom Lizenzserver (nur bei gültiger Lizenz und gültigem Update-Zeitraum), wird anhand der
 * signierten Prüfsumme verifiziert, vor dem Einspielen gesichert und bei einem Fehler automatisch zurückgerollt.
 * Daten, Einstellungen, Schlüssel und Uploads werden nie angefasst.
 */
/** Es gibt nichts (mehr) zu installieren – kein Fehler, wenn zuvor schon Schritte gelaufen sind. */
class NothingToInstall extends RuntimeException
{
}

final class UpdateService
{
    /** Nur diese Verzeichnisse und Dateien darf ein Update überschreiben. */
    private const DIRS = ['src', 'public', 'bin', 'examples', 'database/migrations'];
    private const ROOT_FILES = ['VERSION', '.htaccess', 'README.md', 'composer.json', '.env.example', 'manifest.json'];
    /** Nie über ein Update ausliefern oder löschen */
    private const PROTECTED = ['public/install.php', 'product.json'];

    private static function stateFile(): string
    {
        return APP_ROOT . '/database/update-state.json';
    }

    /** @return array<string,mixed> */
    public static function lastState(): array
    {
        $d = is_file(self::stateFile()) ? json_decode((string) @file_get_contents(self::stateFile()), true) : null;

        return is_array($d) ? $d : [];
    }

    /** Ohne Lizenzschlüssel (z. B. die Beta-Version) gibt es nur öffentliche Releases; mit Schlüssel auch die lizenzierten. */
    private static function licensed(): bool
    {
        return Product::enforced() && ProductLicense::key() !== '';
    }

    private static function requireSource(): void
    {
        if (Product::server() === '' || Product::publicKeys() === []) {
            throw new RuntimeException('Für diese Installation ist kein Update-Server eingetragen (product.json fehlt oder ist leer).');
        }
    }

    /**
     * Fragt den Update-Server nach der neuesten Version und dem nächsten Installationsschritt.
     *
     * @return array{current:string,latest:?array<string,mixed>,target:?array<string,mixed>,changes:list<array<string,mixed>>,entitled:bool,updatesUntil:?string,download:?array<string,mixed>,checkedAt:string,licensed:bool}
     */
    public static function check(): array
    {
        self::requireSource();
        $licensed = self::licensed();
        $client = ProductLicense::client($licensed ? null : '');
        $channel = Env::get('UPDATE_CHANNEL', 'stable') === 'beta' ? 'beta' : 'stable';
        $payload = $client->signedRequest($licensed ? '/api/license/update-check' : '/api/license/update-public', ['product' => Product::slug(), 'channel' => $channel], 'update');
        if ($payload === null) {
            throw new RuntimeException('Der Update-Server hat keine gültige Antwort geliefert' . ($client->error ? ' (' . $client->error . ')' : '') . '.');
        }
        if (!$payload['valid']) {
            throw new RuntimeException('Die Lizenz wurde abgelehnt: ' . ProductLicense::message($payload['reason']));
        }
        $target = $payload['target'] ?? ($payload['latest'] !== null && ($payload['entitled'] ?? false) ? $payload['latest'] : null); // ältere Server liefern nur „latest“
        $result = [
            'current' => Product::version(), 'latest' => $payload['latest'], 'target' => $target, 'changes' => is_array($payload['changes'] ?? null) ? $payload['changes'] : [],
            'entitled' => $target !== null, 'updatesUntil' => $payload['updatesUntil'] ?? null, 'download' => $payload['download'] ?? null, 'checkedAt' => Dates::now(), 'licensed' => $licensed,
        ];
        @file_put_contents(self::stateFile(), json_encode(['checkedAt' => $result['checkedAt'], 'latest' => $payload['latest'], 'entitled' => $result['entitled'], 'current' => $result['current'], 'changes' => $result['changes'], 'licensed' => $licensed]));

        return $result;
    }

    /** Für den täglichen Cron-Lauf: höchstens alle 20 Stunden nach Updates fragen (nur Hinweis, installiert wird nichts). Fehler sind hier unerheblich. */
    public static function checkIfDue(): void
    {
        if (Product::server() === '' || Product::publicKeys() === []) {
            return;
        }
        $at = self::lastState()['checkedAt'] ?? null;
        if (is_string($at) && strtotime($at) > time() - 20 * 3600) {
            return;
        }
        try {
            self::check();
        } catch (\Throwable) {
            // Server nicht erreichbar o. Ä.: beim nächsten Lauf erneut
        }
    }

    /**
     * Installiert alle verfügbaren Updates nacheinander. Wer mehrere Versionen verpasst hat, springt in der Regel mit einem Schritt
     * zur neuesten (jedes Paket ist ein vollständiger Stand); nur bei Versionen mit Mindestversion („minFrom“) sind es mehrere Schritte.
     *
     * @return array{from:string,to:string,files:int,migrations:list<string>,backup:string,removed:int,steps:list<array<string,mixed>>}
     */
    public static function installAll(int $maxSteps = 8): array
    {
        $steps = [];
        for ($i = 0; $i < $maxSteps; $i++) {
            try {
                $steps[] = self::install();
            } catch (NothingToInstall $e) {
                if ($steps === []) {
                    throw $e;
                }
                break;
            }
        }
        $first = $steps[0];
        $last = $steps[count($steps) - 1];

        return ['from' => $first['from'], 'to' => $last['to'], 'files' => $last['files'], 'migrations' => array_merge(...array_column($steps, 'migrations')),
            'backup' => $first['backup'], 'removed' => array_sum(array_column($steps, 'removed')), 'steps' => $steps];
    }

    /**
     * Lädt, prüft und installiert den nächsten Schritt (die höchste direkt erreichbare Version).
     *
     * @return array{from:string,to:string,files:int,migrations:list<string>,backup:string,removed:int}
     */
    public static function install(): array
    {
        $info = self::check();
        $latest = $info['target'];
        if ($info['latest'] === null) {
            throw new NothingToInstall('Die Software ist bereits auf dem neuesten Stand (' . $info['current'] . ').');
        }
        if ($latest === null || $info['download'] === null) {
            throw new NothingToInstall('Version ' . $info['latest']['version'] . ' erschien nach dem Ende deines Update-Zeitraums' . ($info['updatesUntil'] ? ' (' . substr((string) $info['updatesUntil'], 0, 10) . ')' : '') . '. Mit einer Verlängerung bekommst du sie.');
        }
        $minPhp = $latest['minPhp'] ?: '8.1';
        if (version_compare(PHP_VERSION, $minPhp, '<')) {
            throw new RuntimeException("Diese Version braucht PHP $minPhp oder neuer (hier: " . PHP_VERSION . '). Bitte im Hosting-Panel umstellen.');
        }
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Die PHP-Erweiterung „zip“ fehlt.');
        }
        self::checkWritable();
        if ((float) @disk_free_space(APP_ROOT) < max(30 * 1024 * 1024, 4 * (int) $latest['size'])) {
            throw new RuntimeException('Nicht genug freier Speicherplatz für das Update.');
        }

        $lock = self::lock();
        $stage = APP_ROOT . '/database/.update-stage-' . bin2hex(random_bytes(5));
        $zipFile = APP_ROOT . '/database/.update-' . bin2hex(random_bytes(5)) . '.zip';
        try {
            // 1. Herunterladen und prüfen – erst danach wird irgendetwas angefasst
            $client = ProductLicense::client($info['licensed'] ? null : '');
            $data = $client->fetch('/api/license/download', ['token' => $info['download']['token']]);
            if ($data === null || $data === '') {
                throw new RuntimeException('Das Paket konnte nicht heruntergeladen werden' . ($client->error ? ' (' . $client->error . ')' : '') . '.');
            }
            if (strlen($data) !== (int) $latest['size'] || !hash_equals((string) $latest['sha256'], hash('sha256', $data))) {
                throw new RuntimeException('Die Prüfsumme des Pakets stimmt nicht – Download abgebrochen, nichts wurde verändert.');
            }
            if (!$client->verifyRaw(ReleaseService::message(Product::slug(), $latest['version'], $latest['sha256']), (string) $latest['signature'])) {
                throw new RuntimeException('Die Signatur des Pakets ist ungültig – es stammt nicht vom Hersteller. Nichts wurde verändert.');
            }
            file_put_contents($zipFile, $data);
            unset($data);

            // 2. Inhalt prüfen und in einen Zwischenordner entpacken
            $manifest = ReleaseService::readManifest($zipFile);
            if ($manifest['product'] !== Product::slug() || $manifest['version'] !== $latest['version']) {
                throw new RuntimeException('Das Paket passt nicht zur angekündigten Version.');
            }
            $files = self::extract($zipFile, $stage, $manifest);

            // 3. Sicherung des alten Codes (und der Daten, soweit möglich)
            $backup = self::backupCode($files);
            try {
                BackupService::create();
            } catch (\Throwable) {
                // Der Code ist gesichert; ein Daten-Backup ist zusätzlich nett, aber hier nicht zwingend
            }

            // 4. Einspielen mit Rücksicherung bei Fehlern
            $oldFiles = self::installedFiles();
            $applied = [];
            try {
                foreach ($files as $rel) {
                    self::place($stage . '/' . $rel, APP_ROOT . '/' . $rel);
                    $applied[] = $rel;
                }
                $removed = 0;
                foreach (array_diff($oldFiles, $files) as $rel) {
                    if (self::allowed($rel) && is_file(APP_ROOT . '/' . $rel)) {
                        @unlink(APP_ROOT . '/' . $rel);
                        $removed++;
                    }
                }
                if (function_exists('opcache_reset')) {
                    @opcache_reset();
                }
                clearstatcache();
                $migrations = Migrator::run(Db::pdo(), Db::driver());
                if (trim((string) @file_get_contents(APP_ROOT . '/VERSION')) !== $latest['version']) {
                    throw new RuntimeException('Die neue Version wurde nicht korrekt eingespielt.');
                }
            } catch (\Throwable $e) {
                self::restore($backup);
                foreach (array_diff($applied, self::zipNames($backup)) as $rel) {
                    @unlink(APP_ROOT . '/' . $rel); // neu hinzugekommene Dateien wieder entfernen
                }
                if (function_exists('opcache_reset')) {
                    @opcache_reset();
                }
                throw new RuntimeException('Das Update ist fehlgeschlagen und wurde zurückgenommen (' . $e->getMessage() . ').');
            }

            self::log(['at' => Dates::now(), 'from' => $info['current'], 'to' => $latest['version'], 'files' => count($files), 'migrations' => $migrations, 'backup' => basename($backup)]);
            @file_put_contents(self::stateFile(), json_encode(['checkedAt' => Dates::now(), 'latest' => null, 'entitled' => true, 'current' => $latest['version']]));

            return ['from' => $info['current'], 'to' => $latest['version'], 'files' => count($files), 'migrations' => $migrations, 'backup' => basename($backup), 'removed' => $removed];
        } finally {
            self::removeDir($stage);
            @unlink($zipFile);
            self::unlock($lock);
        }
    }

    /** Stellt den Code aus einer Code-Sicherung wieder her (bin/update.php --rollback=…). */
    public static function rollback(string $backupZip): int
    {
        if (!is_file($backupZip)) {
            throw new RuntimeException('Sicherungsdatei nicht gefunden.');
        }
        $n = self::restore($backupZip);
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return $n;
    }

    /* ---------- Hilfen ---------- */

    private static function allowed(string $rel): bool
    {
        if ($rel === '' || str_contains($rel, '..') || $rel[0] === '/' || str_contains($rel, '\\') || str_contains($rel, "\0") || in_array($rel, self::PROTECTED, true)) {
            return false;
        }
        if (in_array($rel, self::ROOT_FILES, true)) {
            return true;
        }
        foreach (self::DIRS as $dir) {
            if (str_starts_with($rel, $dir . '/')) {
                return preg_match('#^[A-Za-z0-9._/@ -]+$#', $rel) === 1;
            }
        }

        return false;
    }

    private static function checkWritable(): void
    {
        foreach (array_merge(['.'], self::DIRS) as $dir) {
            $path = APP_ROOT . '/' . $dir;
            if (is_dir($path) && !is_writable($path)) {
                throw new RuntimeException("Der Ordner $dir/ ist nicht beschreibbar – bitte die Schreibrechte setzen.");
            }
        }
    }

    /** @return resource */
    private static function lock()
    {
        $file = APP_ROOT . '/database/update.lock';
        if (is_file($file) && filemtime($file) < time() - 1800) {
            @unlink($file);
        }
        $h = @fopen($file, 'x');
        if ($h === false) {
            throw new RuntimeException('Es läuft bereits ein Update.');
        }
        fwrite($h, (string) time());

        return $h;
    }

    /** @param resource $h */
    private static function unlock($h): void
    {
        fclose($h);
        @unlink(APP_ROOT . '/database/update.lock');
    }

    /**
     * Entpackt in den Zwischenordner und prüft jede Datei gegen die Liste (Pfad erlaubt, Prüfsumme stimmt, nichts Unaufgeführtes).
     *
     * @param array{files:list<array<string,mixed>>} $manifest
     * @return list<string> relative Pfade
     */
    private static function extract(string $zipFile, string $stage, array $manifest): array
    {
        $expected = [];
        foreach ($manifest['files'] as $f) {
            if (is_array($f) && is_string($f['path'] ?? null) && is_string($f['sha256'] ?? null)) {
                $expected[$f['path']] = $f['sha256'];
            }
        }
        if ($expected === []) {
            throw new RuntimeException('Das Paket enthält keine Dateiliste.');
        }
        $zip = new ZipArchive();
        $zip->open($zipFile, ZipArchive::RDONLY);
        $out = [];
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (str_ends_with($name, '/')) {
                    continue;
                }
                if ($name === 'manifest.json') {
                    $content = (string) $zip->getFromIndex($i);
                } elseif (!self::allowed($name) || !isset($expected[$name])) {
                    throw new RuntimeException("Unzulässige oder unaufgeführte Datei im Paket: $name");
                } else {
                    $content = (string) $zip->getFromIndex($i);
                    if (!hash_equals($expected[$name], hash('sha256', $content))) {
                        throw new RuntimeException("Prüfsumme der Datei $name stimmt nicht.");
                    }
                }
                $target = $stage . '/' . $name;
                if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
                    throw new RuntimeException('Zwischenordner nicht beschreibbar.');
                }
                file_put_contents($target, $content);
                $out[] = $name;
            }
        } finally {
            $zip->close();
        }
        $missing = array_diff(array_keys($expected), $out);
        if ($missing !== []) {
            throw new RuntimeException('Dateien fehlen im Paket: ' . implode(', ', array_slice($missing, 0, 3)));
        }
        if (!in_array('VERSION', $out, true)) {
            throw new RuntimeException('Das Paket enthält keine VERSION-Datei.');
        }

        return $out;
    }

    /** @return list<string> Dateien der bisher installierten Version laut manifest.json */
    private static function installedFiles(): array
    {
        $m = is_file(APP_ROOT . '/manifest.json') ? json_decode((string) @file_get_contents(APP_ROOT . '/manifest.json'), true) : null;
        $out = [];
        foreach (is_array($m) && is_array($m['files'] ?? null) ? $m['files'] : [] as $f) {
            if (is_array($f) && is_string($f['path'] ?? null)) {
                $out[] = $f['path'];
            }
        }

        return $out;
    }

    /** Sichert alle Dateien, die das Update ersetzt, und die bisherigen Dateien der alten Version. @param list<string> $newFiles */
    private static function backupCode(array $newFiles): string
    {
        $dir = rtrim(Env::get('BACKUP_DIR', '') ?: APP_ROOT . '/backups', '/\\');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Der Backup-Ordner ist nicht beschreibbar.');
        }
        $zipPath = $dir . '/code-vor-update-' . Product::version() . '-' . gmdate('Ymd-His') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Code-Sicherung konnte nicht angelegt werden.');
        }
        foreach (array_unique(array_merge($newFiles, self::installedFiles())) as $rel) {
            if (self::allowed($rel) && is_file(APP_ROOT . '/' . $rel)) {
                $zip->addFile(APP_ROOT . '/' . $rel, $rel);
            }
        }
        if (!$zip->close() || !is_file($zipPath)) {
            throw new RuntimeException('Code-Sicherung konnte nicht geschrieben werden.');
        }

        return $zipPath;
    }

    /** @return list<string> */
    private static function zipNames(string $zipPath): array
    {
        $zip = new ZipArchive();
        $names = [];
        if ($zip->open($zipPath, ZipArchive::RDONLY) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[] = (string) $zip->getNameIndex($i);
            }
            $zip->close();
        }

        return $names;
    }

    private static function restore(string $backupZip): int
    {
        $zip = new ZipArchive();
        if ($zip->open($backupZip, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Code-Sicherung nicht lesbar – bitte manuell zurückspielen: ' . $backupZip);
        }
        $n = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!self::allowed($name)) {
                continue;
            }
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                continue;
            }
            $target = APP_ROOT . '/' . $name;
            if (!is_dir(dirname($target))) {
                @mkdir(dirname($target), 0775, true);
            }
            file_put_contents($target, $content);
            $n++;
        }
        $zip->close();

        return $n;
    }

    /** Datei atomar ersetzen: erst neben das Ziel schreiben, dann umbenennen. */
    private static function place(string $from, string $to): void
    {
        if (!is_dir(dirname($to)) && !mkdir(dirname($to), 0775, true) && !is_dir(dirname($to))) {
            throw new RuntimeException('Ordner ' . dirname($to) . ' nicht anlegbar.');
        }
        $tmp = $to . '.new-' . bin2hex(random_bytes(3));
        if (!copy($from, $tmp) || !rename($tmp, $to)) {
            @unlink($tmp);
            throw new RuntimeException('Datei ' . substr($to, strlen(APP_ROOT) + 1) . ' konnte nicht geschrieben werden.');
        }
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') {
                $p = $dir . '/' . $f;
                is_dir($p) && !is_link($p) ? self::removeDir($p) : @unlink($p);
            }
        }
        @rmdir($dir);
    }

    /** @param array<string,mixed> $entry */
    private static function log(array $entry): void
    {
        $file = APP_ROOT . '/database/update-log.json';
        $log = is_file($file) ? (json_decode((string) @file_get_contents($file), true) ?: []) : [];
        $log[] = $entry;
        @file_put_contents($file, json_encode(array_slice($log, -50), JSON_PRETTY_PRINT));
    }

    /** @return list<array<string,mixed>> */
    public static function history(): array
    {
        $file = APP_ROOT . '/database/update-log.json';
        $log = is_file($file) ? json_decode((string) @file_get_contents($file), true) : [];

        return array_reverse(is_array($log) ? $log : []);
    }
}
