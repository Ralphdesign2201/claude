<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Support\Auth;
use App\Support\Db;
use App\Support\Env;
use App\Support\RateLimit;
use App\Support\Validator;

final class AuthController
{
    private const WINDOW = 900;

    public static function register(Request $r): Response
    {
        $data = Validator::validate($r->body(), [
            'name' => ['required' => true, 'min' => 2, 'max' => 200],
            'email' => ['required' => true, 'email' => true, 'max' => 255],
            'password' => ['required' => true, 'min' => 8, 'max' => 72],
        ]);
        $email = strtolower(trim($data['email']));

        $user = Db::transaction(static function () use ($data, $email) {
            $count = (int) Db::value('SELECT COUNT(*) FROM "User"');
            if ($count > 0 && !Env::bool('ALLOW_REGISTRATION')) {
                throw ApiError::forbidden('Registrierung ist deaktiviert – Benutzer legt ein Administrator an');
            }
            if (Db::value('SELECT 1 FROM "User" WHERE "email" = ?', [$email])) {
                throw ApiError::conflict('E-Mail-Adresse bereits registriert');
            }
            $id = Db::insert('User', [
                'name' => $data['name'],
                'email' => $email,
                'passwordHash' => password_hash($data['password'], PASSWORD_BCRYPT),
                'role' => $count === 0 ? 'ADMIN' : 'MEMBER',
            ]);
            return Db::find('User', $id);
        });

        return Response::json(['token' => Auth::tokenFor($user), 'user' => self::publicUser($user)], 201);
    }

    public static function login(Request $r): Response
    {
        $data = Validator::validate($r->body(), [
            'email' => ['required' => true, 'email' => true],
            'password' => ['required' => true, 'min' => 1, 'max' => 1000],
        ]);

        $key = 'login:' . $r->ip();
        if (RateLimit::count($key, self::WINDOW) >= Env::int('LOGIN_RATE_LIMIT_MAX', 10)) {
            throw new ApiError(429, 'Zu viele fehlgeschlagene Anmeldeversuche – bitte später erneut versuchen');
        }

        $user = Db::one('SELECT * FROM "User" WHERE "email" = ?', [strtolower(trim($data['email']))]);
        // Auch bei unbekannter E-Mail einen Hash prüfen, damit die Antwortzeit nichts verrät
        $hash = $user['passwordHash'] ?? '$2y$10$usesomesillystringforsaltuYmQ7vTn0g6L1a8kQ1k0j0dQn3rJ6a';
        if (!password_verify($data['password'], $hash) || $user === null) {
            RateLimit::hit($key, self::WINDOW);
            throw ApiError::unauthorized('E-Mail oder Passwort falsch');
        }

        RateLimit::clear($key);
        return Response::json(['token' => Auth::tokenFor($user), 'user' => self::publicUser($user)]);
    }

    public static function me(Request $r): Response
    {
        $user = Db::one('SELECT "id", "name", "email", "role", "createdAt" FROM "User" WHERE "id" = ?', [$r->user['id']]);
        return Response::json($user ?? throw ApiError::notFound('Benutzer nicht gefunden'));
    }

    /** @param array<string,mixed> $user */
    private static function publicUser(array $user): array
    {
        return ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role']];
    }
}
