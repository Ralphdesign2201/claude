<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Env;

/**
 * Versendet E-Mails mit Anhängen, ohne externe Bibliothek.
 *
 * MAIL_DRIVER=smtp (Standard, sobald SMTP_HOST gesetzt ist): echter Versand per SMTP (STARTTLS, SSL oder unverschlüsselt)
 * MAIL_DRIVER=file: schreibt .eml-Dateien nach database/outbox/ (für lokale Entwicklung, ohne Mailserver)
 */
final class Mailer
{
    public static function driver(): ?string
    {
        $driver = strtolower(Env::get('MAIL_DRIVER', '') ?? '');
        if ($driver === 'file') {
            return 'file';
        }
        return Env::get('SMTP_HOST', '') ? 'smtp' : null;
    }

    public static function configured(): bool
    {
        return self::driver() !== null;
    }

    public static function fromAddress(): string
    {
        return trim(Env::get('MAIL_FROM', '') ?: Env::get('COMPANY_EMAIL', '') ?: '');
    }

    /**
     * @param list<array{name:string,type:string,data:string}> $attachments
     * @return string Message-ID
     */
    public static function send(string $to, string $subject, string $body, array $attachments = []): string
    {
        $driver = self::driver();
        if ($driver === null) {
            throw new MailException('E-Mail-Versand ist nicht eingerichtet (SMTP_HOST in der .env setzen).');
        }
        $from = self::fromAddress();
        if (!self::validAddress($from)) {
            throw new MailException('Absenderadresse fehlt oder ist ungültig (MAIL_FROM bzw. COMPANY_EMAIL in der .env).');
        }
        $to = trim($to);
        if (!self::validAddress($to)) {
            throw new MailException('Ungültige Empfängeradresse.');
        }

        $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
        $messageId = '<' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
        $raw = self::build($from, $to, $subject, $body, $attachments, $messageId);

        if ($driver === 'file') {
            $dir = APP_ROOT . '/database/outbox';
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new MailException('Ausgangsordner database/outbox konnte nicht angelegt werden.');
            }
            file_put_contents($dir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml', $raw);
            return $messageId;
        }

        self::smtp($from, $to, $raw);
        $bcc = trim(Env::get('MAIL_BCC', '') ?? '');
        if (self::validAddress($bcc)) {
            self::smtp($from, $bcc, $raw);
        }
        return $messageId;
    }

    public static function validAddress(string $address): bool
    {
        return $address !== '' && !preg_match('/[\r\n<>,;"]/', $address) && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** @param list<array{name:string,type:string,data:string}> $attachments */
    private static function build(string $from, string $to, string $subject, string $body, array $attachments, string $messageId): string
    {
        $fromName = trim(preg_replace('/[\r\n"\\\\]+/', ' ', Env::get('MAIL_FROM_NAME', '') ?: Env::get('COMPANY_NAME', '') ?: '') ?? '');
        $fromHeader = $fromName !== '' ? self::encodeHeader($fromName, 6) . " <$from>" : $from;
        $replyTo = trim(Env::get('COMPANY_EMAIL', '') ?? '');

        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            "From: $fromHeader",
            "To: $to",
            'Subject: ' . self::encodeHeader(preg_replace('/[\r\n]+/', ' ', $subject) ?? '', 9),
            "Message-ID: $messageId",
            'MIME-Version: 1.0',
        ];
        if (self::validAddress($replyTo) && $replyTo !== $from) {
            $headers[] = "Reply-To: $replyTo";
        }

        $text = chunk_split(base64_encode((string) preg_replace('/\r\n|\r|\n/', "\r\n", $body)), 76, "\r\n");
        if ($attachments === []) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            return implode("\r\n", $headers) . "\r\n\r\n" . $text;
        }

        $boundary = '=_' . bin2hex(random_bytes(12));
        $headers[] = "Content-Type: multipart/mixed; boundary=\"$boundary\"";
        $out = implode("\r\n", $headers) . "\r\n\r\nThis is a multi-part message in MIME format.\r\n";
        $out .= "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n$text";
        foreach ($attachments as $a) {
            $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $a['name']) ?? 'anhang';
            $out .= "--$boundary\r\nContent-Type: {$a['type']}; name=\"$name\"\r\nContent-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"$name\"\r\n\r\n" . chunk_split(base64_encode($a['data']), 76, "\r\n");
        }
        return $out . "--$boundary--\r\n";
    }

    private static function encodeHeader(string $value, int $offset): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }
        return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n", $offset);
    }

    private static function smtp(string $from, string $to, string $raw): void
    {
        $host = (string) Env::get('SMTP_HOST');
        $port = Env::int('SMTP_PORT', 587);
        $encryption = strtolower(Env::get('SMTP_ENCRYPTION', 'tls') ?? 'tls');
        $insecure = Env::bool('SMTP_INSECURE');

        $context = stream_context_create(['ssl' => [
            'verify_peer' => !$insecure,
            'verify_peer_name' => !$insecure,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);
        $socket = @stream_socket_client(($encryption === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new MailException("Verbindung zum Mailserver $host:$port fehlgeschlagen ($errstr)");
        }
        stream_set_timeout($socket, 20);

        $read = static function () use ($socket): string {
            $data = '';
            while (($line = fgets($socket, 2048)) !== false) {
                $data .= $line;
                if (strlen($line) < 4 || $line[3] !== '-') {
                    break;
                }
            }
            if ($data === '') {
                throw new MailException('Keine Antwort vom Mailserver (Zeitüberschreitung).');
            }
            return $data;
        };
        $command = static function (string $line, array $expect) use ($socket, $read): string {
            fwrite($socket, $line . "\r\n");
            $reply = $read();
            if (!in_array((int) substr($reply, 0, 3), $expect, true)) {
                throw new MailException('Mailserver: ' . trim(preg_replace('/\s+/', ' ', $reply) ?? ''));
            }
            return $reply;
        };

        try {
            $greeting = $read();
            if ((int) substr($greeting, 0, 3) !== 220) {
                throw new MailException('Mailserver: ' . trim($greeting));
            }
            $hello = 'EHLO ' . (preg_replace('/[^A-Za-z0-9.-]/', '', (string) gethostname()) ?: 'localhost');
            $caps = $command($hello, [250]);

            if ($encryption === 'tls') {
                $command('STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new MailException('TLS-Verschlüsselung konnte nicht aufgebaut werden (Zertifikat prüfen oder SMTP_ENCRYPTION anpassen).');
                }
                $caps = $command($hello, [250]);
            }

            $user = Env::get('SMTP_USER', '') ?? '';
            if ($user !== '') {
                $pass = Env::get('SMTP_PASSWORD', '') ?? '';
                if (stripos($caps, 'AUTH') !== false && stripos($caps, 'PLAIN') === false && stripos($caps, 'LOGIN') !== false) {
                    $command('AUTH LOGIN', [334]);
                    $command(base64_encode($user), [334]);
                    $command(base64_encode($pass), [235]);
                } else {
                    $command('AUTH PLAIN ' . base64_encode("\0$user\0$pass"), [235]);
                }
            }

            $command("MAIL FROM:<$from>", [250]);
            $command("RCPT TO:<$to>", [250, 251]);
            $command('DATA', [354]);

            $stuffed = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $raw)) ?? '';
            fwrite($socket, str_replace("\n", "\r\n", $stuffed) . "\r\n.\r\n");
            $reply = $read();
            if ((int) substr($reply, 0, 3) !== 250) {
                throw new MailException('Mailserver: ' . trim($reply));
            }
            @fwrite($socket, "QUIT\r\n");
        } finally {
            fclose($socket);
        }
    }
}
