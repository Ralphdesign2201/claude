<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Mail\MailException;
use App\Mail\Mailer;
use App\Pdf\DocumentPdf;
use App\Pdf\InvoicePdf;
use App\Services\InvoiceService;
use App\Services\MailTemplates;
use App\Services\OrderService;
use App\Services\PortalService;
use App\Support\Activity;
use App\Support\Dates;
use App\Support\Db;
use App\Support\Validator;

/**
 * Teil 1 (angemeldete Mitarbeiter): Portalzugang eines Kunden verwalten.
 * Teil 2 (Kunden mit Zugangslink): eigene Rechnungen und Angebote ansehen, als PDF laden, Angebote beantworten.
 * Kunden sehen nur ihre eigenen Daten und nie Entwürfe, stornierte Rechnungen oder interne Notizen.
 */
final class PortalController
{
    private const ISSUE_SCHEMA = [
        'send' => ['type' => 'bool'],
        'to' => ['email' => true, 'emptyOk' => true, 'max' => 255],
    ];

    /* ---------- Verwaltung ---------- */

    public static function status(Request $r): Response
    {
        $client = Db::require('Client', $r->param('id'), 'Kunde nicht gefunden');
        return Response::json(PortalService::status($client['id']) + [
            'mailConfigured' => Mailer::configured(),
            'recipient' => MailTemplates::recipient($client),
        ]);
    }

    /** Erstellt einen neuen Zugangslink (ein bestehender wird dabei ungültig) und sendet ihn auf Wunsch per E-Mail. */
    public static function issue(Request $r): Response
    {
        $client = Db::require('Client', $r->param('id'), 'Kunde nicht gefunden');
        $data = Validator::validate($r->body() ?: [], self::ISSUE_SCHEMA);
        $send = (bool) ($data['send'] ?? false);
        $to = (string) ($data['to'] ?? '') ?: MailTemplates::recipient($client);
        if ($send && $to === '') {
            throw ApiError::badRequest('Für den E-Mail-Versand wird eine Empfängeradresse benötigt');
        }

        $access = PortalService::issue($client['id'], $r->user['id']);
        $emailed = false;
        $emailError = null;
        if ($send) {
            try {
                $mail = MailTemplates::portal($client, $access['link'], $access['expiresAt']);
                Mailer::send($to, $mail['subject'], $mail['message'] . "\n\n" . MailTemplates::signature());
                $emailed = true;
            } catch (MailException $e) {
                $emailError = $e->getMessage(); // Der Link wird trotzdem angezeigt, damit er nicht verloren geht.
            }
        }
        Activity::log('PORTAL_ISSUED', 'Portalzugang erstellt' . ($emailed ? " und an $to gesendet" : ''), $client['id'], null, $r->user['id']);

        return Response::json($access + ['emailed' => $emailed, 'emailError' => $emailError, 'to' => $to], 201);
    }

    public static function revoke(Request $r): Response
    {
        $client = Db::require('Client', $r->param('id'), 'Kunde nicht gefunden');
        PortalService::revoke($client['id']);
        Activity::log('PORTAL_REVOKED', 'Portalzugang gesperrt', $client['id'], null, $r->user['id']);
        return Response::noContent();
    }

    /* ---------- Kundenansicht ---------- */

    public static function me(Request $r): Response
    {
        $client = PortalService::authenticate($r);
        $invoices = self::invoices($client['id']);
        $open = $overdue = 0.0;
        foreach ($invoices as $inv) {
            if ($inv['totals']['balance'] > 0) {
                $open += $inv['totals']['balance'];
                if ($inv['status'] === 'OVERDUE') {
                    $overdue += $inv['totals']['balance'];
                }
            }
        }
        $co = DocumentPdf::company();

        return Response::json([
            'client' => ['name' => $client['name'], 'company' => $client['company']],
            'company' => [
                'name' => $co['name'],
                'address' => array_values(array_filter(array_map('trim', explode('|', $co['address'])))),
                'email' => $co['email'],
                'phone' => $co['phone'],
                'bank' => $co['bank'],
                'iban' => $co['iban'],
                'bic' => $co['bic'],
            ],
            'summary' => ['open' => round($open, 2), 'overdue' => round($overdue, 2)],
        ]);
    }

    public static function invoiceList(Request $r): Response
    {
        $client = PortalService::authenticate($r);
        return Response::json(self::invoices($client['id']));
    }

    public static function invoicePdf(Request $r): Response
    {
        $client = PortalService::authenticate($r);
        $invoice = self::ownInvoice($client['id'], $r->param('id'));

        return Response::bytes(InvoicePdf::render($invoice), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="Rechnung-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $invoice['number']) . '.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public static function quoteList(Request $r): Response
    {
        $client = PortalService::authenticate($r);
        $ids = Db::all('SELECT "id" FROM "Quote" WHERE "clientId" = ? AND "status" != \'DRAFT\' ORDER BY "createdAt" DESC', [$client['id']]);

        return Response::json(array_map(static fn ($row) => self::publicQuote(QuotesController::detail($row['id'])), $ids));
    }

    public static function quotePdf(Request $r): Response
    {
        $client = PortalService::authenticate($r);
        $quote = self::ownQuote($client['id'], $r->param('id'));

        return Response::bytes(InvoicePdf::render($quote, 'quote'), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="Angebot-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $quote['number']) . '.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public static function acceptQuote(Request $r): Response
    {
        return self::respond($r, 'ACCEPTED');
    }

    public static function declineQuote(Request $r): Response
    {
        return self::respond($r, 'DECLINED');
    }

    /* ---------- Katalog und Bestellungen ---------- */

    private const ORDER_SCHEMA = [
        'productId' => ['required' => true, 'min' => 1],
        'quantity' => ['type' => 'number', 'positive' => true],
        'note' => ['max' => 2000],
    ];

    public static function products(Request $r): Response
    {
        PortalService::authenticate($r);
        return Response::json(OrderService::portalCatalog());
    }

    public static function orderList(Request $r): Response
    {
        $client = PortalService::authenticate($r);
        $rows = Db::all('SELECT "id" FROM "ProductOrder" WHERE "clientId" = ? ORDER BY "createdAt" DESC LIMIT 200', [$client['id']]);

        return Response::json(array_map(static fn ($row) => self::publicOrder(OrderService::detail($row['id'])), $rows));
    }

    public static function createOrder(Request $r): Response
    {
        $client = PortalService::authenticate($r);
        $data = Validator::validate($r->body(), self::ORDER_SCHEMA);
        $order = OrderService::create($client['id'], $data['productId'], (float) ($data['quantity'] ?? 1), trim((string) ($data['note'] ?? '')), 'PORTAL', true);

        return Response::json(self::publicOrder($order), 201);
    }

    public static function cancelOrder(Request $r): Response
    {
        $client = PortalService::authenticate($r);
        return Response::json(self::publicOrder(OrderService::cancel($r->param('id'), $client['id'])));
    }

    /** Nur freigegebene Felder: keine internen Verknüpfungen zu Rechnung, Abo oder Projekt. */
    private static function publicOrder(array $o): array
    {
        return [
            'id' => $o['id'],
            'number' => $o['number'],
            'status' => $o['status'],
            'createdAt' => $o['createdAt'],
            'decidedAt' => $o['decidedAt'],
            'productName' => $o['productName'],
            'productType' => $o['productType'],
            'unitPrice' => $o['unitPrice'],
            'taxRate' => $o['taxRate'],
            'quantity' => $o['quantity'],
            'unit' => $o['unit'],
            'intervalUnit' => $o['intervalUnit'],
            'setupFee' => $o['setupFee'],
            'note' => $o['note'],
            'rejectReason' => $o['rejectReason'],
            'totals' => $o['totals'],
        ];
    }

    private static function respond(Request $r, string $status): Response
    {
        $client = PortalService::authenticate($r);
        $quote = self::ownQuote($client['id'], $r->param('id'));
        // Nur ein offenes, noch gültiges Angebot kann beantwortet werden (EXPIRED wird von detail() abgeleitet).
        if ($quote['status'] !== 'SENT') {
            throw ApiError::conflict($quote['status'] === 'EXPIRED' ? 'Das Angebot ist abgelaufen – bitte melde dich bei uns' : 'Dieses Angebot wurde bereits beantwortet');
        }

        Db::update('Quote', $quote['id'], ['status' => $status, 'respondedAt' => Dates::now()]);
        $word = $status === 'ACCEPTED' ? 'angenommen' : 'abgelehnt';
        Activity::log('QUOTE_' . $status, "Angebot {$quote['number']} wurde vom Kunden im Portal $word", $client['id'], $quote['projectId'], null);

        $notify = DocumentPdf::company()['email'];
        if ($notify !== '' && Mailer::configured()) {
            try {
                Mailer::send($notify, "Angebot {$quote['number']} wurde $word", "{$client['name']} hat das Angebot {$quote['number']} im Kundenportal $word.\n\nBetrag: "
                    . \App\Support\Format::money($quote['totals']['total'], $quote['currency']));
            } catch (MailException) {
                // Die Benachrichtigung ist optional; die Antwort des Kunden ist bereits gespeichert.
            }
        }

        return Response::json(self::publicQuote(QuotesController::detail($quote['id'])));
    }

    /** @return list<array<string,mixed>> sichtbare Rechnungen (ohne Entwürfe und Stornos), neueste zuerst */
    private static function invoices(string $clientId): array
    {
        $rows = Db::all('SELECT * FROM "Invoice" WHERE "clientId" = ? AND "status" IN (\'SENT\', \'OVERDUE\', \'PAID\') ORDER BY "issueDate" DESC, "createdAt" DESC', [$clientId]);
        return array_map([self::class, 'publicInvoice'], InvoiceService::withTotalsBatch($rows));
    }

    /** @return array<string,mixed> */
    private static function ownInvoice(string $clientId, string $id): array
    {
        $invoice = InvoiceService::detail($id);
        if ($invoice['clientId'] !== $clientId || !in_array($invoice['status'], ['SENT', 'OVERDUE', 'PAID'], true)) {
            throw ApiError::notFound('Rechnung nicht gefunden');
        }
        return $invoice;
    }

    /** @return array<string,mixed> */
    private static function ownQuote(string $clientId, string $id): array
    {
        $quote = QuotesController::detail($id);
        if ($quote['clientId'] !== $clientId || $quote['status'] === 'DRAFT') {
            throw ApiError::notFound('Angebot nicht gefunden');
        }
        return $quote;
    }

    /** Nur freigegebene Felder: keine internen IDs (Projekt, Abo), keine Mahn- oder Mailprotokolle. */
    private static function publicInvoice(array $inv): array
    {
        $status = $inv['status'];
        if ($status === 'SENT' && $inv['totals']['balance'] > 0 && $inv['dueDate'] && $inv['dueDate'] < Dates::now()) {
            $status = 'OVERDUE';
        }
        return [
            'id' => $inv['id'],
            'number' => $inv['number'],
            'status' => $status,
            'issueDate' => $inv['issueDate'],
            'dueDate' => $inv['dueDate'],
            'currency' => $inv['currency'],
            'taxRate' => $inv['taxRate'],
            'discount' => $inv['discount'],
            'notes' => $inv['notes'],
            'items' => array_map(static fn ($i) => ['description' => $i['description'], 'quantity' => $i['quantity'], 'unitPrice' => $i['unitPrice']], $inv['items']),
            'payments' => array_map(static fn ($p) => ['amount' => $p['amount'], 'paidAt' => $p['paidAt'], 'method' => $p['method']], $inv['payments']),
            'totals' => $inv['totals'],
        ];
    }

    private static function publicQuote(array $q): array
    {
        return [
            'id' => $q['id'],
            'number' => $q['number'],
            'status' => $q['status'],
            'issueDate' => $q['issueDate'],
            'validUntil' => $q['validUntil'],
            'respondedAt' => $q['respondedAt'] ?? null,
            'currency' => $q['currency'],
            'taxRate' => $q['taxRate'],
            'discount' => $q['discount'],
            'notes' => $q['notes'],
            'items' => array_map(static fn ($i) => ['description' => $i['description'], 'quantity' => $i['quantity'], 'unitPrice' => $i['unitPrice']], $q['items']),
            'totals' => $q['totals'],
        ];
    }
}
