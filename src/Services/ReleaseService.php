<?php

declare(strict_types=1);

namespace App\Services;

use App\Controllers\DocumentsController;
use App\Http\ApiError;
use App\Support\Dates;
use App\Support\Db;
use ZipArchive;

/**
 * Software-Releases für die Update-Funktion: signierte ZIP-Pakete, die nur Lizenzen mit gültigem Update-Anspruch laden können.
 *
 * Ablauf: Das Paket wird mit Lizenz-Tools/build-release.php gebaut und hier hochgeladen. Der Server prüft das Paket,
 * berechnet die SHA-256-Prüfsumme und signiert „Produkt, Version, Prüfsumme“ mit dem geheimen Lizenzschlüssel.
 * Die Software prüft die Signatur mit dem eingebauten öffentlichen Schlüssel, bevor sie irgendetwas installiert –
 * ein manipuliertes Paket oder ein gefälschter Update-Server wird so erkannt.
 */
final class ReleaseService
{
    public const MAX_BYTES = 52428800; // 50 MB
    private const TOKEN_SECONDS = 600;

    public static function dir(): string
    {
        return DocumentsController::uploadDir() . '/releases';
    }

    public static function message(string $product, string $version, string $sha256): string
    {
        return "crm-release|$product|$version|$sha256";
    }

    /**
     * Prüft ein hochgeladenes Paket und legt die Version an.
     *
     * @return array<string,mixed>
     */
    public static function create(string $tmpFile, string $channel, ?string $notes, bool $published, string $access = 'licensed', ?string $minFrom = null): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ApiError(500, 'Die PHP-Erweiterung „zip“ fehlt.');
        }
        $size = (int) filesize($tmpFile);
        if ($size < 100 || $size > self::MAX_BYTES) {
            throw ApiError::badRequest('Das Paket muss zwischen 100 Byte und 50 MB groß sein.');
        }
        if (!in_array($access, ['public', 'licensed'], true)) {
            throw ApiError::badRequest('Zugang muss „public“ oder „licensed“ sein.');
        }
        if ($minFrom !== null && $minFrom !== '' && !self::validVersion($minFrom)) {
            throw ApiError::badRequest('Die Mindestversion ist ungültig (erwartet z. B. 0.1.0).');
        }
        $minFrom = $minFrom === '' ? null : $minFrom;
        $manifest = self::readManifest($tmpFile);
        $product = $manifest['product'];
        $version = $manifest['version'];
        if (Db::value('SELECT 1 FROM "Release" WHERE "product" = ? AND "version" = ?', [$product, $version]) !== null) {
            throw ApiError::conflict("Version $version von „$product“ gibt es schon – bitte eine neue Versionsnummer bauen.");
        }
        $sha = hash_file('sha256', $tmpFile);
        $stored = 'release-' . bin2hex(random_bytes(12)) . '.zip';
        if (!is_dir(self::dir()) && !@mkdir(self::dir(), 0775, true) && !is_dir(self::dir())) {
            throw new ApiError(500, 'Ordner für Releases nicht beschreibbar.');
        }
        $target = self::dir() . '/' . $stored;
        if (!(is_uploaded_file($tmpFile) ? move_uploaded_file($tmpFile, $target) : copy($tmpFile, $target))) {
            throw new ApiError(500, 'Paket konnte nicht gespeichert werden.');
        }
        $id = Db::insert('Release', [
            'product' => $product, 'version' => $version, 'channel' => $channel, 'notes' => $notes, 'fileName' => $stored, 'size' => $size,
            'sha256' => $sha, 'signature' => LicenseService::signRaw(self::message($product, $version, $sha)),
            'minPhp' => $manifest['minPhp'], 'published' => (int) $published, 'releasedAt' => Dates::now(), 'access' => $access, 'minFrom' => $minFrom,
        ]);

        return Db::require('Release', $id, 'Release nicht gefunden');
    }

    /** @return array{product:string,version:string,minPhp:?string,files:list<array<string,mixed>>} */
    public static function readManifest(string $zipPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw ApiError::badRequest('Das ist kein gültiges ZIP-Paket.');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 5000) {
                throw ApiError::badRequest('Das Paket enthält zu viele oder keine Dateien.');
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) ($stat['name'] ?? '');
                if ($name === '' || str_contains($name, '..') || $name[0] === '/' || str_contains($name, '\\') || str_contains($name, "\0")) {
                    throw ApiError::badRequest('Das Paket enthält unzulässige Dateipfade.');
                }
                $total += (int) ($stat['size'] ?? 0);
            }
            if ($total > 300 * 1024 * 1024) {
                throw ApiError::badRequest('Das entpackte Paket ist zu groß.');
            }
            $raw = $zip->getFromName('manifest.json');
        } finally {
            $zip->close();
        }
        $m = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($m) || !preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', (string) ($m['product'] ?? '')) || !self::validVersion((string) ($m['version'] ?? ''))) {
            throw ApiError::badRequest('manifest.json fehlt oder ist ungültig (benötigt: product, version).');
        }
        return ['product' => $m['product'], 'version' => $m['version'], 'minPhp' => isset($m['minPhp']) ? (string) $m['minPhp'] : null, 'files' => is_array($m['files'] ?? null) ? $m['files'] : []];
    }

    public static function validVersion(string $v): bool
    {
        return preg_match('/^\d{1,4}\.\d{1,4}\.\d{1,4}(-[0-9A-Za-z.]{1,20})?$/', $v) === 1;
    }

    /** Neueste veröffentlichte Version eines Produkts im Kanal ('beta' schließt stabile ein). @return array<string,mixed>|null */
    public static function latest(string $product, string $channel): ?array
    {
        return self::visible($product, $channel, true)[0] ?? null;
    }

    /**
     * Veröffentlichte Versionen, neueste zuerst.
     *
     * @return list<array<string,mixed>>
     */
    public static function visible(string $product, string $channel, bool $licensed): array
    {
        $rows = Db::all(
            'SELECT * FROM "Release" WHERE "product" = ? AND "published" = 1' . ($channel === 'beta' ? '' : ' AND "channel" = \'stable\'') . ($licensed ? '' : ' AND "access" = \'public\''),
            [$product],
        );
        usort($rows, static fn ($a, $b) => version_compare($b['version'], $a['version']));

        return $rows;
    }

    /**
     * Update-Plan für eine Installation – egal, wie viele Versionen sie verpasst hat.
     *
     * Jedes Paket ist ein vollständiger Stand (aller Code + alle Datenbank-Migrationen), deshalb kann eine Installation direkt auf die
     * neueste Version springen; die Migrationen holen alles Verpasste nach. Nur wenn eine Version „minFrom“ verlangt (Zwischenschritt
     * bei einem Umbau), wird zuerst die höchste erreichbare Version eingespielt und danach erneut geprüft.
     *
     * @param callable(array<string,mixed>):bool $allowed darf diese Lizenz die Version installieren (Update-Anspruch)?
     * @return array{latest:?array<string,mixed>,target:?array<string,mixed>,changes:list<array<string,mixed>>,blocked:bool}
     */
    public static function plan(string $product, string $channel, ?string $installed, bool $licensed, callable $allowed): array
    {
        $newer = array_values(array_filter(
            self::visible($product, $channel, $licensed),
            static fn ($r) => $installed === null || version_compare($r['version'], $installed, '>'),
        ));
        $target = null;
        foreach ($newer as $r) { // neueste zuerst
            if (!$allowed($r)) {
                continue;
            }
            if ($installed !== null && ($r['minFrom'] ?? null) !== null && version_compare($installed, $r['minFrom'], '<')) {
                continue; // dafür fehlt ein Zwischenschritt; eine ältere, erreichbare Version kommt zuerst
            }
            $target = $r;
            break;
        }
        $latest = $newer[0] ?? null;
        $changes = [];
        foreach (array_reverse($newer) as $r) { // aufsteigend: was ist alles neu seit der installierten Version
            $changes[] = ['version' => $r['version'], 'releasedAt' => $r['releasedAt'], 'channel' => $r['channel'], 'notes' => $r['notes'] === null ? null : mb_substr((string) $r['notes'], 0, 3000)];
        }

        return ['latest' => $latest, 'target' => $target, 'changes' => array_slice($changes, -30), 'blocked' => $latest !== null && $target === null];
    }

    /**
     * Hängt das Vollpaket (Erstinstallation, wie von build-product.php gebaut) an eine Version.
     * Das ZIP darf die Dateien direkt oder in einem einzigen Oberordner enthalten.
     *
     * @return array<string,mixed>
     */
    public static function attachFull(string $id, string $tmpFile): array
    {
        $release = Db::require('Release', $id, 'Release nicht gefunden');
        $size = (int) filesize($tmpFile);
        if ($size < 200 || $size > self::MAX_BYTES) {
            throw ApiError::badRequest('Das Vollpaket muss zwischen 200 Byte und 50 MB groß sein.');
        }
        $zip = new ZipArchive();
        if (!class_exists(ZipArchive::class) || $zip->open($tmpFile, ZipArchive::RDONLY) !== true) {
            throw ApiError::badRequest('Das ist kein gültiges ZIP-Paket.');
        }
        $names = [];
        for ($i = 0; $i < $zip->numFiles && $i < 5000; $i++) {
            $n = (string) $zip->getNameIndex($i);
            if ($n === '' || str_contains($n, '..') || $n[0] === '/' || str_contains($n, '\\')) {
                $zip->close();
                throw ApiError::badRequest('Das Paket enthält unzulässige Dateipfade.');
            }
            $names[] = $n;
        }
        $prefix = null;
        foreach (['', ...array_unique(array_filter(array_map(static fn ($n) => str_contains($n, '/') ? explode('/', $n)[0] . '/' : null, $names)))] as $cand) {
            if (in_array($cand . 'manifest.json', $names, true)) {
                $prefix = $cand;
                break;
            }
        }
        $m = $prefix === null ? null : json_decode((string) $zip->getFromName($prefix . 'manifest.json'), true);
        $zip->close();
        if (!is_array($m) || ($m['product'] ?? null) !== $release['product'] || ($m['version'] ?? null) !== $release['version']) {
            throw ApiError::badRequest('Das Vollpaket gehört nicht zu Version ' . $release['version'] . ' von „' . $release['product'] . '“ (manifest.json prüfen – gleiche VERSION bauen).');
        }
        foreach (['public/install.php', 'product.json'] as $need) {
            if (!in_array($prefix . $need, $names, true)) {
                throw ApiError::badRequest("Im Vollpaket fehlt $need – bitte mit build-product.php bauen.");
            }
        }
        if (!is_dir(self::dir()) && !@mkdir(self::dir(), 0775, true) && !is_dir(self::dir())) {
            throw new ApiError(500, 'Ordner für Releases nicht beschreibbar.');
        }
        $stored = 'full-' . bin2hex(random_bytes(12)) . '.zip';
        if (!(is_uploaded_file($tmpFile) ? move_uploaded_file($tmpFile, self::dir() . '/' . $stored) : copy($tmpFile, self::dir() . '/' . $stored))) {
            throw new ApiError(500, 'Paket konnte nicht gespeichert werden.');
        }
        self::removeFull($release);
        Db::update('Release', $id, ['fullFileName' => $stored, 'fullSize' => $size, 'fullSha256' => hash_file('sha256', self::dir() . '/' . $stored)]);

        return Db::require('Release', $id, 'Release nicht gefunden');
    }

    /** @param array<string,mixed> $release */
    private static function removeFull(array $release): void
    {
        $f = (string) ($release['fullFileName'] ?? '');
        if (preg_match('/^full-[a-f0-9]{24}\.zip$/', $f) && is_file(self::dir() . '/' . $f)) {
            @unlink(self::dir() . '/' . $f);
        }
    }

    /** @param array<string,mixed> $release */
    public static function fullPath(array $release): string
    {
        $f = (string) ($release['fullFileName'] ?? '');
        $path = self::dir() . '/' . $f;
        if (!preg_match('/^full-[a-f0-9]{24}\.zip$/', $f) || !is_file($path)) {
            throw ApiError::notFound('Das Vollpaket fehlt auf dem Server.');
        }

        return $path;
    }

    /**
     * Das Vollpaket, das eine Lizenz herunterladen darf: die neueste stabile Version mit Vollpaket, für die noch Update-Anspruch besteht
     * (gibt es keine stabile, dann die neueste Beta).
     *
     * @param array<string,mixed> $license
     * @return array<string,mixed>|null
     */
    public static function fullFor(array $license): ?array
    {
        $rows = Db::all('SELECT * FROM "Release" WHERE "product" = ? AND "published" = 1 AND "fullFileName" IS NOT NULL', [$license['slug']]);
        usort($rows, static fn ($a, $b) => (($a['channel'] === 'stable') !== ($b['channel'] === 'stable') ? ($a['channel'] === 'stable' ? -1 : 1) : version_compare($b['version'], $a['version'])));
        foreach ($rows as $r) {
            if (Entitlements::updatesActive($license, $r['releasedAt'])) {
                return $r;
            }
        }

        return null;
    }

    /** Kurzlebiges Download-Token, gebunden an Lizenz und Version. */
    public static function token(string $licenseId, string $releaseId): array // $licenseId = '-' für öffentliche Releases
    {
        $exp = time() + self::TOKEN_SECONDS;
        $body = rtrim(strtr(base64_encode(json_encode(['l' => $licenseId, 'r' => $releaseId, 'e' => $exp])), '+/', '-_'), '=');
        return ['token' => $body . '.' . LicenseService::mac($body), 'expiresAt' => gmdate(Dates::FORMAT, $exp)];
    }

    /** @return array{l:string,r:string}|null */
    public static function checkToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || !hash_equals(LicenseService::mac($parts[0]), $parts[1])) {
            return null;
        }
        $d = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true);
        return is_array($d) && ($d['e'] ?? 0) >= time() && is_string($d['l'] ?? null) && is_string($d['r'] ?? null) ? ['l' => $d['l'], 'r' => $d['r']] : null;
    }

    /** @param array<string,mixed> $release */
    public static function path(array $release): string
    {
        if (!preg_match('/^release-[a-f0-9]{24}\.zip$/', $release['fileName'])) {
            throw ApiError::notFound('Paket nicht gefunden');
        }
        $path = self::dir() . '/' . $release['fileName'];
        if (!is_file($path)) {
            throw ApiError::notFound('Die Paketdatei fehlt auf dem Server.');
        }
        return $path;
    }

    public static function delete(string $id): void
    {
        $r = Db::require('Release', $id, 'Release nicht gefunden');
        Db::delete('Release', $id);
        self::removeFull($r);
        $path = self::dir() . '/' . $r['fileName'];
        if (preg_match('/^release-[a-f0-9]{24}\.zip$/', $r['fileName']) && is_file($path)) {
            @unlink($path);
        }
    }
}
