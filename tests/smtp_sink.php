<?php

declare(strict_types=1);

/**
 * Minimaler SMTP-Server für Tests: nimmt Mails an (AUTH PLAIN/LOGIN erforderlich) und legt sie als .eml ab.
 * Aufruf: php tests/smtp_sink.php <port> <ordner> [zertifikat.pem [implicit]]
 * Mit Zertifikat wird STARTTLS angeboten; mit "implicit" läuft die Verbindung von Anfang an verschlüsselt (SSL).
 * Empfänger, die mit "reject@" beginnen, werden mit 550 abgelehnt.
 */

[$script, $port, $dir, $pem, $mode] = $argv + [null, '2525', sys_get_temp_dir(), null, null];
$context = stream_context_create($pem ? ['ssl' => ['local_cert' => $pem, 'verify_peer' => false]] : []);
$scheme = ($pem && $mode === 'implicit') ? 'ssl' : 'tcp';
$server = stream_socket_server("$scheme://127.0.0.1:$port", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if (!$server) {
    fwrite(STDERR, "Sink konnte nicht starten: $errstr\n");
    exit(1);
}

$user = 'mailer';
$pass = 'secret';

while ($conn = @stream_socket_accept($server, -1)) {
    $say = static fn (string $line) => fwrite($conn, $line . "\r\n");
    $say('220 sink ready');
    $authed = false;
    $secure = false;
    $from = $to = '';
    $inData = false;
    $buffer = '';

    while (($line = fgets($conn)) !== false) {
        if ($inData) {
            if ($line === ".\r\n") {
                $head = "X-Envelope-From: $from\r\nX-Envelope-To: $to\r\nX-Authenticated: " . ($authed ? 'yes' : 'no') . "\r\n";
                file_put_contents($dir . '/' . str_replace('.', '', uniqid('', true)) . '.eml', $head . $buffer);
                $say('250 queued');
                $inData = false;
                $buffer = '';
                continue;
            }
            $buffer .= str_starts_with($line, '..') ? substr($line, 1) : $line;
            continue;
        }

        $cmd = strtoupper(trim($line));
        if (str_starts_with($cmd, 'EHLO')) {
            fwrite($conn, "250-sink\r\n" . ($pem && $mode !== 'implicit' && !$secure ? "250-STARTTLS\r\n" : '') . "250-AUTH PLAIN LOGIN\r\n250 8BITMIME\r\n");
        } elseif ($cmd === 'STARTTLS' && $pem) {
            $say('220 ready for tls');
            $secure = @stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
        } elseif (str_starts_with($cmd, 'AUTH PLAIN')) {
            $parts = explode("\0", (string) base64_decode(trim(substr(trim($line), 11))));
            $authed = ($parts[1] ?? '') === $user && ($parts[2] ?? '') === $pass;
            $say($authed ? '235 ok' : '535 bad credentials');
        } elseif ($cmd === 'AUTH LOGIN') {
            $say('334 VXNlcm5hbWU6');
            $u = base64_decode(trim((string) fgets($conn)));
            $say('334 UGFzc3dvcmQ6');
            $p = base64_decode(trim((string) fgets($conn)));
            $authed = $u === $user && $p === $pass;
            $say($authed ? '235 ok' : '535 bad credentials');
        } elseif (str_starts_with($cmd, 'MAIL FROM')) {
            if (!$authed) {
                $say('530 authentication required');
                continue;
            }
            $from = trim(substr(trim($line), 10), ' <>');
            $say('250 ok');
        } elseif (str_starts_with($cmd, 'RCPT TO')) {
            $to = trim(substr(trim($line), 8), ' <>');
            $say(str_starts_with($to, 'reject@') ? '550 mailbox unavailable' : '250 ok');
        } elseif ($cmd === 'DATA') {
            $say('354 go ahead');
            $inData = true;
        } elseif ($cmd === 'QUIT') {
            $say('221 bye');
            break;
        } else {
            $say('250 ok');
        }
    }
    fclose($conn);
}
