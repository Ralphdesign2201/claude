<?php
declare(strict_types=1);

/** Sicherheits-Bausteine: Host/URL, Verschlüsselung, Ratenbegrenzung, Passwortregeln, TOTP, Audit-Log. */

// ---------------------------------------------------------------------------------------------- Host / URL
function valid_host(string $h): bool { return $h !== '' && strlen($h) <= 255 && (bool)preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d{1,5})?$/i', $h); }
function request_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    return (app_config()['trust_proxy'] ?? false) === true && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}
function client_ip(): string {
    if ((app_config()['trust_proxy'] ?? false) === true && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
}
/** Kanonische Basis-URL (ohne Slash am Ende), z. B. https://domain.de/pfad – für Links in Mails und Weiterleitungen. */
function app_base_url(): string {
    $c = app_config()['base_url'] ?? '';
    if (is_string($c) && preg_match('#^https?://[a-z0-9.-]+(:\d+)?(/[A-Za-z0-9._~/-]*)?$#i', $c)) return rtrim($c, '/');
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!valid_host($host)) $host = 'localhost';
    return (request_is_https() ? 'https' : 'http') . '://' . $host . base_url();
}
function app_url(string $route, array $p = []): string { return app_base_url() . '/index.php?' . http_build_query(['r' => $route] + $p); }

// ---------------------------------------------------------------------------------------------- Geheimnisse
function secret_key(): string {
    static $k = null;
    if ($k !== null) return $k;
    $f = APP_STORAGE . '/secret.key';
    if (is_file($f)) { $raw = base64_decode(trim((string)file_get_contents($f)), true); if ($raw !== false && strlen($raw) === 32) return $k = $raw; }
    $k = random_bytes(32);
    if (@file_put_contents($f, base64_encode($k) . "\n", LOCK_EX) === false) throw new RuntimeException('storage/secret.key konnte nicht angelegt werden.');
    @chmod($f, 0600);
    return $k;
}
function secret_encrypt(string $plain): string {
    if ($plain === '') return '';
    if (!function_exists('sodium_crypto_secretbox')) return 'plain:' . $plain;
    $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'enc1:' . base64_encode($n . sodium_crypto_secretbox($plain, $n, secret_key()));
}
function secret_decrypt(string $v): string {
    if ($v === '') return '';
    if (str_starts_with($v, 'plain:')) return substr($v, 6);
    if (!str_starts_with($v, 'enc1:')) return $v; // Altbestand im Klartext
    $raw = base64_decode(substr($v, 5), true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES || !function_exists('sodium_crypto_secretbox_open')) return '';
    $p = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), secret_key());
    return $p === false ? '' : $p;
}
/** Einstellungsschlüssel, die verschlüsselt gespeichert und nie in Backups exportiert werden. */
const SECRET_SETTINGS = ['smtp_pass', 'stripe_secret', 'stripe_webhook_secret', 'paypal_secret'];

// ---------------------------------------------------------------------------------------------- Ratenbegrenzung (Dateien, unabhängig von der Datenbank)
function rate_file(string $bucket, string $key): string {
    $d = APP_STORAGE . '/throttle'; if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d . '/' . preg_replace('/[^a-z0-9_]/', '', strtolower($bucket)) . '_' . hash('sha256', $key . '|' . APP_STORAGE) . '.json';
}
/** Zählt einen Versuch und liefert true, wenn er noch erlaubt ist (max pro Zeitfenster). */
function rate_hit(string $bucket, int $max, int $window, ?string $key = null): bool {
    $f = rate_file($bucket, $key ?? client_ip());
    $fp = @fopen($f, 'c+'); if (!$fp) return true; // lieber durchlassen als aussperren, wenn Dateisystem streikt
    flock($fp, LOCK_EX);
    $d = json_decode((string)stream_get_contents($fp), true); $now = time();
    $hits = array_values(array_filter(is_array($d) ? $d : [], fn($t) => is_int($t) && $t > $now - $window));
    $ok = count($hits) < $max;
    if ($ok) $hits[] = $now;
    ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($hits)); flock($fp, LOCK_UN); fclose($fp);
    return $ok;
}
function rate_count(string $bucket, int $window, ?string $key = null): int {
    $f = rate_file($bucket, $key ?? client_ip());
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : [];
    $now = time(); return count(array_filter(is_array($d) ? $d : [], fn($t) => is_int($t) && $t > $now - $window));
}
function rate_reset(string $bucket, ?string $key = null): void { @unlink(rate_file($bucket, $key ?? client_ip())); }
/** Bricht mit 429 ab, wenn das Limit überschritten ist (für teure Endpunkte). */
function rate_guard(string $bucket, int $max, int $window): void {
    if (rate_hit($bucket, $max, $window, client_ip() . '|' . (string)($_SESSION['uid'] ?? $_SESSION['sa'] ?? ''))) return;
    http_response_code(429); header('Retry-After: ' . $window);
    exit('Zu viele Anfragen. Bitte kurz warten und erneut versuchen.');
}

// ---------------------------------------------------------------------------------------------- Passwortregeln
const COMMON_PASSWORDS = ['12345678', '123456789', '1234567890', 'password', 'passwort', 'passwort1', 'password1', 'qwertz123', 'qwerty123', 'qwertzuiop', 'abcdefgh', 'abc12345', 'iloveyou', 'willkommen', 'hallo123', 'hallo1234', 'admin123', 'administrator', 'geheim123', 'geheim1234', 'test1234', 'changeme', 'letmein1', 'welcome1', 'sommer2024', 'sommer2025', 'winter2024', 'winter2025', 'master123', '11111111', '00000000', 'aaaaaaaa', 'zuhause1', 'fussball1', 'schatz123', 'sonnenschein'];
function password_error(string $pw, string $user = '', int $min = 8): ?string {
    if (mb_strlen($pw) < $min) return "Das Passwort muss mindestens $min Zeichen lang sein.";
    if (mb_strlen($pw) > 200) return 'Das Passwort ist zu lang (max. 200 Zeichen).';
    $l = mb_strtolower($pw);
    if (in_array($l, COMMON_PASSWORDS, true)) return 'Dieses Passwort ist zu einfach und häufig im Umlauf. Bitte ein anderes wählen.';
    if ($user !== '' && mb_strtolower($user) === $l) return 'Das Passwort darf nicht dem Benutzernamen entsprechen.';
    if (count(array_unique(preg_split('//u', $pw, -1, PREG_SPLIT_NO_EMPTY))) < 4) return 'Das Passwort ist zu einfach (zu wenige verschiedene Zeichen).';
    return null;
}

// ---------------------------------------------------------------------------------------------- TOTP (RFC 6238)
function base32_encode(string $s): string {
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $o = '';
    foreach (str_split($s) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 5) as $b) $o .= $a[bindec(str_pad($b, 5, '0'))];
    return $o;
}
function base32_decode(string $s): string {
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $o = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s))) as $c) $bits .= str_pad(decbin(strpos($a, $c)), 5, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 8) as $b) if (strlen($b) === 8) $o .= chr(bindec($b));
    return $o;
}
function totp_new_secret(): string { return base32_encode(random_bytes(20)); }
function totp_code(string $secretB32, int $step): string {
    $h = hash_hmac('sha1', pack('N2', 0, $step), base32_decode($secretB32), true);
    $o = ord($h[19]) & 0xf;
    $v = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($v % 1000000), 6, '0', STR_PAD_LEFT);
}
/** @return ?int akzeptierter Zeitschritt (für Wiederverwendungsschutz) oder null */
function totp_verify(string $secretB32, string $code, int $lastStep = 0, int $now = 0): ?int {
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code) || $secretB32 === '') return null;
    $cur = intdiv($now ?: time(), 30);
    for ($d = -1; $d <= 1; $d++) {
        $step = $cur + $d;
        if ($step > $lastStep && hash_equals(totp_code($secretB32, $step), $code)) return $step;
    }
    return null;
}
function totp_uri(string $secret, string $account, string $issuer): string {
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}
/** @return array [klartextcodes[], hashes[]] */
function recovery_codes_new(int $n = 8): array {
    $plain = []; $hash = [];
    for ($i = 0; $i < $n; $i++) { $c = strtolower(bin2hex(random_bytes(5))); $plain[] = substr($c, 0, 5) . '-' . substr($c, 5); $hash[] = hash('sha256', str_replace('-', '', $c)); }
    return [$plain, $hash];
}

// ---------------------------------------------------------------------------------------------- Audit-Log
/** Schreibt ein Sicherheits-Ereignis (Mandanten-/Programm-DB bzw. zentral im Superadmin-Kontext). Nie mit Passwörtern/Tokens aufrufen. */
function audit(string $action, string $detail = '', ?string $user = null, bool $central = false): void {
    try {
        $user ??= (string)(($central ? (function_exists('sa_user') ? (sa_user()['username'] ?? '') : '') : (current_user()['username'] ?? '')));
        $row = [date('Y-m-d H:i:s'), $user, substr($action, 0, 60), mb_substr($detail, 0, 480), substr(client_ip(), 0, 45)];
        $sql = 'INSERT INTO audit_log(ts, username, action, detail, ip) VALUES (?,?,?,?,?)';
        if ($central || (is_saas() && tenant_slug() === null)) cdb()->prepare($sql)->execute($row); else db()->prepare($sql)->execute($row);
    } catch (Throwable $e) { error_log('audit: ' . $e->getMessage()); }
}
