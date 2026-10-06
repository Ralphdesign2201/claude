<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\ApiError;
use App\Support\Env;
use App\Support\Product;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * Baut Update-Paket und Vollpaket (Erstinstallation) direkt aus den Dateien dieses Servers – ohne Kommandozeile.
 * Server-Adresse und öffentlicher Schlüssel kommen aus dieser Installation.
 */
final class PackageBuilder
{
    private const DIRS = ['src', 'public', 'bin', 'examples', 'database/migrations'];
    private const ROOT_FILES = ['VERSION', '.htaccess', 'README.md', 'composer.json', '.env.example'];

    /** @return list<string> */
    public static function files(bool $forUpdate): array
    {
        $out = [];
        foreach (self::DIRS as $dir) {
            $base = APP_ROOT . '/' . $dir;
            if (!is_dir($base)) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $f) {
                if (!$f->isFile() || $f->isLink()) {
                    continue;
                }
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(APP_ROOT) + 1));
                if (!preg_match('#(^|/)(\.DS_Store|Thumbs\.db)$#', $rel) && !str_ends_with($rel, '.swp')) {
                    $out[] = $rel;
                }
            }
        }
        foreach (self::ROOT_FILES as $f) {
            if (is_file(APP_ROOT . '/' . $f)) {
                $out[] = $f;
            }
        }
        if ($forUpdate) {
            $out = array_values(array_diff($out, ['public/install.php']));
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /** @return array{version:string,product:string,serverUrl:string} */
    public static function info(): array
    {
        return ['version' => Product::version(), 'product' => 'crm', 'serverUrl' => self::serverUrl()];
    }

    public static function serverUrl(): string
    {
        $u = rtrim(trim(Env::get('APP_URL', '') ?? ''), '/');

        return preg_match('#^https://[^\s/]+#', $u) ? $u : '';
    }

    /** @param list<string> $files @return array<string,mixed> */
    private static function manifest(string $version, array $files): array
    {
        $list = [];
        foreach ($files as $rel) {
            $list[] = ['path' => $rel, 'sha256' => hash_file('sha256', APP_ROOT . '/' . $rel)];
        }

        return ['product' => 'crm', 'version' => $version, 'builtAt' => gmdate('Y-m-d\TH:i:s\Z'), 'minPhp' => '8.1', 'files' => $list];
    }

    private static function prepare(): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ApiError(500, 'Die PHP-Erweiterung „zip“ fehlt.');
        }
        $version = Product::version();
        if (!ReleaseService::validVersion($version)) {
            throw new ApiError(500, 'Die Datei VERSION enthält keine gültige Versionsnummer.');
        }

        return $version;
    }

    /** Update-Paket (ZIP) in eine temporäre Datei schreiben. */
    public static function buildUpdate(): string
    {
        $version = self::prepare();
        $files = self::files(true);
        $tmp = tempnam(sys_get_temp_dir(), 'crm-upd-') ?: throw new ApiError(500, 'Temporärer Ordner nicht beschreibbar.');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE);
        foreach ($files as $rel) {
            $zip->addFile(APP_ROOT . '/' . $rel, $rel);
        }
        $zip->addFromString('manifest.json', json_encode(self::manifest($version, $files), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();

        return $tmp;
    }

    /** Installationspaket (mit install.php und product.json für diesen Update-Server) in eine temporäre Datei schreiben. */
    public static function buildInstall(): string
    {
        $version = self::prepare();
        $server = self::serverUrl();
        if ($server === '') {
            throw ApiError::badRequest('Die öffentliche Adresse (https://…) ist nicht eingetragen: Einstellungen → Kundenportal → „Öffentliche Adresse“. Sie wird als Update-Server in das Paket geschrieben.');
        }
        $files = self::files(false);
        $update = self::files(true);
        $tmp = tempnam(sys_get_temp_dir(), 'crm-full-') ?: throw new ApiError(500, 'Temporärer Ordner nicht beschreibbar.');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE);
        $p = 'crm/';
        foreach (array_merge($files, ['database/.htaccess']) as $rel) {
            $zip->addFile(APP_ROOT . '/' . $rel, $p . $rel);
        }
        foreach (['uploads', 'backups'] as $d) {
            $zip->addFile(APP_ROOT . '/database/.htaccess', "$p$d/.htaccess");
        }
        $zip->addFromString($p . 'manifest.json', json_encode(self::manifest($version, $update), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->addFromString($p . 'product.json', json_encode([
            'product' => 'crm', 'name' => 'Webdesigner CRM', 'server' => $server, 'publicKeys' => [UpdateSigner::publicKey()], 'builtAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $zip->addFromString($p . 'INSTALL.txt', "Webdesigner CRM $version – Installation\n\n1. Den Inhalt des Ordners „crm“ auf deinen Webspace hochladen (am besten in eine eigene Subdomain mit https).\n2. https://deine-domain/install.php im Browser öffnen und den Anweisungen folgen. \n3. Danach install.php löschen.\n\nUpdates: im Programm unter Einstellungen → Version & Updates.\nRechtstexte (Impressum, Datenschutz, AGB): im Admin unter System → Rechtstexte.\n");
        $zip->close();

        return $tmp;
    }

    /**
     * Erstellt aus diesem Server das Update-Paket der Version laut VERSION-Datei und legt es (signiert) als Release an.
     *
     * @return array<string,mixed>
     */
    public static function publish(string $channel, ?string $notes, bool $published, ?string $minFrom): array
    {
        self::prepare();
        $upd = self::buildUpdate();
        try {
            return ReleaseService::create($upd, $channel, $notes, $published, $minFrom);
        } finally {
            @unlink($upd);
        }
    }
}
