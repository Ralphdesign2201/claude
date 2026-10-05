<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\ApiError;
use App\Http\Request;
use App\Support\Dates;
use App\Support\Db;
use App\Support\Env;
use App\Support\RateLimit;
use DateTimeImmutable;

/**
 * Zugang für Kunden ohne Passwort: ein langer, zufälliger Schlüssel im Link (/portal#<schlüssel>).
 * In der Datenbank liegt nur der SHA-256-Hash; der Link wird einmalig beim Erstellen angezeigt.
 */
final class PortalService
{
    private const FAIL_WINDOW = 900;
    private const FAIL_LIMIT = 20;

    /** @return array{token:string,link:string,expiresAt:?string} */
    public static function issue(string $clientId, ?string $userId): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $days = Env::int('PORTAL_TOKEN_DAYS', 365);
        $expires = $days > 0 ? (new DateTimeImmutable('now'))->modify("+$days days")->format(Dates::FORMAT) : null;

        Db::transaction(static function () use ($clientId, $token, $expires, $userId) {
            Db::run('UPDATE "PortalToken" SET "revokedAt" = ? WHERE "clientId" = ? AND "revokedAt" IS NULL', [Dates::now(), $clientId]);
            Db::insert('PortalToken', [
                'clientId' => $clientId,
                'tokenHash' => hash('sha256', $token),
                'expiresAt' => $expires,
                'createdBy' => $userId,
            ]);
        });

        return ['token' => $token, 'link' => self::baseUrl() . '/portal#' . $token, 'expiresAt' => $expires];
    }

    public static function revoke(string $clientId): void
    {
        Db::run('UPDATE "PortalToken" SET "revokedAt" = ? WHERE "clientId" = ? AND "revokedAt" IS NULL', [Dates::now(), $clientId]);
    }

    /** @return array{active:bool,createdAt:?string,expiresAt:?string,lastUsedAt:?string} */
    public static function status(string $clientId): array
    {
        $row = Db::one(
            'SELECT "createdAt", "expiresAt", "lastUsedAt" FROM "PortalToken"
             WHERE "clientId" = ? AND "revokedAt" IS NULL ORDER BY "createdAt" DESC LIMIT 1',
            [$clientId],
        );
        $active = $row !== null && ($row['expiresAt'] === null || $row['expiresAt'] > Dates::now());
        return ['active' => $active, 'createdAt' => $row['createdAt'] ?? null, 'expiresAt' => $row['expiresAt'] ?? null, 'lastUsedAt' => $row['lastUsedAt'] ?? null];
    }

    /**
     * Prüft den Schlüssel aus dem Header X-Portal-Token und liefert den zugehörigen Kunden.
     * Fehlversuche werden pro IP gezählt, damit niemand Schlüssel durchprobieren kann.
     *
     * @return array<string,mixed>
     */
    public static function authenticate(Request $r): array
    {
        $key = 'portal-fail:' . $r->ip();
        if (RateLimit::count($key, self::FAIL_WINDOW) >= self::FAIL_LIMIT) {
            throw new ApiError(429, 'Zu viele ungültige Zugriffsversuche – bitte später erneut versuchen');
        }

        $token = (string) $r->header('x-portal-token');
        $row = $token !== '' && strlen($token) <= 128
            ? Db::one('SELECT "id", "clientId", "expiresAt", "lastUsedAt" FROM "PortalToken" WHERE "tokenHash" = ? AND "revokedAt" IS NULL', [hash('sha256', $token)])
            : null;

        if ($row === null || ($row['expiresAt'] !== null && $row['expiresAt'] <= Dates::now())) {
            RateLimit::hit($key, self::FAIL_WINDOW);
            throw ApiError::unauthorized('Der Zugangslink ist ungültig oder abgelaufen');
        }

        if ($row['lastUsedAt'] === null || $row['lastUsedAt'] < gmdate(Dates::FORMAT, time() - 300)) {
            Db::run('UPDATE "PortalToken" SET "lastUsedAt" = ? WHERE "id" = ?', [Dates::now(), $row['id']]);
        }
        return Db::require('Client', $row['clientId'], 'Kunde nicht gefunden');
    }

    /** Öffentliche Adresse der Anwendung (APP_URL empfohlen; sonst aus der aktuellen Anfrage abgeleitet). */
    public static function baseUrl(): string
    {
        $configured = rtrim(trim(Env::get('APP_URL', '') ?? ''), '/');
        if ($configured !== '') {
            return $configured;
        }
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $host = preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
        return ($https ? 'https' : 'http') . '://' . $host;
    }
}
