<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\ApiError;
use App\Http\Request;

final class Auth
{
    /**
     * Prüft das Bearer-Token und lädt den Benutzer frisch aus der DB,
     * damit gelöschte Konten und geänderte Rollen sofort wirken.
     *
     * @return array{id:string,email:string,role:string}
     */
    public static function authenticate(Request $request): array
    {
        $token = $request->bearerToken();
        if ($token === null) {
            throw ApiError::unauthorized();
        }
        $claims = Jwt::decode($token);
        if ($claims === null || !is_string($claims['id'] ?? null)) {
            throw ApiError::unauthorized('Token ungültig oder abgelaufen');
        }
        $user = Db::one('SELECT "id", "email", "role" FROM "User" WHERE "id" = ?', [$claims['id']]);
        if ($user === null) {
            throw ApiError::unauthorized('Token ungültig oder abgelaufen');
        }
        return $user;
    }

    /** @param array<string,mixed> $user */
    public static function tokenFor(array $user): string
    {
        return Jwt::encode(['id' => $user['id'], 'email' => $user['email'], 'role' => $user['role']]);
    }
}
