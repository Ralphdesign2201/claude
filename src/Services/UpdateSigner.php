<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Env;
use RuntimeException;

/**
 * Signiert Update-Pakete und Antworten des Update-Servers (Ed25519). Die Installationen prüfen mit dem öffentlichen Schlüssel,
 * dass ein Paket wirklich von dir stammt. Der geheime Schlüssel verlässt den Server nie.
 */
final class UpdateSigner
{
    /** Nachrichtenauthentifizierung (HMAC) mit einem aus dem Signaturschlüssel abgeleiteten Geheimnis, z. B. für Download-Tokens. */
    public static function mac(string $data): string
    {
        return self::b64(hash_hmac('sha256', $data, hash('sha256', self::secretKey() . '|mac', true), true));
    }

    /** Signiert beliebige Daten (Base64URL), z. B. die Prüfsumme eines Release-Pakets. */
    public static function signRaw(string $data): string
    {
        return self::b64(sodium_crypto_sign_detached($data, self::secretKey()));
    }

    /** Kurzkennung des Signaturschlüssels (für einen späteren Schlüsselwechsel). */
    public static function keyId(): string
    {
        return substr(hash('sha256', (string) base64_decode(self::publicKey())), 0, 12);
    }

    /** @param array<string,mixed> $payload @return array{payload:string,signature:string} */
    public static function sign(array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return ['payload' => self::b64($json), 'signature' => self::b64(sodium_crypto_sign_detached($json, self::secretKey()))];
    }

    public static function keyFile(): string
    {
        $configured = Env::get('UPDATE_KEY_FILE', '');
        if ($configured) {
            return $configured;
        }
        $old = APP_ROOT . '/database/license.key'; // Schlüssel aus früheren Versionen weiterverwenden – bereits ausgelieferte Installationen kennen den öffentlichen Teil

        return is_file($old) ? $old : APP_ROOT . '/database/update.key';
    }

    /** Öffentlicher Schlüssel (Base64) für die Installationen. */
    public static function publicKey(): string
    {
        return base64_encode(sodium_crypto_sign_publickey_from_secretkey(self::secretKey()));
    }

    /**
     * Geheimer Signaturschlüssel: aus UPDATE_SECRET_KEY (Base64), sonst aus der Schlüsseldatei (wird beim ersten Bedarf erzeugt).
     * Geht er verloren, können bereits ausgelieferte Installationen keine Updates mehr prüfen – er ist deshalb Teil der Backups.
     */
    private static function secretKey(): string
    {
        $fromEnv = trim(Env::get('UPDATE_SECRET_KEY', '') ?? '');
        if ($fromEnv !== '') {
            $key = base64_decode($fromEnv, true);
            if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                throw new RuntimeException('UPDATE_SECRET_KEY ist ungültig (erwartet: Base64 eines Ed25519-Schlüssels, 64 Byte)');
            }
            return $key;
        }
        $file = self::keyFile();
        if (!is_file($file)) {
            $dir = dirname($file);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Ordner für den Signaturschlüssel konnte nicht angelegt werden');
            }
            $handle = @fopen($file, 'x'); // „x“: nur anlegen, wenn es die Datei noch nicht gibt
            if ($handle !== false) {
                fwrite($handle, base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
                fclose($handle);
                @chmod($file, 0600);
            }
        }
        $key = base64_decode(trim((string) file_get_contents($file)), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Der Signaturschlüssel in ' . $file . ' ist beschädigt');
        }
        return $key;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
