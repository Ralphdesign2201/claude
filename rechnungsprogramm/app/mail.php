<?php
declare(strict_types=1);

/** Mailversand ohne Abhängigkeiten: PHP mail() oder eigener SMTP-Client (SSL/STARTTLS, AUTH LOGIN/PLAIN). */

/** Kontext des Versands: Systemmail der Plattform, Mandant (eigener SMTP oder Plattform-SMTP) oder Einzelinstallation. */
function mail_ctx(): array {
    $sys = !empty($GLOBALS['__mail_central']);
    $tenant = !$sys && is_saas();
    $own = $tenant && setting('mail_mode') === 'smtp' && setting('smtp_host') !== '';
    $viaPlatform = $sys || ($tenant && !$own);
    return ['sys' => $sys, 'tenant' => $tenant, 'viaPlatform' => $viaPlatform,
        'cfg' => fn(string $k, string $d = ''): string => $viaPlatform ? csetting($k, $d) : setting($k, $d)];
}
/** Einstellung für den Versand (Transport). Kompatibel zu älteren Aufrufen. */
function mail_setting(string $k, string $d = ''): string { return (mail_ctx()['cfg'])($k, $d); }
function mail_clean(string $s): string { return trim(str_replace(["\r", "\n", "\0"], ' ', $s)); }
function mail_b64_header(string $s): string { return preg_match('/^[\x20-\x7E]*$/', $s) ? $s : '=?UTF-8?B?' . base64_encode($s) . '?='; }

/** Absender-Adresse: bei Plattform-Versand die Plattform-Adresse (SPF/DKIM!), sonst die des Betriebs. */
function mail_from_address(): string { $c = mail_ctx(); $cfg = $c['cfg']; return mail_clean($cfg('mail_from') !== '' ? $cfg('mail_from') : $cfg('email')); }
/** Adresse des Betriebs (für Antworten und Kopien). */
function mail_owner_address(): string { $c = mail_ctx(); if ($c['sys']) return mail_from_address(); $a = mail_clean(setting('mail_from') !== '' ? setting('mail_from') : setting('email')); return filter_var($a, FILTER_VALIDATE_EMAIL) ? $a : ''; }

/** @param array<int,array{name:string,data:string,mime:string}> $attachments @return ?string Fehlertext oder null bei Erfolg */
function send_mail(string $to, string $subject, string $body, array $attachments = [], bool $copyToSelf = false): ?string
{
    $ctx = mail_ctx(); $cfg = $ctx['cfg'];
    $to = mail_clean($to); $from = mail_from_address(); $owner = mail_owner_address();
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return 'Die Empfänger-Adresse ist ungültig.';
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) return $ctx['viaPlatform'] && !$ctx['sys'] ? 'Der Mailversand der Plattform ist noch nicht eingerichtet. Bitte wenden Sie sich an den Betreiber oder hinterlegen Sie unter Einstellungen einen eigenen SMTP-Server.' : 'Bitte zuerst unter Einstellungen eine gültige Absender-E-Mail-Adresse eintragen.';
    $fromName = mail_clean($ctx['sys'] ? ($cfg('company') !== '' ? $cfg('company') : $cfg('brand_name')) : (setting('company') !== '' ? setting('company') : setting('owner')));
    $fromHdr = ($fromName !== '' ? mail_b64_header($fromName) . ' ' : '') . '<' . $from . '>';
    $replyTo = $owner !== '' ? $owner : $from;
    $rcpts = [$to];
    if ($copyToSelf && $owner !== '' && strcasecmp($owner, $to) !== 0) $rcpts[] = $owner;

    $boundary = '=_' . bin2hex(random_bytes(12));
    $h = [
        'From: ' . $fromHdr, 'Reply-To: ' . $replyTo, 'To: ' . $to,
        'Subject: ' . mail_b64_header(mail_clean($subject)),
        'Date: ' . date('r'), 'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . (preg_replace('/[^A-Za-z0-9.-]/', '', explode('@', $from)[1] ?? 'localhost')) . '>',
        'MIME-Version: 1.0', 'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
    ];
    $msg = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode(str_replace(["\r\n", "\r"], "\n", $body)), 76, "\r\n");
    foreach ($attachments as $a) {
        $n = mail_b64_header(mail_clean($a['name']));
        $msg .= "--$boundary\r\nContent-Type: {$a['mime']}; name=\"$n\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$n\"\r\n\r\n"
            . chunk_split(base64_encode($a['data']), 76, "\r\n");
    }
    $msg .= "--$boundary--\r\n";

    if ($cfg('mail_mode', 'mail') === 'smtp') return smtp_send($from, $rcpts, implode("\r\n", $h) . "\r\n\r\n" . $msg, $cfg);

    // PHP mail(): To/Subject kommen aus den Parametern, Rest aus den Headern
    $extra = array_values(array_filter($h, fn($x) => !str_starts_with($x, 'To: ') && !str_starts_with($x, 'Subject: ')));
    if (count($rcpts) > 1) $extra[] = 'Bcc: ' . $rcpts[1];
    $ok = @mail($to, mail_b64_header(mail_clean($subject)), $msg, implode("\r\n", $extra), '-f' . $from);
    return $ok ? null : 'PHP mail() konnte die Nachricht nicht übergeben. Bitte unter Einstellungen → E-Mail den SMTP-Versand einrichten.';
}

function smtp_send(string $from, array $rcpts, string $data, callable $cfg): ?string
{
    $host = $cfg('smtp_host'); $port = (int)($cfg('smtp_port') ?: 587); $sec = $cfg('smtp_secure', 'tls');
    if ($host === '') return 'SMTP-Server ist nicht eingetragen (Einstellungen → E-Mail).';
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client(($sec === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return "Verbindung zum SMTP-Server fehlgeschlagen ($errstr).";
    stream_set_timeout($fp, 20);
    $read = function () use ($fp): array {
        $code = 0; $text = '';
        while (($l = fgets($fp, 1024)) !== false) {
            $text .= $l; $code = (int)substr($l, 0, 3);
            if (strlen($l) < 4 || $l[3] !== '-') break;
        }
        return [$code, trim($text)];
    };
    $cmd = function (string $c, array $ok) use ($fp, $read): ?string {
        if ($c !== '') fwrite($fp, $c . "\r\n");
        [$code, $text] = $read();
        return in_array($code, $ok, true) ? null : "SMTP-Fehler: $text";
    };
    $domain = preg_replace('/[^A-Za-z0-9.-]/', '', explode('@', $from)[1] ?? 'localhost') ?: 'localhost';
    try {
        if ($e = $cmd('', [220])) return $e;
        if ($e = $cmd("EHLO $domain", [250])) return $e;
        if ($sec === 'tls') {
            if ($e = $cmd('STARTTLS', [220])) return $e;
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) return 'TLS-Verschlüsselung zum SMTP-Server fehlgeschlagen.';
            if ($e = $cmd("EHLO $domain", [250])) return $e;
        }
        if ($cfg('smtp_user') !== '') {
            if ($e = $cmd('AUTH LOGIN', [334])) return $e;
            if ($e = $cmd(base64_encode($cfg('smtp_user')), [334])) return $e;
            if ($e = $cmd(base64_encode($cfg('smtp_pass')), [235])) return 'SMTP-Anmeldung fehlgeschlagen (Benutzer/Passwort prüfen).';
        }
        if ($e = $cmd("MAIL FROM:<$from>", [250])) return $e;
        foreach ($rcpts as $r) if ($e = $cmd("RCPT TO:<$r>", [250, 251])) return $e;
        if ($e = $cmd('DATA', [354])) return $e;
        $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $data));
        fwrite($fp, str_replace("\n", "\r\n", $data) . "\r\n.\r\n");
        if ($e = $cmd('', [250])) return $e;
        $cmd('QUIT', [221]);
    } finally { fclose($fp); }
    return null;
}
