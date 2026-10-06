<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Update-Konfiguration dieser Installation (product.json im Projektordner, wird nie von Updates überschrieben):
 * Adresse des Update-Servers und seine öffentlichen Schlüssel. Fehlt die Datei, gibt es keine Update-Funktion
 * (z. B. beim Update-Server selbst).
 */
final class Product
{
    /** @var array<string,mixed>|null */
    private static ?array $config = null;

    /** @return array<string,mixed> */
    public static function config(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }
        // Nur aus der echten Umgebung überschreibbar (Tests) – nicht über .env oder die Einstellungsseite
        $path = getenv('PRODUCT_CONFIG') ?: APP_ROOT . '/product.json';
        $raw = is_file($path) ? @file_get_contents($path) : false;
        $data = $raw === false ? null : json_decode($raw, true);

        return self::$config = is_array($data) ? $data : [];
    }

    public static function slug(): string
    {
        return (string) (self::config()['product'] ?? 'crm');
    }

    public static function server(): string
    {
        return rtrim((string) (self::config()['server'] ?? ''), '/');
    }

    /** @return list<string> */
    public static function publicKeys(): array
    {
        $keys = self::config()['publicKeys'] ?? [];

        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    /** Ist ein Update-Server eingetragen? */
    public static function updatesConfigured(): bool
    {
        return self::server() !== '' && self::publicKeys() !== [];
    }

    public static function version(): string
    {
        $v = @file_get_contents(APP_ROOT . '/VERSION');

        return $v === false ? '0.0.0' : trim($v);
    }

    /** Für Tests: gespeicherte Konfiguration verwerfen. */
    public static function reset(): void
    {
        self::$config = null;
    }
}
