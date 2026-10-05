<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Mail\Mailer;
use App\Pdf\InvoicePdf;
use App\Pdf\ReminderPdf;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\MailTemplates;
use App\Support\Activity;
use App\Support\Dates;
use App\Support\Db;
use App\Support\Env;
use App\Support\Validator;
use DateTimeImmutable;

/** Mahnwesen: Zahlungserinnerung (Stufe 1), 1. Mahnung (Stufe 2), letzte Mahnung (Stufe 3). */
final class RemindersController
{
    private const SCHEMA = [
        'level' => ['required' => true, 'type' => 'int'],
        'fee' => ['type' => 'number'],
        'dueDate' => ['required' => true, 'type' => 'datetime'],
        'subject' => ['required' => true, 'min' => 1, 'max' => 300],
        'message' => ['required' => true, 'min' => 1, 'max' => 20000],
        'send' => ['type' => 'bool'],
        'to' => ['email' => true, 'emptyOk' => true, 'max' => 255],
    ];

    /** Überfällige, noch offene Rechnungen mit Mahnstand und vorgeschlagener nächster Stufe. */
    public static function overview(Request $r): Response
    {
        $rows = Db::all(
            'SELECT i.*, c.id AS client__id, c.name AS client__name, c.company AS client__company, c.email AS client__email
             FROM "Invoice" i JOIN "Client" c ON c.id = i.clientId
             WHERE i."status" IN (\'SENT\', \'OVERDUE\') AND i."dueDate" IS NOT NULL AND i."dueDate" < ?
             ORDER BY i."dueDate" ASC',
            [Dates::now()],
        );
        $invoices = array_values(array_filter(
            InvoiceService::withTotalsBatch($rows),
            static fn ($i) => $i['totals']['balance'] > 0,
        ));

        $last = [];
        $ids = array_column($invoices, 'id');
        if ($ids !== []) {
            $sql = 'SELECT "invoiceId", MAX("level") AS level, MAX("createdAt") AS at FROM "Reminder" WHERE "invoiceId" IN (' . Db::in($ids) . ') GROUP BY "invoiceId"';
            foreach (Db::all($sql, $ids) as $row) {
                $last[$row['invoiceId']] = $row;
            }
        }

        $now = new DateTimeImmutable('now');
        $items = array_map(static function ($inv) use ($last, $now) {
            $level = (int) ($last[$inv['id']]['level'] ?? 0);
            $next = min(3, $level + 1);
            return [
                'id' => $inv['id'],
                'number' => $inv['number'],
                'client' => $inv['client'],
                'issueDate' => $inv['issueDate'],
                'dueDate' => $inv['dueDate'],
                'currency' => $inv['currency'],
                'totals' => $inv['totals'],
                'daysOverdue' => (int) $now->diff(new DateTimeImmutable($inv['dueDate']))->days,
                'lastLevel' => $level,
                'lastReminderAt' => $last[$inv['id']]['at'] ?? null,
                'nextLevel' => $next,
                'nextLevelName' => MailTemplates::LEVEL_NAMES[$next],
            ];
        }, $invoices);

        return Response::json([
            'items' => $items,
            'summary' => [
                'count' => count($items),
                'balance' => round(array_sum(array_column(array_column($items, 'totals'), 'balance')), 2),
            ],
            'mailConfigured' => Mailer::configured(),
        ]);
    }

    /** Vorschlag (Stufe, Gebühr, Frist, Betreff, Text) für die gewünschte Mahnstufe. */
    public static function draft(Request $r): Response
    {
        $invoice = self::remindable($r->param('id'));
        $last = (int) Db::value('SELECT COALESCE(MAX("level"), 0) FROM "Reminder" WHERE "invoiceId" = ?', [$invoice['id']]);
        $level = (int) ($r->q('level') ?? min(3, $last + 1));
        if ($level < 1 || $level > 3) {
            throw ApiError::badRequest('Mahnstufe muss 1, 2 oder 3 sein');
        }
        $fee = self::defaultFee($level);
        $dueDate = (new DateTimeImmutable('now'))->modify('+' . Env::int('REMINDER_DAYS', 7) . ' days')->format(Dates::FORMAT);

        return Response::json(MailTemplates::reminder($invoice, $level, $fee, $dueDate) + [
            'level' => $level,
            'levelName' => MailTemplates::LEVEL_NAMES[$level],
            'fee' => $fee,
            'dueDate' => $dueDate,
            'to' => MailTemplates::recipient($invoice['client']),
            'balance' => $invoice['totals']['balance'],
            'mailConfigured' => Mailer::configured(),
            'lastLevel' => $last,
        ]);
    }

    public static function create(Request $r): Response
    {
        $invoice = self::remindable($r->param('id'));
        $data = Validator::validate($r->body(), self::SCHEMA);
        if ($data['level'] < 1 || $data['level'] > 3) {
            throw ApiError::badRequest('Mahnstufe muss 1, 2 oder 3 sein');
        }
        $fee = max(0.0, round((float) ($data['fee'] ?? 0), 2));
        $send = (bool) ($data['send'] ?? false);
        $to = (string) ($data['to'] ?? '');
        if ($send && $to === '') {
            throw ApiError::badRequest('Für den E-Mail-Versand wird eine Empfängeradresse benötigt');
        }

        $reminder = [
            'id' => Db::newId(),
            'invoiceId' => $invoice['id'],
            'level' => $data['level'],
            'fee' => $fee,
            'dueDate' => $data['dueDate'],
            'toEmail' => $to !== '' ? $to : null,
            'subject' => $data['subject'],
            'message' => $data['message'],
            'createdAt' => Dates::now(),
        ];

        if ($send) {
            // Zuerst senden: Schlägt der Versand fehl, wird keine Mahnung als „verschickt“ gespeichert.
            MailService::send('REMINDER', $reminder['id'], $to, $data['subject'], $data['message'], [
                ['name' => self::fileName($reminder, $invoice), 'type' => 'application/pdf', 'data' => ReminderPdf::render($reminder, $invoice)],
                ['name' => 'Rechnung-' . $invoice['number'] . '.pdf', 'type' => 'application/pdf', 'data' => InvoicePdf::render($invoice)],
            ], $r->user['id']);
            $reminder['emailedAt'] = Dates::now();
        }

        Db::transaction(static function () use ($reminder, $invoice) {
            Db::insert('Reminder', $reminder);
            if ($invoice['status'] === 'SENT') {
                Db::update('Invoice', $invoice['id'], ['status' => 'OVERDUE']);
            }
        });

        $name = MailTemplates::LEVEL_NAMES[$reminder['level']];
        Activity::log('REMINDER_CREATED', ($send ? "$name zu {$invoice['number']} an $to gesendet" : "$name zu {$invoice['number']} erstellt"), $invoice['clientId'], $invoice['projectId'], $r->user['id']);

        return Response::json(Db::find('Reminder', $reminder['id']), 201);
    }

    public static function pdf(Request $r): Response
    {
        $reminder = Db::require('Reminder', $r->param('id'), 'Mahnung nicht gefunden');
        $invoice = InvoiceService::detail($reminder['invoiceId']);

        return Response::bytes(ReminderPdf::render($reminder, $invoice), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . self::fileName($reminder, $invoice) . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public static function delete(Request $r): Response
    {
        Db::delete('Reminder', $r->param('id'), 'Mahnung nicht gefunden');
        return Response::noContent();
    }

    /** @return array<string,mixed> */
    private static function remindable(string $invoiceId): array
    {
        $invoice = InvoiceService::detail($invoiceId);
        if (!in_array($invoice['status'], ['SENT', 'OVERDUE'], true) || $invoice['totals']['balance'] <= 0) {
            throw ApiError::badRequest('Für diese Rechnung ist keine Mahnung möglich (nur versendete, offene Rechnungen)');
        }
        return $invoice;
    }

    private static function defaultFee(int $level): float
    {
        return max(0.0, (float) (Env::get("REMINDER_FEE_$level", '0') ?? 0));
    }

    /** @param array<string,mixed> $reminder @param array<string,mixed> $invoice */
    private static function fileName(array $reminder, array $invoice): string
    {
        $kind = [1 => 'Zahlungserinnerung', 2 => 'Mahnung-1', 3 => 'Mahnung-2'][$reminder['level']];
        return $kind . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $invoice['number']) . '.pdf';
    }
}
