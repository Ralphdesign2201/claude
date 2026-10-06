<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Services\Entitlements;
use App\Services\LicenseService;
use App\Services\ReleaseService;
use App\Support\Dates;
use App\Support\Db;
use App\Support\Env;
use App\Support\RateLimit;
use App\Support\Validator;

/** Öffentliche Schnittstelle, die die verkaufte Software aufruft. */
final class LicenseApiController
{
    private const WINDOW = 900;

    /**
     * Gemeinsame Prüfungen aller Software-Anfragen: HTTPS, Pflichtfelder, Zeitstempel, Replay-Schutz, Bremsen.
     *
     * @return array{key:string,domain:string,nonce:string,version:?string,body:array<string,mixed>}
     */
    private static function guard(Request $r, array $extra = [], bool $needKey = true): array
    {
        self::requireHttps($r);
        $data = Validator::validate($r->body(), [
            'key' => ['required' => $needKey, 'min' => 1, 'max' => 64, 'emptyOk' => !$needKey],
            'domain' => ['required' => true, 'min' => 1, 'max' => 300],
            'nonce' => ['required' => true, 'min' => 8, 'max' => 128],
            'ts' => ['type' => 'int'],
            'version' => ['max' => 30, 'emptyOk' => true],
        ] + $extra);

        $ip = $r->ip();
        if (RateLimit::hit('lic:' . $ip, self::WINDOW) > 1200) {
            throw new ApiError(429, 'Zu viele Anfragen');
        }
        if (RateLimit::count('licfail:' . $ip, self::WINDOW) >= 30) {
            throw new ApiError(429, 'Zu viele ungültige Lizenzprüfungen von dieser Adresse – bitte später erneut versuchen');
        }

        // Zeitstempel und einmalige Zufallswerte: eine abgefangene Anfrage lässt sich nicht noch einmal abspielen
        if (!isset($data['ts'])) {
            if (Env::bool('LICENSE_REQUIRE_TS', true)) {
                throw ApiError::badRequest('Zeitstempel (ts) fehlt – bitte die aktuelle Prüfklasse verwenden.');
            }
        } else {
            if (abs(time() - (int) $data['ts']) > 300) {
                throw ApiError::badRequest('Die Uhrzeit weicht um mehr als 5 Minuten ab – bitte die Serverzeit prüfen.');
            }
            try {
                Db::insert('LicenseNonce', ['id' => $data['nonce'], 'createdAt' => time()]);
            } catch (\PDOException $e) {
                if (Db::isUniqueViolation($e)) {
                    throw new ApiError(409, 'Diese Anfrage wurde schon verwendet.');
                }
                throw $e;
            }
            if (random_int(1, 100) === 1) {
                Db::run('DELETE FROM "LicenseNonce" WHERE "createdAt" < ?', [time() - 900]);
            }
        }
        $data['version'] = ($data['version'] ?? '') === '' ? null : $data['version'];

        return ['key' => (string) ($data['key'] ?? ''), 'domain' => $data['domain'], 'nonce' => $data['nonce'], 'version' => $data['version'], 'body' => $data];
    }

    private static function requireHttps(Request $r): void
    {
        $default = str_starts_with(strtolower(Env::get('APP_URL', '') ?? ''), 'https://');
        if (!Env::bool('LICENSE_REQUIRE_HTTPS', $default)) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string) $r->header('x-forwarded-proto')) === 'https';
        if (!$https) {
            throw ApiError::badRequest('Die Lizenzprüfung ist nur über HTTPS erlaubt.');
        }
    }

    /** @param array<string,mixed> $data */
    private static function reply(Request $r, array $data): Response
    {
        $res = Response::json($data)->withHeader('Cache-Control', 'no-store');
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string) $r->header('x-forwarded-proto')) === 'https';
        return $https ? $res->withHeader('Strict-Transport-Security', 'max-age=31536000') : $res;
    }

    /**
     * POST /api/license/verify  { key, domain, nonce, ts, version }
     * Antwort: { payload, signature } – payload ist Base64URL-JSON, signature die Ed25519-Signatur darüber.
     */
    public static function verify(Request $r): Response
    {
        $q = self::guard($r);
        $signed = LicenseService::verify($q['key'], $q['domain'], $q['nonce'], $q['version']);
        $payload = json_decode((string) base64_decode(strtr($signed['payload'], '-_', '+/')), true);
        if (($payload['reason'] ?? null) === 'unknown') {
            RateLimit::hit('licfail:' . $r->ip(), self::WINDOW); // Durchprobieren von Schlüsseln ausbremsen
        }

        return self::reply($r, $signed);
    }

    /**
     * POST /api/license/update-check  { key, domain, nonce, ts, version, product, channel }
     * Antwort signiert: neueste Version, der nächste Installationsschritt (target; bei verpassten Versionen direkt der größte Sprung),
     * alle Änderungen seit der installierten Version und ein kurzlebiges Download-Token.
     */
    public static function updateCheck(Request $r): Response
    {
        $q = self::guard($r, ['product' => ['required' => true, 'min' => 1, 'max' => 40], 'channel' => ['enum' => ['stable', 'beta'], 'emptyOk' => true]]);
        $product = (string) $q['body']['product'];
        ['license' => $license, 'reason' => $reason, 'domain' => $domain] = LicenseService::lookup($q['key'], $q['domain']);
        if ($license !== null) {
            LicenseService::throttle($license);
        }
        if ($reason === null && $license['slug'] !== $product) {
            $reason = 'product'; // Lizenz gehört zu einem anderen Produkt
        }
        if ($reason === 'unknown') {
            RateLimit::hit('licfail:' . $r->ip(), self::WINDOW);
        }
        $payload = self::updateBase('update', $reason === null, $reason, $domain, $product, $q['nonce']);
        if ($reason === null) {
            LicenseService::noteHost($license['id'], $domain ?? '', $q['version']);
            $payload['updatesUntil'] = $license['updatesUntil'];
            $plan = ReleaseService::plan(
                $product, (string) ($q['body']['channel'] ?? 'stable') ?: 'stable', $q['version'], true,
                static fn ($rel) => $rel['access'] === 'public' || Entitlements::updatesActive($license, $rel['releasedAt']),
            );
            $payload = self::withPlan($payload, $plan, $license['id']);
        }

        return self::reply($r, LicenseService::sign($payload));
    }

    /**
     * POST /api/license/update-public  { domain, nonce, ts, version, product, channel }
     * Wie update-check, aber ohne Lizenz: liefert nur Releases, die als „öffentlich“ markiert sind (z. B. die Beta-Version).
     */
    public static function updatePublic(Request $r): Response
    {
        $q = self::guard($r, ['product' => ['required' => true, 'min' => 1, 'max' => 40], 'channel' => ['enum' => ['stable', 'beta'], 'emptyOk' => true]], false);
        $product = (string) $q['body']['product'];
        $domain = LicenseService::normalizeDomain($q['domain']) ?? mb_substr(strtolower($q['domain']), 0, 253);
        $payload = self::updateBase('update', true, null, $domain, $product, $q['nonce']);
        $plan = ReleaseService::plan($product, (string) ($q['body']['channel'] ?? 'stable') ?: 'stable', $q['version'], false, static fn () => true);

        return self::reply($r, LicenseService::sign(self::withPlan($payload, $plan, '-')));
    }

    /** @return array<string,mixed> */
    private static function updateBase(string $type, bool $valid, ?string $reason, ?string $domain, string $product, string $nonce): array
    {
        return [
            'v' => 2, 'type' => $type, 'kid' => LicenseService::keyId(), 'valid' => $valid, 'reason' => $reason, 'domain' => $domain,
            'product' => $product, 'issuedAt' => Dates::now(), 'nonce' => mb_substr($nonce, 0, 128), 'latest' => null, 'target' => null, 'changes' => [], 'entitled' => false, 'updatesUntil' => null,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array{latest:?array<string,mixed>,target:?array<string,mixed>,changes:list<array<string,mixed>>,blocked:bool} $plan
     * @return array<string,mixed>
     */
    private static function withPlan(array $payload, array $plan, string $licenseId): array
    {
        $info = static fn (array $rel): array => [
            'version' => $rel['version'], 'releasedAt' => $rel['releasedAt'], 'notes' => $rel['notes'], 'sha256' => $rel['sha256'],
            'size' => (int) $rel['size'], 'minPhp' => $rel['minPhp'], 'signature' => $rel['signature'], 'channel' => $rel['channel'], 'access' => $rel['access'],
        ];
        $payload['changes'] = $plan['changes'];
        if ($plan['latest'] !== null) {
            $payload['latest'] = $info($plan['latest']);
        }
        if ($plan['target'] !== null) {
            $payload['target'] = $info($plan['target']);
            $payload['entitled'] = true;
            $payload['download'] = ReleaseService::token($licenseId, $plan['target']['id']);
        }

        return $payload;
    }

    /** POST /api/license/download { token } – liefert das Paket, solange Token, Lizenz und Update-Anspruch gültig sind. */
    public static function download(Request $r): Response
    {
        self::requireHttps($r);
        if (RateLimit::hit('licdl:' . $r->ip(), self::WINDOW) > 60) {
            throw new ApiError(429, 'Zu viele Downloads von dieser Adresse – bitte später erneut versuchen.');
        }
        $d = Validator::validate($r->body(), ['token' => ['required' => true, 'min' => 20, 'max' => 400]]);
        $t = ReleaseService::checkToken($d['token']) ?? throw ApiError::forbidden('Das Download-Token ist ungültig oder abgelaufen.');
        $release = Db::find('Release', $t['r']);
        if ($release !== null && $release['published'] && $release['access'] === 'public') {
            $allowed = true; // öffentliche Version: kein Lizenzschlüssel nötig
        } else {
            $license = $t['l'] === '-' ? null : Db::find('License', $t['l']);
            $allowed = $license !== null && $release !== null && $release['published'] && LicenseService::effectiveStatus($license) === 'ACTIVE' && Entitlements::updatesActive($license, $release['releasedAt']);
        }
        if (!$allowed || $release === null) {
            throw ApiError::forbidden('Für diese Lizenz ist dieser Download nicht (mehr) erlaubt.');
        }
        Db::run('UPDATE "Release" SET "downloads" = "downloads" + 1 WHERE "id" = ?', [$release['id']]);

        return Response::file(ReleaseService::path($release), [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $release['product'] . '-' . $release['version']) . '.zip"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store', 'X-Release-Sha256' => $release['sha256'],
        ]);
    }

    /** Öffentlicher Schlüssel zum Einbau in die Software (Base64, Ed25519). */
    public static function publicKey(Request $r): Response
    {
        return Response::json(['algorithm' => 'ed25519', 'publicKey' => LicenseService::publicKey(), 'kid' => LicenseService::keyId()]);
    }

    /** Die fertige Prüfklasse zum Einbinden in die verkaufte Software. */
    public static function client(Request $r): Response
    {
        return Response::file(APP_ROOT . '/examples/license-client/LicenseClient.php', [
            'Content-Type' => 'text/x-php; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="LicenseClient.php"',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
