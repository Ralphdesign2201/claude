<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\MailException;
use App\Mail\Mailer;
use App\Support\Db;

final class MailService
{
    /**
     * Versendet ein Dokument per E-Mail und protokolliert Erfolg oder Fehler.
     *
     * @param 'INVOICE'|'QUOTE'|'REMINDER' $kind
     * @param list<array{name:string,type:string,data:string}> $attachments
     * @throws MailException
     */
    public static function send(string $kind, string $refId, string $to, string $subject, string $message, array $attachments, ?string $userId): void
    {
        try {
            Mailer::send($to, $subject, $message . "\n\n" . MailTemplates::signature(), $attachments);
        } catch (MailException $e) {
            self::log($kind, $refId, $to, $subject, 'FAILED', $e->getMessage(), $userId);
            throw $e;
        }
        self::log($kind, $refId, $to, $subject, 'SENT', null, $userId);
    }

    private static function log(string $kind, string $refId, string $to, string $subject, string $status, ?string $error, ?string $userId): void
    {
        Db::insert('EmailLog', [
            'kind' => $kind,
            'refId' => $refId,
            'toEmail' => $to,
            'subject' => mb_substr($subject, 0, 300),
            'status' => $status,
            'error' => $error !== null ? mb_substr($error, 0, 500) : null,
            'userId' => $userId,
        ]);
    }
}
