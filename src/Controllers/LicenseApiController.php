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
    private static function guard(Request $r, array $extra = []): array
    {
        self::requireHttps($r);
        $data = Validator::validate($r->body(), [
            'key' => ['required' => true, 'min' => 1, 'max' => 64],
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

        return ['key' => $data['key'], 'domain' => $data['domain'], 'nonce' => $data['nonce'], 'version' => $data['version'], 'body' => $data];
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
     * Antwort signiert: die neueste Version, ob die Lizenz Anspruch darauf hat, und ein kurzlebiges Download-Token.
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
        $payload = [
            'v' => 2, 'type' => 'update', 'kid' => LicenseService::keyId(), 'valid' => $reason === null, 'reason' => $reason, 'domain' => $domain,
            'product' => $product, 'issuedAt' => Dates::now(), 'nonce' => mb_substr($q['nonce'], 0, 128), 'latest' => null, 'entitled' => false, 'updatesUntil' => null,
        ];
        if ($reason === null) {
            LicenseService::noteHost($license['id'], $domain ?? '', $q['version']);
            $latest = ReleaseService::latest($product, (string) ($q['body']['channel'] ?? 'stable') ?: 'stable');
            $payload['updatesUntil'] = $license['updatesUntil'];
            if ($latest !== null && ($q['version'] === null || version_compare($latest['version'], $q['version'], '>'))) {
                $payload['latest'] = [
                    'version' => $latest['version'], 'releasedAt' => $latest['releasedAt'], 'notes' => $latest['notes'], 'sha256' => $latest['sha256'],
                    'size' => (int) $latest['size'], 'minPhp' => $latest['minPhp'], 'signature' => $latest['signature'], 'channel' => $latest['channel'],
                ];
                $payload['entitled'] = Entitlements::updatesActive($license, $latest['releasedAt']);
                if ($payload['entitled']) {
                    $payload += ['download' => ReleaseService::token($license['id'], $latest['id'])];
                }
            }
        }

        return self::reply($r, LicenseService::sign($payload));
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
        $license = Db::find('License', $t['l']);
        $release = Db::find('Release', $t['r']);
        if ($license === null || $release === null || !$release['published'] || LicenseService::effectiveStatus($license) !== 'ACTIVE' || !Entitlements::updatesActive($license, $release['releasedAt'])) {
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
