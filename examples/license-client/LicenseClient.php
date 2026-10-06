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
    public ?string $plan = null;
    /** @var list<string> */
    public array $features = [];
    public ?string $supportUntil = null;
    public ?string $updatesUntil = null;
    public ?string $slug = null;
    public ?string $issuedAt = null;
    /** @var 'fresh'|'grace'|'none'|'network' wie das letzte Ergebnis zustande kam */
    public string $source = 'none';

    /** @param array{domain?:string,timeout?:int,maxSkew?:int,version?:string} $options  Der öffentliche Schlüssel darf auch eine Liste sein (Schlüsselwechsel). */
    public function __construct(
        private string $serverUrl,
        private string|array $publicKey,
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
    public function check(bool $force = false): bool
    {
        $domain = $this->domain();
        $cache = $this->readCache($domain);

        if (!$force && $cache !== null && $cache['valid'] && $cache['checkedAt'] + $cache['cacheHours'] * 3600 > time()) {
            $this->source = 'fresh';
            return $this->apply($cache['payload']);
        }

        $result = $this->ask($domain);
        if ($result !== null) {
            $this->writeCache($domain, $result);
            $this->source = 'network';
            return $this->apply($result);
        }

        // Server nicht erreichbar oder Antwort unbrauchbar: eine zuletzt gültige Antwort gilt noch für die Kulanzfrist
        if ($cache !== null && $cache['valid']) {
            $until = $cache['checkedAt'] + ($cache['cacheHours'] * 3600) + ($cache['graceDays'] * 86400);
            if (time() < $until) {
                $this->source = 'grace';
                return $this->apply($cache['payload']);
            }
            $this->reason = 'offline';
            return false;
        }
        $this->reason = 'offline';
        return false;
    }

    /**
     * Schaut nur in den Zwischenspeicher, ohne das Netz zu benutzen.
     * Liefert 'fresh' (noch frisch), 'grace' (abgelaufen, aber innerhalb der Kulanzfrist) oder 'none'.
     */
    public function peek(): string
    {
        $cache = $this->readCache($this->domain());
        if ($cache === null || !$cache['valid']) {
            return $this->source = 'none';
        }
        $fresh = $cache['checkedAt'] + $cache['cacheHours'] * 3600 > time();
        if ($fresh || time() < $cache['checkedAt'] + $cache['cacheHours'] * 3600 + $cache['graceDays'] * 86400) {
            $this->apply($cache['payload']);
            return $this->source = $fresh ? 'fresh' : 'grace';
        }
        return $this->source = 'none';
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
        $this->plan = $payload['plan'] ?? null;
        $this->features = is_array($payload['features'] ?? null) ? array_values($payload['features']) : [];
        $this->supportUntil = $payload['supportUntil'] ?? null;
        $this->updatesUntil = $payload['updatesUntil'] ?? null;
        $this->slug = $payload['slug'] ?? null;
        $this->issuedAt = $payload['issuedAt'] ?? null;
        return (bool) ($payload['valid'] ?? false);
    }

    /** @return array<string,mixed>|null geprüfte Nutzdaten oder null bei Netz-/Signaturfehler */
    private function ask(string $domain): ?array
    {
        $payload = $this->signedRequest('/api/license/verify', [], null, $domain);
        if ($payload !== null) {
            $this->lastSigned = $this->lastResponse;
        }
        return $payload;
    }

    /** @var array{payload:string,signature:string}|null */
    private ?array $lastResponse = null;

    /**
     * Sendet eine Anfrage an den Lizenzserver und prüft die signierte Antwort: Signatur, Zufallswert, Domain, Zeitstempel, Art.
     * Gibt die geprüften Nutzdaten zurück oder null (Grund in $error).
     *
     * @param array<string,mixed> $fields zusätzliche Felder der Anfrage
     * @return array<string,mixed>|null
     */
    public function signedRequest(string $path, array $fields = [], ?string $type = null, ?string $domain = null): ?array
    {
        $domain ??= $this->domain();
        $nonce = bin2hex(random_bytes(16));
        $body = json_encode($fields + ['key' => $this->licenseKey, 'domain' => $domain, 'nonce' => $nonce, 'ts' => time(), 'version' => (string) ($this->options['version'] ?? '')]);
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
        if (!is_array($payload) || !in_array($payload['v'] ?? null, [1, 2], true) || ($payload['nonce'] ?? null) !== $nonce
            || ($payload['domain'] ?? null) !== $this->normalizeForCompare($domain) || ($payload['type'] ?? null) !== $type) {
            $this->error = 'Antwort passt nicht zur Anfrage';
            return null;
        }
        $issued = strtotime((string) ($payload['issuedAt'] ?? ''));
        if ($issued === false || abs(time() - $issued) > (int) ($this->options['maxSkew'] ?? 600)) {
            $this->error = 'Uhrzeit weicht zu stark ab';
            return null;
        }
        $this->lastResponse = ['payload' => $response['payload'], 'signature' => $response['signature']];
        return $payload;
    }

    /** Lädt eine Datei vom Lizenzserver (POST mit JSON-Body); gibt den Inhalt oder null zurück. */
    public function fetch(string $path, array $fields, int $timeout = 120): ?string
    {
        $old = $this->options['timeout'] ?? null;
        $this->options['timeout'] = $timeout;
        try {
            return $this->post($this->serverUrl . $path, (string) json_encode($fields));
        } finally {
            $old === null ? $this->options = array_diff_key($this->options, ['timeout' => 1]) : $this->options['timeout'] = $old;
        }
    }

    /** Prüft eine beliebige Signatur (Base64URL) gegen die bekannten öffentlichen Schlüssel, z. B. die eines Release-Pakets. */
    public function verifyRaw(string $data, string $signatureB64): bool
    {
        $sig = self::unb64($signatureB64);
        return $sig !== null && $this->signatureOk($data, $sig);
    }

    /** Prüft die Ed25519-Signatur gegen alle bekannten öffentlichen Schlüssel (so ist ein Schlüsselwechsel möglich). */
    private function signatureOk(string $json, string $signature): bool
    {
        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        foreach ((array) $this->publicKey as $key) {
            $raw = base64_decode((string) $key, true);
            if ($raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && sodium_crypto_sign_verify_detached($signature, $json, $raw)) {
                return true;
            }
        }
        return false;
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features, true);
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
        if ($json === null || $signature === null || !$this->signatureOk($json, $signature)) {
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
