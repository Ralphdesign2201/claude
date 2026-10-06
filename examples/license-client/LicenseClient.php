<?php

declare(strict_types=1);

/**
 * Lizenzprüfung für Software, die an eine Domain gebunden verkauft wird.
 *
 * Benötigt PHP 8 mit den Erweiterungen sodium und curl bzw. allow_url_fopen. Keine weiteren Abhängigkeiten.
 *
 *   require __DIR__ . '/LicenseClient.php';
 *   $lic = new LicenseClient(
 *       'https://crm.example.com',            // Adresse deines CRM
 *       'BASE64-ÖFFENTLICHER-SCHLÜSSEL',      // unter /api/license/public-key abrufbar
 *       'XXXXX-XXXXX-XXXXX-XXXXX-XXXXX',      // Lizenzschlüssel des Kunden
 *       __DIR__ . '/license.cache'            // beschreibbare Datei
 *   );
 *   $lic->require();                          // beendet das Skript mit Hinweis, wenn die Lizenz nicht gültig ist
 *
 * Die Antwort des Servers ist mit Ed25519 signiert; ohne den privaten Schlüssel kann niemand eine „gültig“-Antwort fälschen.
 * Ehrlich gesagt: Wer den PHP-Code der Software selbst ändert, kann diesen Aufruf entfernen. Die Prüfung hält ehrliche
 * Kunden bei der Sache und verhindert Weitergabe – echten Schutz bieten zusätzlich Updates, Support und serverseitige Funktionen.
 */
final class LicenseClient
{
    public ?string $reason = null;
    public ?string $expiresAt = null;
    public ?string $product = null;
    public ?string $error = null;

    /** @param array{domain?:string,timeout?:int,maxSkew?:int} $options */
    public function __construct(
        private string $serverUrl,
        private string $publicKey,
        private string $licenseKey,
        private string $cacheFile,
        private array $options = [],
    ) {
        $this->serverUrl = rtrim($serverUrl, '/');
    }

    public function domain(): string
    {
        $host = $this->options['domain'] ?? ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
        $host = strtolower(trim((string) $host));
        return preg_replace('/:\d+$/', '', $host) ?? $host;
    }

    /** Liefert true bei gültiger Lizenz. Nutzt den Zwischenspeicher und fragt den Server nur gelegentlich. */
    public function check(): bool
    {
        $domain = $this->domain();
        $cache = $this->readCache($domain);

        if ($cache !== null && $cache['valid'] && $cache['checkedAt'] + $cache['cacheHours'] * 3600 > time()) {
            return $this->apply($cache['payload']);
        }

        $result = $this->ask($domain);
        if ($result !== null) {
            $this->writeCache($domain, $result);
            return $this->apply($result);
        }

        // Server nicht erreichbar oder Antwort unbrauchbar: eine zuletzt gültige Antwort gilt noch für die Kulanzfrist
        if ($cache !== null && $cache['valid']) {
            $until = $cache['checkedAt'] + ($cache['cacheHours'] * 3600) + ($cache['graceDays'] * 86400);
            if (time() < $until) {
                return $this->apply($cache['payload']);
            }
            $this->reason = 'offline';
            return false;
        }
        $this->reason = 'offline';
        return false;
    }

    /** Beendet die Anfrage mit einer verständlichen Meldung, wenn die Lizenz nicht gültig ist. */
    public function require(): void
    {
        if ($this->check()) {
            return;
        }
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Lizenz ungültig: ' . $this->message());
    }

    public function message(): string
    {
        return match ($this->reason) {
            'unknown' => 'Der Lizenzschlüssel ist unbekannt.',
            'domain' => 'Die Lizenz gilt nicht für diese Domain (' . $this->domain() . ').',
            'revoked' => 'Die Lizenz wurde widerrufen.',
            'suspended' => 'Die Lizenz ist vorübergehend gesperrt.',
            'pending' => 'Die Lizenz ist noch nicht aktiv (Zahlung offen).',
            'expired' => 'Die Lizenz ist abgelaufen.',
            'offline' => 'Der Lizenzserver ist nicht erreichbar.' . ($this->error ? ' (' . $this->error . ')' : ''),
            default => 'Die Lizenz konnte nicht geprüft werden.',
        };
    }

    /** @param array<string,mixed> $payload */
    private function apply(array $payload): bool
    {
        $this->reason = $payload['reason'] ?? null;
        $this->expiresAt = $payload['expiresAt'] ?? null;
        $this->product = $payload['product'] ?? null;
        return (bool) ($payload['valid'] ?? false);
    }

    /** @return array<string,mixed>|null geprüfte Nutzdaten oder null bei Netz-/Signaturfehler */
    private function ask(string $domain): ?array
    {
        $nonce = bin2hex(random_bytes(16));
        $body = json_encode(['key' => $this->licenseKey, 'domain' => $domain, 'nonce' => $nonce]);
        $raw = $this->post($this->serverUrl . '/api/license/verify', (string) $body);
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
        $publicKey = base64_decode($this->publicKey, true);
        if ($json === null || $signature === null || $publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || !sodium_crypto_sign_verify_detached($signature, $json, $publicKey)) {
            $this->error = 'Signatur ungültig';
            return null;
        }
        $payload = json_decode($json, true);
        if (!is_array($payload) || ($payload['v'] ?? null) !== 1 || ($payload['nonce'] ?? null) !== $nonce
            || ($payload['domain'] ?? null) !== $this->normalizeForCompare($domain)) {
            $this->error = 'Antwort passt nicht zur Anfrage';
            return null;
        }
        $issued = strtotime((string) ($payload['issuedAt'] ?? ''));
        if ($issued === false || abs(time() - $issued) > (int) ($this->options['maxSkew'] ?? 600)) {
            $this->error = 'Uhrzeit weicht zu stark ab';
            return null;
        }
        $this->lastSigned = ['payload' => $response['payload'], 'signature' => $response['signature']];
        return $payload;
    }

    private function normalizeForCompare(string $domain): string
    {
        $domain = strtolower($domain);
        if (function_exists('idn_to_ascii') && preg_match('/[^\x00-\x7F]/', $domain)) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii)) {
                $domain = $ascii;
            }
        }
        return $domain;
    }

    private function post(string $url, string $body): ?string
    {
        $timeout = (int) ($this->options['timeout'] ?? 8);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => $timeout,
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
        $context = stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true,
        ]]);
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

    /** @return array{valid:bool,payload:array<string,mixed>,checkedAt:int,cacheHours:int,graceDays:int}|null */
    private function readCache(string $domain): ?array
    {
        $raw = @file_get_contents($this->cacheFile);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['payload'], $data['signature'], $data['key'])
            || $data['key'] !== hash('sha256', $this->licenseKey)) {
            return null;
        }
        // Die Signatur wird bei jedem Lesen erneut geprüft: Eine bearbeitete Zwischenspeicher-Datei ist wertlos.
        $json = self::unb64((string) $data['payload']);
        $signature = self::unb64((string) $data['signature']);
        $publicKey = base64_decode($this->publicKey, true);
        if ($json === null || $signature === null || $publicKey === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || !sodium_crypto_sign_verify_detached($signature, $json, $publicKey)) {
            return null;
        }
        $payload = json_decode($json, true);
        if (!is_array($payload) || ($payload['domain'] ?? null) !== $this->normalizeForCompare($domain)) {
            return null;
        }
        return [
            'valid' => (bool) ($payload['valid'] ?? false), 'payload' => $payload, 'checkedAt' => (int) (strtotime((string) ($payload['issuedAt'] ?? '')) ?: 0),
            'cacheHours' => (int) ($payload['cacheHours'] ?? 24), 'graceDays' => (int) ($payload['graceDays'] ?? 7),
        ];
    }

    /** @param array<string,mixed> $payload */
    private function writeCache(string $domain, array $payload): void
    {
        // Gespeichert wird die signierte Originalantwort, damit sie beim Lesen erneut geprüft werden kann.
        // Maßgeblich für die Frist ist der signierte Zeitstempel der Antwort, nicht die Dateizeit.
        if ($this->lastSigned === null) {
            return;
        }
        @file_put_contents($this->cacheFile, json_encode([
            'key' => hash('sha256', $this->licenseKey), 'payload' => $this->lastSigned['payload'],
            'signature' => $this->lastSigned['signature'], 'checkedAt' => time(),
        ]), LOCK_EX);
    }

    /** @var array{payload:string,signature:string}|null */
    private ?array $lastSigned = null;

    private static function unb64(string $s): ?string
    {
        $out = base64_decode(strtr($s, '-_', '+/'), true);
        return $out === false ? null : $out;
    }
}
