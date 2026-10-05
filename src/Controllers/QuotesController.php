<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Mail\Mailer;
use App\Pdf\InvoicePdf;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\MailTemplates;
use App\Services\Numbering;
use App\Support\Activity;
use App\Support\Db;
use App\Support\Env;
use App\Support\InvoiceMath;
use App\Support\Pagination;
use App\Support\Validator;
use App\Support\Where;
use DateTimeImmutable;

final class QuotesController
{
    private const SCHEMA = [
        'clientId' => ['required' => true, 'min' => 1],
        'projectId' => [],
        'status' => ['enum' => ['DRAFT', 'SENT', 'ACCEPTED', 'DECLINED', 'EXPIRED']],
        'issueDate' => ['type' => 'datetime'],
        'validUntil' => ['type' => 'datetime', 'emptyOk' => true],
        'taxRate' => ['type' => 'number'],
        'discount' => ['type' => 'number'],
        'notes' => ['max' => 20000],
        'currency' => ['min' => 3, 'max' => 3],
        'items' => ['type' => 'list', 'required' => true, 'minItems' => 1, 'items' => InvoiceService::ITEM_SCHEMA],
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
            ->eq('q.status', $r->q('status'))
            ->eq('q.clientId', $r->q('clientId'))
            ->search(['q.number'], $r->q('search'));

        $quotes = Db::all(
            'SELECT q.*, c.id AS client__id, c.name AS client__name, c.company AS client__company
             FROM "Quote" q JOIN "Client" c ON c.id = q.clientId'
            . $where->sql() . ' ORDER BY q.createdAt DESC LIMIT ? OFFSET ?',
            [...$where->params(), $p['pageSize'], $p['offset']],
        );
        $total = (int) Db::value('SELECT COUNT(*) FROM "Quote" q' . $where->sql(), $where->params());

        $items = [];
        $ids = array_column($quotes, 'id');
        if ($ids !== []) {
            foreach (Db::all('SELECT * FROM "QuoteItem" WHERE "quoteId" IN (' . Db::in($ids) . ') ORDER BY "position" ASC', $ids) as $row) {
                $items[$row['quoteId']][] = $row;
            }
        }
        $quotes = array_map(static fn ($q) => self::expire(InvoiceMath::withTotals($q, $items[$q['id']] ?? [], [])), $quotes);

        return Response::json(Pagination::wrap($quotes, $total, $p['page'], $p['pageSize']));
    }

    public static function show(Request $r): Response
    {
        return Response::json(self::detail($r->param('id')));
    }

    public static function pdf(Request $r): Response
    {
        $quote = self::detail($r->param('id'));

        return Response::bytes(InvoicePdf::render($quote, 'quote'), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="Angebot-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $quote['number']) . '.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public static function create(Request $r): Response
    {
        $data = Validator::validate($r->body(), self::SCHEMA);
        $items = $data['items'];
        unset($data['items']);

        if (!array_key_exists('validUntil', $data)) {
            $base = new DateTimeImmutable($data['issueDate'] ?? 'now');
            $data['validUntil'] = $base->modify('+' . Env::int('QUOTE_VALID_DAYS', 30) . ' days')->format(\App\Support\Dates::FORMAT);
        }

        $id = (string) Db::transaction(static function () use ($data, $items) {
            $id = Db::insert('Quote', $data + ['number' => Numbering::next('Quote')]);
            self::insertItems($id, $items);
            return $id;
        });

        $quote = self::detail($id);
        Activity::log('QUOTE_CREATED', "Angebot {$quote['number']} wurde erstellt", $quote['clientId'], $quote['projectId'], $r->user['id']);

        return Response::json($quote, 201);
    }

    public static function update(Request $r): Response
    {
        $id = $r->param('id');
        $data = Validator::validate($r->body(), self::SCHEMA, partial: true);
        $items = $data['items'] ?? null;
        unset($data['items']);

        Db::transaction(static function () use ($id, $data, $items) {
            Db::update('Quote', $id, $data, 'Angebot nicht gefunden');
            if ($items !== null) {
                Db::run('DELETE FROM "QuoteItem" WHERE "quoteId" = ?', [$id]);
                self::insertItems($id, $items);
            }
        });

        return Response::json(self::detail($id));
    }

    public static function delete(Request $r): Response
    {
        Db::delete('Quote', $r->param('id'), 'Angebot nicht gefunden');
        return Response::noContent();
    }

    public static function emailDraft(Request $r): Response
    {
        $quote = self::detail($r->param('id'));

        return Response::json(MailTemplates::quote($quote) + [
            'to' => MailTemplates::recipient($quote['client']),
            'mailConfigured' => Mailer::configured(),
            'attachment' => 'Angebot-' . $quote['number'] . '.pdf',
        ]);
    }

    public static function send(Request $r): Response
    {
        $id = $r->param('id');
        $data = Validator::validate($r->body(), self::SEND_SCHEMA);
        $quote = self::detail($id);

        MailService::send('QUOTE', $id, $data['to'], $data['subject'], $data['message'], [[
            'name' => 'Angebot-' . $quote['number'] . '.pdf',
            'type' => 'application/pdf',
            'data' => InvoicePdf::render($quote, 'quote'),
        ]], $r->user['id']);

        if ($quote['status'] === 'DRAFT') {
            Db::update('Quote', $id, ['status' => 'SENT']);
        }
        Activity::log('QUOTE_SENT', "Angebot {$quote['number']} wurde per E-Mail an {$data['to']} gesendet", $quote['clientId'], $quote['projectId'], $r->user['id']);

        return Response::json(self::detail($id));
    }

    /** Wandelt das Angebot in eine Rechnung (Entwurf) um und markiert es als angenommen. */
    public static function convert(Request $r): Response
    {
        $id = $r->param('id');

        $invoiceId = (string) Db::transaction(static function () use ($id, $r) {
            $quote = self::detail($id);
            if ($quote['invoice']) {
                throw ApiError::conflict("Das Angebot wurde bereits in Rechnung {$quote['invoice']['number']} umgewandelt");
            }
            if ($quote['status'] === 'DECLINED') {
                throw ApiError::badRequest('Ein abgelehntes Angebot kann nicht in eine Rechnung umgewandelt werden');
            }

            $items = array_map(static fn ($i) => [
                'description' => $i['description'],
                'quantity' => $i['quantity'],
                'unitPrice' => $i['unitPrice'],
            ], $quote['items']);

            $invoiceId = InvoiceService::create([
                'clientId' => $quote['clientId'],
                'projectId' => $quote['projectId'],
                'status' => 'DRAFT',
                'dueDate' => (new DateTimeImmutable('now'))->modify('+' . Env::int('PAYMENT_DAYS', 14) . ' days')->format(\App\Support\Dates::FORMAT),
                'taxRate' => $quote['taxRate'],
                'discount' => $quote['discount'],
                'notes' => $quote['notes'],
                'currency' => $quote['currency'],
            ], $items);

            Db::update('Quote', $id, ['status' => 'ACCEPTED', 'invoiceId' => $invoiceId]);
            $invoice = Db::find('Invoice', $invoiceId);
            Activity::log('QUOTE_CONVERTED', "Angebot {$quote['number']} wurde in Rechnung {$invoice['number']} umgewandelt", $quote['clientId'], $quote['projectId'], $r->user['id']);
            return $invoiceId;
        });

        return Response::json(InvoiceService::detail($invoiceId), 201);
    }

    /** @return array<string,mixed> */
    public static function detail(string $id): array
    {
        $quote = Db::require('Quote', $id, 'Angebot nicht gefunden');
        $quote['client'] = Db::find('Client', $quote['clientId']);
        $quote['project'] = $quote['projectId']
            ? Db::one('SELECT "id", "name" FROM "Project" WHERE "id" = ?', [$quote['projectId']])
            : null;
        $quote['invoice'] = $quote['invoiceId']
            ? Db::one('SELECT "id", "number" FROM "Invoice" WHERE "id" = ?', [$quote['invoiceId']])
            : null;
        $items = Db::all('SELECT * FROM "QuoteItem" WHERE "quoteId" = ? ORDER BY "position" ASC', [$id]);
        $quote = self::expire(InvoiceMath::withTotals($quote, $items, []));
        $quote['emails'] = Db::all('SELECT * FROM "EmailLog" WHERE "kind" = \'QUOTE\' AND "refId" = ? ORDER BY "createdAt" DESC', [$id]);
        return $quote;
    }

    /** Ein versendetes Angebot, dessen Gültigkeit abgelaufen ist, wird als „abgelaufen“ angezeigt. */
    private static function expire(array $quote): array
    {
        if ($quote['status'] === 'SENT' && $quote['validUntil'] && $quote['validUntil'] < \App\Support\Dates::now()) {
            $quote['status'] = 'EXPIRED';
        }
        return $quote;
    }

    /** @param list<array<string,mixed>> $items */
    private static function insertItems(string $quoteId, array $items): void
    {
        foreach ($items as $index => $item) {
            Db::insert('QuoteItem', $item + ['position' => $index, 'quoteId' => $quoteId]);
        }
    }
}
