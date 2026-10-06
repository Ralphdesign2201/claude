<?php

declare(strict_types=1);

namespace App\Support;

final class Env
{
    /** @var array<string,string> */
    private static array $file = [];
    /** @var array<string,string> Werte aus der Einstellungsseite (settings.json); sie gehen der .env vor */
    private static array $settings = [];
    private static ?string $settingsPath = null;

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
                $value = substr($value, 1, -1);
            }
            self::$file[trim($key)] = $value;
        }
    }

    /** Reihenfolge: echte Umgebungsvariable → Einstellungsseite (settings.json) → .env → Standardwert. */
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }
        return self::$settings[$key] ?? self::$file[$key] ?? $default;
    }

    /** Woher kommt der Wert? 'env' (Server-Umgebung, nicht änderbar), 'settings', 'file' (.env) oder 'default'. */
    public static function source(string $key): string
    {
        if (getenv($key) !== false) {
            return 'env';
        }
        return isset(self::$settings[$key]) ? 'settings' : (isset(self::$file[$key]) ? 'file' : 'default');
    }

    public static function settingsPath(): string
    {
        if (self::$settingsPath !== null) {
            return self::$settingsPath;
        }
        $path = getenv('SETTINGS_FILE') ?: (self::$file['SETTINGS_FILE'] ?? '') ?: 'database/settings.json';
        if ($path[0] !== '/' && !preg_match('#^[A-Za-z]:[\\/]#', $path)) {
            $path = APP_ROOT . '/' . $path;
        }
        return self::$settingsPath = $path;
    }

    /** Lädt die Einstellungsdatei (JSON-Objekt aus Texten). Eine kaputte Datei wird ignoriert. */
    public static function loadSettings(): void
    {
        self::$settings = [];
        $raw = @file_get_contents(self::settingsPath());
        $data = $raw === false ? null : json_decode($raw, true);
        foreach (is_array($data) ? $data : [] as $key => $value) {
            if (is_string($key) && (is_string($value) || is_int($value))) {
                self::$settings[$key] = (string) $value;
            }
        }
    }

    /** @return array<string,string> */
    public static function settings(): array
    {
        return self::$settings;
    }

    /**
     * Speichert Einstellungen dauerhaft (null = Eintrag entfernen, dann gilt wieder .env bzw. Standard; ein leerer Text bleibt als bewusst leerer Wert erhalten).
     *
     * @param array<string,?string> $changes
     */
    public static function saveSettings(array $changes): void
    {
        $path = self::settingsPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Der Ordner für die Einstellungen ist nicht beschreibbar.');
        }
        $fh = fopen($path . '.lock', 'c');
        if ($fh === false || !flock($fh, LOCK_EX)) {
            throw new \RuntimeException('Einstellungen konnten nicht gesperrt werden.');
        }
        try {
            self::loadSettings();
            $new = self::$settings;
            foreach ($changes as $key => $value) {
                if ($value === null) {
                    unset($new[$key]);
                } else {
                    $new[$key] = $value;
                }
            }
            ksort($new);
            $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
            if (file_put_contents($tmp, json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
                throw new \RuntimeException('Einstellungen konnten nicht gespeichert werden (Schreibrechte?).');
            }
            @chmod($tmp, 0600); // enthält Zugangsdaten
            if (!rename($tmp, $path)) {
                @unlink($tmp);
                throw new \RuntimeException('Einstellungen konnten nicht gespeichert werden.');
            }
            self::$settings = array_map('strval', $new);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);
        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}
