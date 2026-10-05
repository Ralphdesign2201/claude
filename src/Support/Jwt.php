<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/** HS256-JWT ohne externe Abhängigkeit. */
final class Jwt
{
    public static function secret(): string
    {
        $secret = Env::get('JWT_SECRET', '');
        if ($secret === null || strlen($secret) < 16) {
            throw new RuntimeException('JWT_SECRET fehlt oder ist zu kurz (mindestens 16 Zeichen)');
        }
        return $secret;
    }

    /** @param array<string,mixed> $claims */
    public static function encode(array $claims): string
    {
        $now = time();
        $claims += ['iat' => $now, 'exp' => $now + self::ttl()];
        $head = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body = self::b64(json_encode($claims, JSON_THROW_ON_ERROR));
        return "$head.$body." . self::b64(hash_hmac('sha256', "$head.$body", self::secret(), true));
    }

    /** @return array<string,mixed>|null null bei ungültiger Signatur oder abgelaufenem Token */
    public static function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$head, $body, $sig] = $parts;
        $header = json_decode((string) self::unb64($head), true);
        if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            return null;
        }
        $expected = self::b64(hash_hmac('sha256', "$head.$body", self::secret(), true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $claims = json_decode((string) self::unb64($body), true);
        if (!is_array($claims) || ($claims['exp'] ?? 0) < time()) {
            return null;
        }
        return $claims;
    }

    private static function ttl(): int
    {
        $value = Env::get('JWT_EXPIRES_IN', '7d');
        if (preg_match('/^(\d+)([smhd])?$/', $value, $m)) {
            $unit = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][$m[2] ?? 's'];
            return (int) $m[1] * $unit;
        }
        return 7 * 86400;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): string|false
    {
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}
