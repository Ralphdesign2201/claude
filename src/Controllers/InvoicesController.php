<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Support\Activity;
use App\Support\Dates;
use App\Support\Db;
use App\Support\InvoiceMath;
use App\Support\Pagination;
use App\Support\Validator;
use App\Support\Where;

final class InvoicesController
{
    private const ITEM_SCHEMA = [
        'description' => ['required' => true, 'min' => 1, 'max' => 2000],
        'quantity' => ['type' => 'number', 'positive' => true, 'default' => 1],
        'unitPrice' => ['required' => true, 'type' => 'number'],
        'position' => ['type' => 'int'],
    ];

    private const SCHEMA = [
        'clientId' => ['required' => true, 'min' => 1],
        'projectId' => [],
        'status' => ['enum' => ['DRAFT', 'SENT', 'PAID', 'OVERDUE', 'CANCELLED']],
        'issueDate' => ['type' => 'datetime'],
        'dueDate' => ['type' => 'datetime', 'emptyOk' => true],
        'taxRate' => ['type' => 'number'],
        'discount' => ['type' => 'number'],
        'notes' => ['max' => 20000],
        'currency' => ['min' => 3, 'max' => 3],
        'items' => ['type' => 'list', 'required' => true, 'minItems' => 1, 'items' => self::ITEM_SCHEMA],
    ];

    private const PAYMENT_SCHEMA = [
        'amount' => ['required' => true, 'type' => 'number', 'positive' => true],
        'method' => ['max' => 100],
        'paidAt' => ['type' => 'datetime'],
        'note' => ['max' => 5000],
    ];

    public static function index(Request $r): Response
    {
        $p = Pagination::from($r);
        $where = (new Where())
            ->eq('i.status', $r->q('status'))
            ->eq('i.clientId', $r->q('clientId'))
            ->search(['i.number'], $r->q('search'));

        $invoices = Db::all(
            'SELECT i.*, c.id AS client__id, c.name AS client__name, c.company AS client__company
             FROM "Invoice" i JOIN "Client" c ON c.id = i.clientId'
            . $where->sql() . ' ORDER BY i.createdAt DESC LIMIT ? OFFSET ?',
            [...$where->params(), $p['pageSize'], $p['offset']],
        );
        $total = (int) Db::value('SELECT COUNT(*) FROM "Invoice" i' . $where->sql(), $where->params());

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
        $invoices = array_map(
            static fn ($inv) => InvoiceMath::withTotals($inv, $items[$inv['id']] ?? [], $payments[$inv['id']] ?? []),
            $invoices,
        );

        return Response::json(Pagination::wrap($invoices, $total, $p['page'], $p['pageSize']));
    }

    public static function show(Request $r): Response
    {
        return Response::json(self::detail($r->param('id')));
    }

    public static function create(Request $r): Response
    {
        $data = Validator::validate($r->body(), self::SCHEMA);
        $items = $data['items'];
        unset($data['items']);

        $id = Db::transaction(static function () use ($data, $items) {
            $invoiceId = Db::insert('Invoice', $data + ['number' => self::nextNumber()]);
            self::insertItems($invoiceId, $items);
            return $invoiceId;
        });

        $invoice = self::detail($id);
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
                self::insertItems($id, $items);
            }
        });

        return Response::json(self::detail($id));
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

            $invoice = self::detail($invoiceId);
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

    /** @return array<string,mixed> */
    private static function detail(string $id): array
    {
        $invoice = Db::require('Invoice', $id, 'Rechnung nicht gefunden');
        $invoice['client'] = Db::find('Client', $invoice['clientId']);
        $invoice['project'] = $invoice['projectId']
            ? Db::one('SELECT "id", "name" FROM "Project" WHERE "id" = ?', [$invoice['projectId']])
            : null;
        $items = Db::all('SELECT * FROM "InvoiceItem" WHERE "invoiceId" = ? ORDER BY "position" ASC', [$id]);
        $payments = Db::all('SELECT * FROM "Payment" WHERE "invoiceId" = ? ORDER BY "paidAt" DESC', [$id]);

        return InvoiceMath::withTotals($invoice, $items, $payments);
    }

    /** @param list<array<string,mixed>> $items */
    private static function insertItems(string $invoiceId, array $items): void
    {
        foreach ($items as $index => $item) {
            Db::insert('InvoiceItem', $item + ['position' => $index, 'invoiceId' => $invoiceId]);
        }
    }

    /** Nächste Nummer im Format RE-JJJJ-0001; basiert auf der höchsten vorhandenen Nummer, nicht auf der Anzahl. */
    private static function nextNumber(): string
    {
        $prefix = 'RE-' . gmdate('Y') . '-';
        $max = (int) Db::value(
            'SELECT MAX(CAST(SUBSTR("number", ?) AS INTEGER)) FROM "Invoice" WHERE "number" LIKE ? ESCAPE \'\\\'',
            [strlen($prefix) + 1, addcslashes($prefix, '%_\\') . '%'],
        );
        return $prefix . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
