<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Dates;
use App\Support\Env;
use App\Support\Product;
use LicenseClient;

require_once APP_ROOT . '/examples/license-client/LicenseClient.php';

/**
 * Lizenzprüfung der Software selbst (nur in der lizenzierten Auslieferung, siehe product.json).
 *
 * Die Prüfung läuft gegen den Lizenzserver des Herstellers; die Antwort ist signiert und wird mit dem eingebauten öffentlichen
 * Schlüssel geprüft. Ergebnisse werden zwischengespeichert, kurze Ausfälle des Servers überbrückt eine Kulanzfrist.
 * Bei ungültiger Lizenz arbeitet die Software im „Nur-Lesen-Modus“: Daten bleiben les- und exportierbar, Änderungen sind gesperrt.
 *
 * Ehrlich: Wer den PHP-Code ändert, kann diese Prüfung entfernen. Wirklichen Schutz bieten die Teile, die der Server liefert –
 * signierte Updates, Support und die Berechtigungen, die aus der signierten Antwort kommen.
 */
final class ProductLicense
{
    private const BACKOFF_SECONDS = 600;

    /** @var array<string,mixed>|null */
    private static ?array $memo = null;

    public static function cacheFile(): string
    {
        return APP_ROOT . '/database/product-license.cache';
    }

    private static function stateFile(): string
    {
        return APP_ROOT . '/database/product-license.state.json';
    }

    public static function key(): string
    {
        return trim(Env::get('PRODUCT_LICENSE_KEY', '') ?? '');
    }

    /** Domain, für die geprüft wird: die in APP_URL, sonst die der Anfrage. */
    public static function domain(): string
    {
        $host = parse_url(Env::get('APP_URL', '') ?? '', PHP_URL_HOST);
        $host = is_string($host) && $host !== '' ? $host : (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return strtolower(preg_replace('/:\d+$/', '', $host) ?? $host);
    }

    public static function client(?string $key = null, ?string $cacheFile = null): LicenseClient
    {
        return new LicenseClient(Product::server(), Product::publicKeys(), $key ?? self::key(), $cacheFile ?? self::cacheFile(), [
            'domain' => self::domain(), 'timeout' => 4, 'version' => Product::version(),
        ]);
    }

    /**
     * Aktueller Zustand der Lizenz.
     *
     * @return array<string,mixed>
     */
    public static function state(bool $force = false): array
    {
        if (self::$memo !== null && !$force) {
            return self::$memo;
        }
        if (!Product::enforced()) {
            return self::$memo = [
                'enforced' => false, 'valid' => true, 'reason' => null, 'mode' => 'none', 'plan' => null, 'features' => array_keys(Entitlements::FEATURES),
                'supportUntil' => null, 'updatesUntil' => null, 'expiresAt' => null, 'keySet' => false, 'checkedAt' => null, 'product' => null,
            ];
        }
        $key = self::key();
        $base = ['enforced' => true, 'keySet' => $key !== '', 'checkedAt' => null, 'plan' => null, 'features' => [], 'supportUntil' => null, 'updatesUntil' => null, 'expiresAt' => null, 'product' => null];
        if ($key === '' || Product::server() === '' || Product::publicKeys() === []) {
            return self::$memo = $base + ['valid' => false, 'reason' => $key === '' ? 'missing' : 'config', 'mode' => 'none'];
        }

        $client = self::client();
        $mode = $client->peek();
        $valid = $mode !== 'none';
        $stateFile = self::stateFile();
        $meta = is_file($stateFile) ? (json_decode((string) @file_get_contents($stateFile), true) ?: []) : [];

        if ($force || $mode !== 'fresh') {
            // Nicht bei jeder Anfrage nachfragen, wenn der Server gerade nicht antwortet
            $recentFail = !$force && isset($meta['failedAt']) && time() - (int) $meta['failedAt'] < self::BACKOFF_SECONDS && ($meta['key'] ?? '') === hash('sha256', $key);
            if (!$recentFail) {
                $valid = $client->check(true);
                if ($client->source === 'network') {
                    $mode = 'fresh';
                    $meta = ['key' => hash('sha256', $key)];
                } else {
                    // Server nicht erreichbar (oder Antwort unbrauchbar): Kulanzfrist aus dem Zwischenspeicher, sonst offline
                    $meta = ['failedAt' => time(), 'key' => hash('sha256', $key), 'error' => $client->error];
                    $mode = $valid ? 'grace' : 'offline';
                }
                @file_put_contents($stateFile, json_encode($meta));
            } else {
                $valid = $mode !== 'none';
                $mode = $valid ? $mode : 'offline';
            }
        }
        $reason = $valid ? null : ($client->reason ?? ($mode === 'offline' || $mode === 'none' ? 'offline' : 'unknown'));
        if ($valid && $client->slug !== null && $client->slug !== Product::slug()) {
            $valid = false;
            $reason = 'product'; // Lizenz gehört zu einem anderen Produkt
        }

        return self::$memo = [
            'enforced' => true, 'keySet' => true, 'valid' => $valid, 'reason' => $reason, 'mode' => $mode, 'plan' => $client->plan,
            'features' => $valid ? $client->features : [], 'supportUntil' => $client->supportUntil, 'updatesUntil' => $client->updatesUntil,
            'expiresAt' => $client->expiresAt, 'checkedAt' => $client->issuedAt, 'product' => $client->product, 'error' => $meta['error'] ?? null,
        ];
    }

    public static function allowed(): bool
    {
        return (bool) self::state()['valid'];
    }

    public static function feature(string $name): bool
    {
        $s = self::state();

        return !$s['enforced'] || ($s['valid'] && in_array($name, $s['features'], true));
    }

    public static function message(?string $reason): string
    {
        return match ($reason) {
            'missing' => 'Es ist noch kein Lizenzschlüssel eingetragen.',
            'config' => 'Die Lizenzprüfung ist nicht eingerichtet (product.json unvollständig).',
            'unknown' => 'Der Lizenzschlüssel ist unbekannt.',
            'domain' => 'Die Lizenz gilt nicht für diese Domain (' . self::domain() . ').',
            'revoked' => 'Die Lizenz wurde widerrufen.',
            'suspended' => 'Die Lizenz ist vorübergehend gesperrt.',
            'pending' => 'Die Lizenz ist noch nicht freigeschaltet (Zahlung offen).',
            'expired' => 'Die Lizenz ist abgelaufen.',
            'product' => 'Der Schlüssel gehört zu einem anderen Produkt.',
            'offline' => 'Der Lizenzserver ist nicht erreichbar und die Kulanzfrist ist abgelaufen.',
            default => 'Die Lizenz konnte nicht geprüft werden.',
        };
    }

    /**
     * Neuen Schlüssel prüfen und – bei Erfolg – speichern.
     *
     * @return array{ok:bool,reason:?string,message:string}
     */
    public static function setKey(string $key): array
    {
        $key = strtoupper(trim($key));
        $tmp = APP_ROOT . '/database/.product-license-test-' . bin2hex(random_bytes(4)) . '.cache';
        try {
            $client = self::client($key, $tmp);
            $ok = $client->check();
            $slugOk = $client->slug === null || $client->slug === Product::slug();
            if (!$ok || !$slugOk) {
                $reason = !$ok ? ($client->reason ?? 'unknown') : 'product';
                return ['ok' => false, 'reason' => $reason, 'message' => self::message($reason) . ($reason === 'offline' && $client->error ? ' (' . $client->error . ')' : '')];
            }
            @rename($tmp, self::cacheFile());
            Env::saveSettings(['PRODUCT_LICENSE_KEY' => $key]);
            @unlink(self::stateFile());
            self::$memo = null;

            return ['ok' => true, 'reason' => null, 'message' => 'Lizenz geprüft und gespeichert.'];
        } finally {
            @unlink($tmp);
        }
    }

    /** Für die Oberfläche: Zustand ohne interne Felder. @return array<string,mixed> */
    public static function publicState(): array
    {
        $s = self::state();
        $s['message'] = $s['valid'] ? ($s['mode'] === 'grace' ? 'Der Lizenzserver ist gerade nicht erreichbar – die Software läuft während der Kulanzfrist weiter.' : null) : self::message($s['reason']);
        $s['supportActive'] = $s['supportUntil'] === null ? $s['valid'] : ($s['supportUntil'] > Dates::now());
        $s['updatesActive'] = $s['updatesUntil'] === null ? $s['valid'] : ($s['updatesUntil'] > Dates::now());
        $s['version'] = Product::version();
        $s['slug'] = Product::slug();
        $s['name'] = Product::name();
        $s['server'] = Product::server();

        return $s;
    }

    public static function reset(): void
    {
        self::$memo = null;
    }
}
