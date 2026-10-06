<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Fragt den Update-Server ab und prüft seine Antworten: Ed25519-Signatur (mit den eingebauten öffentlichen Schlüsseln),
 * Zufallswert gegen Wiederabspielen und Zeitstempel. Benötigt die PHP-Erweiterung sodium.
 */
final class UpdateClient
{
    public ?string $error = null;

    /** @param list<string> $publicKeys  mehrere Schlüssel erlauben einen Schlüsselwechsel  @param array{timeout?:int,maxSkew?:int,version?:string} $options */
    public function __construct(private string $serverUrl, private array $publicKeys, private array $options = [])
    {
        $this->serverUrl = rtrim($serverUrl, '/');
    }

    /**
     * Signierte Anfrage; gibt die geprüfte Antwort zurück oder null (Grund in $error).
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>|null
     */
    public function signedRequest(string $path, array $fields, string $type): ?array
    {
        $this->error = null;
        $nonce = bin2hex(random_bytes(16));
        $body = json_encode($fields + ['nonce' => $nonce, 'ts' => time(), 'version' => (string) ($this->options['version'] ?? '')]);
        $raw = $this->post($this->serverUrl . $path, (string) $body);
        if ($raw === null) {
            return null;
        }
        $response = json_decode($raw, true);
        if (!is_array($response) || !is_string($response['payload'] ?? null) || !is_string($response['signature'] ?? null)) {
            $this->error = 'unerwartete Antwort';
            return null;
        }
        $json = self::unb64($response['payload']);
        $signature = self::unb64($response['signature']);
        if ($json === null || $signature === null || !$this->signatureOk($json, $signature)) {
            $this->error = 'Signatur ungültig';
            return null;
        }
        $payload = json_decode($json, true);
        if (!is_array($payload) || ($payload['nonce'] ?? null) !== $nonce || ($payload['type'] ?? null) !== $type) {
            $this->error = 'Antwort passt nicht zur Anfrage';
            return null;
        }
        $issued = strtotime((string) ($payload['issuedAt'] ?? ''));
        if ($issued === false || abs(time() - $issued) > (int) ($this->options['maxSkew'] ?? 600)) {
            $this->error = 'Uhrzeit weicht zu stark ab';
            return null;
        }

        return $payload;
    }

    /** Lädt eine Datei vom Update-Server (POST mit JSON-Body); gibt den Inhalt oder null zurück. */
    public function fetch(string $path, array $fields, int $timeout = 120): ?string
    {
        $this->error = null;

        return $this->post($this->serverUrl . $path, (string) json_encode($fields), $timeout);
    }

    /** Prüft eine beliebige Signatur (Base64URL), z. B. die eines Release-Pakets. */
    public function verifyRaw(string $data, string $signatureB64): bool
    {
        $sig = self::unb64($signatureB64);

        return $sig !== null && $this->signatureOk($data, $sig);
    }

    private function signatureOk(string $json, string $signature): bool
    {
        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        foreach ($this->publicKeys as $key) {
            $raw = base64_decode((string) $key, true);
            if ($raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && sodium_crypto_sign_verify_detached($signature, $json, $raw)) {
                return true;
            }
        }

        return false;
    }

    private function post(string $url, string $body, ?int $timeout = null): ?string
    {
        $timeout ??= (int) ($this->options['timeout'] ?? 8);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            ]);
            $out = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if (!is_string($out) || $status !== 200) {
                $this->error = $err !== '' ? $err : 'HTTP ' . $status;
                return null;
            }
            return $out;
        }
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nAccept: application/json\r\n", 'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true]]);
        $out = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                $status = (int) $m[1];
            }
        }
        if (!is_string($out) || $status !== 200) {
            $this->error = 'HTTP ' . $status;
            return null;
        }

        return $out;
    }

    private static function unb64(string $s): ?string
    {
        $out = base64_decode(strtr($s, '-_', '+/'), true);

        return $out === false ? null : $out;
    }
}
