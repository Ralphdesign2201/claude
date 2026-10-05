<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Mail\Mailer;
use App\Pdf\InvoicePdf;
use App\Services\InvoiceService;
use App\Services\MailTemplates;
use App\Support\Activity;
use App\Support\Dates;
use App\Support\Db;
use App\Support\Pagination;
use App\Support\Validator;
use App\Support\Where;

final class InvoicesController
{
    public const SCHEMA = [
        'clientId' => ['required' => true, 'min' => 1],
        'projectId' => [],
        'status' => ['enum' => ['DRAFT', 'SENT', 'PAID', 'OVERDUE', 'CANCELLED']],
        'issueDate' => ['type' => 'datetime'],
        'dueDate' => ['type' => 'datetime', 'emptyOk' => true],
        'taxRate' => ['type' => 'number'],
        'discount' => ['type' => 'number'],
        'notes' => ['max' => 20000],
        'currency' => ['min' => 3, 'max' => 3],
        'items' => ['type' => 'list', 'required' => true, 'minItems' => 1, 'items' => InvoiceService::ITEM_SCHEMA],
    ];

    private const PAYMENT_SCHEMA = [
        'amount' => ['required' => true, 'type' => 'number', 'positive' => true],
        'method' => ['max' => 100],
        'paidAt' => ['type' => 'datetime'],
        'note' => ['max' => 5000],
    ];

    private const SEND_SCHEMA = [
        'to' => ['required' => true, 'email' => true, 'max' => 255],
        'subject' => ['required' => true, 'min' => 1, 'max' => 300],
        'message' => ['required' => true, 'min' => 1, 'max' => 20000],
    ];

    public static function index(Request $r): Response
    {
        $p = Pagination::from($r);
        $where = (new Where())
            ->eq('i.status', $r->q('status'))
            ->eq('i.clientId', $r->q('clientId'))
            ->eq('i.recurringId', $r->q('recurringId'))
            ->search(['i.number'], $r->q('search'));

        $invoices = Db::all(
            'SELECT i.*, c.id AS client__id, c.name AS client__name, c.company AS client__company
             FROM "Invoice" i JOIN "Client" c ON c.id = i.clientId'
            . $where->sql() . ' ORDER BY i.createdAt DESC LIMIT ? OFFSET ?',
            [...$where->params(), $p['pageSize'], $p['offset']],
        );
        $total = (int) Db::value('SELECT COUNT(*) FROM "Invoice" i' . $where->sql(), $where->params());

        return Response::json(Pagination::wrap(InvoiceService::withTotalsBatch($invoices), $total, $p['page'], $p['pageSize']));
    }

    public static function show(Request $r): Response
    {
        return Response::json(InvoiceService::detail($r->param('id')));
    }

    public static function pdf(Request $r): Response
    {
        $invoice = InvoiceService::detail($r->param('id'));

        return Response::bytes(InvoicePdf::render($invoice), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="Rechnung-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $invoice['number']) . '.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public static function create(Request $r): Response
    {
        $data = Validator::validate($r->body(), self::SCHEMA);
        $items = $data['items'];
        unset($data['items']);

        $invoice = InvoiceService::detail(InvoiceService::create($data, $items));
        Activity::log('INVOICE_CREATED', "Rechnung {$invoice['number']} wurde erstellt", $invoice['clientId'], $invoice['projectId'], $r->user['id']);

        return Response::json($invoice, 201);
    }

    public static function update(Request $r): Response
    {
        $id = $r->param('id');
        $data = Validator::validate($r->body(), self::SCHEMA, partial: true);
        $items = $data['items'] ?? null;
        unset($data['items']);

        if (isset($data['status'])) {
            $data['paidAt'] = $data['status'] === 'PAID' ? Dates::now() : null;
        }

        Db::transaction(static function () use ($id, $data, $items) {
            Db::update('Invoice', $id, $data, 'Rechnung nicht gefunden');
            if ($items !== null) {
                Db::run('DELETE FROM "InvoiceItem" WHERE "invoiceId" = ?', [$id]);
                InvoiceService::insertItems($id, $items);
            }
        });

        return Response::json(InvoiceService::detail($id));
    }

    public static function delete(Request $r): Response
    {
        Db::delete('Invoice', $r->param('id'), 'Rechnung nicht gefunden');
        return Response::noContent();
    }

    public static function addPayment(Request $r): Response
    {
        $invoiceId = $r->param('id');
        Db::require('Invoice', $invoiceId, 'Rechnung nicht gefunden');
        $data = Validator::validate($r->body(), self::PAYMENT_SCHEMA);

        $paymentId = Db::transaction(static function () use ($invoiceId, $data) {
            $paymentId = Db::insert('Payment', $data + ['invoiceId' => $invoiceId]);

            $invoice = InvoiceService::detail($invoiceId);
            if ($invoice['totals']['paid'] >= $invoice['totals']['total'] && $invoice['status'] !== 'PAID') {
                Db::update('Invoice', $invoiceId, ['status' => 'PAID', 'paidAt' => Dates::now()]);
            }
            return $paymentId;
        });

        return Response::json(Db::find('Payment', $paymentId), 201);
    }

    public static function deletePayment(Request $r): Response
    {
        $payment = Db::one('SELECT "id" FROM "Payment" WHERE "id" = ? AND "invoiceId" = ?', [$r->param('paymentId'), $r->param('id')])
            ?? throw ApiError::notFound('Zahlung nicht gefunden');
        Db::delete('Payment', $payment['id']);
        return Response::noContent();
    }

    /** Vorschlag für Empfänger, Betreff und Text der Rechnungs-E-Mail. */
    public static function emailDraft(Request $r): Response
    {
        $invoice = InvoiceService::detail($r->param('id'));
        $draft = MailTemplates::invoice($invoice);

        return Response::json($draft + [
            'to' => MailTemplates::recipient($invoice['client']),
            'mailConfigured' => Mailer::configured(),
            'attachment' => 'Rechnung-' . $invoice['number'] . '.pdf',
        ]);
    }

    /** Versendet die Rechnung als PDF-Anhang. Eine Entwurfs-Rechnung wird dabei auf „Versendet“ gesetzt. */
    public static function send(Request $r): Response
    {
        $data = Validator::validate($r->body(), self::SEND_SCHEMA);

        return Response::json(InvoiceService::sendEmail($r->param('id'), $data['to'], $data['subject'], $data['message'], $r->user['id']));
    }
}
