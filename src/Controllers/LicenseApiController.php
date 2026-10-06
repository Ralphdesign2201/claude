<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Services\LicenseService;
use App\Support\RateLimit;
use App\Support\Validator;

/** Öffentliche Schnittstelle, die die verkaufte Software aufruft. */
final class LicenseApiController
{
    private const WINDOW = 900;

    /**
     * POST /api/license/verify  { key, domain, nonce }
     * Antwort: { payload, signature } – payload ist Base64URL-JSON, signature die Ed25519-Signatur darüber.
     */
    public static function verify(Request $r): Response
    {
        $data = Validator::validate($r->body(), [
            'key' => ['required' => true, 'min' => 1, 'max' => 64],
            'domain' => ['required' => true, 'min' => 1, 'max' => 300],
            'nonce' => ['required' => true, 'min' => 8, 'max' => 128],
        ]);

        $ip = $r->ip();
        if (RateLimit::hit('lic:' . $ip, self::WINDOW) > 1200) {
            throw new ApiError(429, 'Zu viele Anfragen');
        }
        if (RateLimit::count('licfail:' . $ip, self::WINDOW) >= 30) {
            throw new ApiError(429, 'Zu viele ungültige Lizenzprüfungen von dieser Adresse – bitte später erneut versuchen');
        }

        $signed = LicenseService::verify($data['key'], $data['domain'], $data['nonce']);
        $payload = json_decode((string) base64_decode(strtr($signed['payload'], '-_', '+/')), true);
        if (($payload['reason'] ?? null) === 'unknown') {
            RateLimit::hit('licfail:' . $ip, self::WINDOW); // Durchprobieren von Schlüsseln ausbremsen
        }

        return Response::json($signed)->withHeader('Cache-Control', 'no-store');
    }

    /** Öffentlicher Schlüssel zum Einbau in die Software (Base64, Ed25519). */
    public static function publicKey(Request $r): Response
    {
        return Response::json(['algorithm' => 'ed25519', 'publicKey' => LicenseService::publicKey()]);
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
