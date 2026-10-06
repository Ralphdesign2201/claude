<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Services\ReleaseService;
use App\Services\UpdateSigner;
use App\Support\Dates;
use App\Support\Db;
use App\Support\Env;
use App\Support\RateLimit;
use App\Support\Validator;

/** Öffentliche Schnittstelle des Update-Servers: Installationen fragen nach neuen Versionen und laden signierte Pakete. */
final class UpdateApiController
{
    private const WINDOW = 900;

    /**
     * POST /api/updates/check  { nonce, ts, version, product, channel }
     * Antwort signiert: neueste Version, der nächste Installationsschritt (target; wer Versionen verpasst hat, springt direkt zur
     * höchsten erreichbaren), alle Änderungen seit der installierten Version und ein kurzlebiges Download-Token.
     */
    public static function check(Request $r): Response
    {
        self::requireHttps($r);
        $d = Validator::validate($r->body(), [
            'nonce' => ['required' => true, 'min' => 8, 'max' => 128], 'ts' => ['type' => 'int'], 'version' => ['max' => 30, 'emptyOk' => true],
            'product' => ['required' => true, 'min' => 1, 'max' => 40], 'channel' => ['enum' => ['stable', 'beta'], 'emptyOk' => true],
        ]);
        if (RateLimit::hit('upd:' . $r->ip(), self::WINDOW) > 600) {
            throw new ApiError(429, 'Zu viele Anfragen');
        }
        self::replayGuard($d);
        $plan = ReleaseService::plan((string) $d['product'], (string) ($d['channel'] ?? 'stable') ?: 'stable', ($d['version'] ?? '') === '' ? null : (string) $d['version']);
        $info = static fn (array $rel): array => [
            'version' => $rel['version'], 'releasedAt' => $rel['releasedAt'], 'notes' => $rel['notes'], 'sha256' => $rel['sha256'],
            'size' => (int) $rel['size'], 'minPhp' => $rel['minPhp'], 'signature' => $rel['signature'], 'channel' => $rel['channel'],
        ];
        $payload = [
            'v' => 3, 'type' => 'update', 'kid' => UpdateSigner::keyId(), 'issuedAt' => Dates::now(), 'nonce' => mb_substr((string) $d['nonce'], 0, 128),
            'product' => $d['product'], 'latest' => $plan['latest'] ? $info($plan['latest']) : null, 'target' => $plan['target'] ? $info($plan['target']) : null, 'changes' => $plan['changes'],
        ];
        if ($plan['target'] !== null) {
            $payload['download'] = ReleaseService::token($plan['target']['id']);
        }

        return self::reply($r, Response::json(UpdateSigner::sign($payload)));
    }

    /** POST /api/updates/download { token } – liefert das Paket, solange das Token gültig ist. */
    public static function download(Request $r): Response
    {
        self::requireHttps($r);
        if (RateLimit::hit('upddl:' . $r->ip(), self::WINDOW) > 60) {
            throw new ApiError(429, 'Zu viele Downloads von dieser Adresse – bitte später erneut versuchen.');
        }
        $d = Validator::validate($r->body(), ['token' => ['required' => true, 'min' => 20, 'max' => 400]]);
        $id = ReleaseService::checkToken($d['token']) ?? throw ApiError::forbidden('Das Download-Token ist ungültig oder abgelaufen.');
        $release = Db::find('Release', $id);
        if ($release === null || !$release['published']) {
            throw ApiError::forbidden('Dieser Download ist nicht (mehr) verfügbar.');
        }
        Db::run('UPDATE "Release" SET "downloads" = "downloads" + 1 WHERE "id" = ?', [$release['id']]);

        return self::reply($r, Response::file(ReleaseService::path($release), [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $release['product'] . '-' . $release['version']) . '.zip"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store', 'X-Release-Sha256' => $release['sha256'],
        ]));
    }

    /** Öffentlicher Schlüssel zum Einbau in die Installationen (Base64, Ed25519). */
    public static function publicKey(Request $r): Response
    {
        return Response::json(['algorithm' => 'ed25519', 'publicKey' => UpdateSigner::publicKey(), 'kid' => UpdateSigner::keyId()]);
    }

    /** Zeitstempel (±5 Min.) und einmaliger Zufallswert: eine abgefangene Anfrage lässt sich nicht noch einmal abspielen. */
    private static function replayGuard(array $d): void
    {
        if (!isset($d['ts'])) {
            throw ApiError::badRequest('Zeitstempel (ts) fehlt – bitte die aktuelle Version der Software verwenden.');
        }
        if (abs(time() - (int) $d['ts']) > 300) {
            throw ApiError::badRequest('Die Uhrzeit weicht um mehr als 5 Minuten ab – bitte die Serverzeit prüfen.');
        }
        try {
            Db::insert('UpdateNonce', ['id' => $d['nonce'], 'createdAt' => time()]);
        } catch (\PDOException $e) {
            if (Db::isUniqueViolation($e)) {
                throw new ApiError(409, 'Diese Anfrage wurde schon verwendet.');
            }
            throw $e;
        }
        if (random_int(1, 100) === 1) {
            Db::run('DELETE FROM "UpdateNonce" WHERE "createdAt" < ?', [time() - 900]);
        }
    }

    private static function requireHttps(Request $r): void
    {
        $default = str_starts_with(strtolower(Env::get('APP_URL', '') ?? ''), 'https://');
        if (!Env::bool('UPDATE_REQUIRE_HTTPS', $default)) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string) $r->header('x-forwarded-proto')) === 'https';
        if (!$https) {
            throw ApiError::badRequest('Der Update-Server ist nur über HTTPS erreichbar.');
        }
    }

    private static function reply(Request $r, Response $res): Response
    {
        $res = $res->withHeader('Cache-Control', 'no-store');
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string) $r->header('x-forwarded-proto')) === 'https';

        return $https ? $res->withHeader('Strict-Transport-Security', 'max-age=31536000') : $res;
    }
}
