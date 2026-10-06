<?php

declare(strict_types=1);

namespace App\Services;

use App\Pdf\InvoicePdf;
use App\Support\Activity;
use App\Support\Db;
use App\Support\InvoiceMath;

/** Gemeinsame Logik für Rechnungen: wird von Rechnungen, Angeboten (Umwandlung) und Abos (Serienrechnungen) genutzt. */
final class InvoiceService
{
    public const ITEM_SCHEMA = [
        'description' => ['required' => true, 'min' => 1, 'max' => 2000],
        'quantity' => ['type' => 'number', 'positive' => true, 'default' => 1],
        'unitPrice' => ['required' => true, 'type' => 'number'],
        'position' => ['type' => 'int'],
    ];

    /**
     * Legt Rechnung und Positionen an (Nummer wird atomar vergeben) und gibt die ID zurück.
     *
     * @param array<string,mixed> $data
     * @param list<array<string,mixed>> $items
     */
    public static function create(array $data, array $items): string
    {
        return (string) Db::transaction(static function () use ($data, $items) {
            $id = Db::insert('Invoice', $data + ['number' => Numbering::next('Invoice')]);
            self::insertItems($id, $items);
            return $id;
        });
    }

    /** @param list<array<string,mixed>> $items */
    public static function insertItems(string $invoiceId, array $items): void
    {
        foreach ($items as $index => $item) {
            Db::insert('InvoiceItem', $item + ['position' => $index, 'invoiceId' => $invoiceId]);
        }
    }

    /** @return array<string,mixed> Rechnung mit Kunde, Projekt, Positionen, Zahlungen, Mahnungen, E-Mail-Verlauf und Summen */
    public static function detail(string $id): array
    {
        $invoice = Db::require('Invoice', $id, 'Rechnung nicht gefunden');
        $invoice['client'] = Db::find('Client', $invoice['clientId']);
        $invoice['project'] = $invoice['projectId']
            ? Db::one('SELECT "id", "name" FROM "Project" WHERE "id" = ?', [$invoice['projectId']])
            : null;
        $items = Db::all('SELECT * FROM "InvoiceItem" WHERE "invoiceId" = ? ORDER BY "position" ASC', [$id]);
        $payments = Db::all('SELECT * FROM "Payment" WHERE "invoiceId" = ? ORDER BY "paidAt" DESC', [$id]);

        $invoice = InvoiceMath::withTotals($invoice, $items, $payments);
        $invoice['reminders'] = Db::all('SELECT * FROM "Reminder" WHERE "invoiceId" = ? ORDER BY "createdAt" DESC', [$id]);
        $invoice['emails'] = Db::all('SELECT * FROM "EmailLog" WHERE "kind" = \'INVOICE\' AND "refId" = ? ORDER BY "createdAt" DESC', [$id]);
        return $invoice;
    }

    /**
     * Versendet die Rechnung als PDF-Anhang. Eine Entwurfs-Rechnung wird dabei auf „Versendet“ gesetzt.
     *
     * @throws \App\Mail\MailException
     * @return array<string,mixed> aktualisierte Rechnung
     */
    public static function sendEmail(string $id, string $to, string $subject, string $message, ?string $userId): array
    {
        $invoice = self::detail($id);
        if ($invoice['status'] === 'CANCELLED') {
            throw \App\Http\ApiError::badRequest('Eine stornierte Rechnung kann nicht versendet werden');
        }

        MailService::send('INVOICE', $id, $to, $subject, $message, [[
            'name' => 'Rechnung-' . $invoice['number'] . '.pdf',
            'type' => 'application/pdf',
            'data' => InvoicePdf::render($invoice),
        ]], $userId);

        if ($invoice['status'] === 'DRAFT') {
            Db::update('Invoice', $id, ['status' => 'SENT']);
        }
        Activity::log('INVOICE_SENT', "Rechnung {$invoice['number']} wurde per E-Mail an $to gesendet", $invoice['clientId'], $invoice['projectId'], $userId);

        return self::detail($id);
    }

    /**
     * Hängt Positionen, Zahlungen und Summen an mehrere Rechnungen (ohne N+1-Abfragen).
     *
     * @param list<array<string,mixed>> $invoices
     * @return list<array<string,mixed>>
     */
    public static function withTotalsBatch(array $invoices): array
    {
        $ids = array_column($invoices, 'id');
        $items = $payments = [];
        if ($ids !== []) {
            $in = Db::in($ids);
            foreach (Db::all("SELECT * FROM \"InvoiceItem\" WHERE \"invoiceId\" IN ($in) ORDER BY \"position\" ASC", $ids) as $row) {
                $items[$row['invoiceId']][] = $row;
            }
            foreach (Db::all("SELECT * FROM \"Payment\" WHERE \"invoiceId\" IN ($in) ORDER BY \"paidAt\" DESC", $ids) as $row) {
                $payments[$row['invoiceId']][] = $row;
            }
        }
        return array_map(
            static fn ($inv) => InvoiceMath::withTotals($inv, $items[$inv['id']] ?? [], $payments[$inv['id']] ?? []),
            $invoices,
        );
    }
}
