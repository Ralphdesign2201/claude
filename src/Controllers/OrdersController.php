<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Services\OrderService;
use App\Support\Db;
use App\Support\Pagination;
use App\Support\Validator;
use App\Support\Where;

/** Bestellungen bearbeiten (Mitarbeiter): ansehen, für Kunden erfassen, annehmen, ablehnen. */
final class OrdersController
{
    private const CREATE_SCHEMA = [
        'clientId' => ['required' => true, 'min' => 1],
        'productId' => ['required' => true, 'min' => 1],
        'quantity' => ['type' => 'number', 'positive' => true],
        'note' => ['max' => 2000],
    ];

    private const ACCEPT_SCHEMA = [
        'startDate' => ['type' => 'datetime', 'emptyOk' => true],
        'billNow' => ['type' => 'bool'],
        'autoSend' => ['type' => 'bool'],
    ];

    private const REJECT_SCHEMA = [
        'reason' => ['max' => 1000],
    ];

    public static function index(Request $r): Response
    {
        $p = Pagination::from($r);
        $where = (new Where())
            ->eq('o.status', $r->q('status'))
            ->eq('o.clientId', $r->q('clientId'))
            ->search(['o.number', 'o.productName'], $r->q('search'));

        $rows = Db::all(
            'SELECT o.*, c.id AS client__id, c.name AS client__name, c.company AS client__company
             FROM "ProductOrder" o JOIN "Client" c ON c.id = o.clientId'
            . $where->sql() . ' ORDER BY (o."status" = \'PENDING\') DESC, o."createdAt" DESC LIMIT ? OFFSET ?',
            [...$where->params(), $p['pageSize'], $p['offset']],
        );
        $total = (int) Db::value('SELECT COUNT(*) FROM "ProductOrder" o' . $where->sql(), $where->params());
        $rows = array_map(static fn ($o) => $o + ['totals' => OrderService::totals($o)], $rows);

        return Response::json(Pagination::wrap($rows, $total, $p['page'], $p['pageSize']));
    }

    public static function show(Request $r): Response
    {
        return Response::json(OrderService::detail($r->param('id')));
    }

    /** Bestellung im Namen eines Kunden erfassen (z. B. nach einem Telefonat). */
    public static function create(Request $r): Response
    {
        $data = Validator::validate($r->body(), self::CREATE_SCHEMA);
        Db::require('Client', $data['clientId'], 'Kunde nicht gefunden');
        $order = OrderService::create($data['clientId'], $data['productId'], (float) ($data['quantity'] ?? 1), (string) ($data['note'] ?? ''), 'ADMIN', false);

        return Response::json($order, 201);
    }

    public static function accept(Request $r): Response
    {
        $data = Validator::validate($r->body() ?: [], self::ACCEPT_SCHEMA);
        return Response::json(OrderService::accept($r->param('id'), $data, $r->user['id']));
    }

    public static function reject(Request $r): Response
    {
        $data = Validator::validate($r->body() ?: [], self::REJECT_SCHEMA);
        return Response::json(OrderService::reject($r->param('id'), (string) ($data['reason'] ?? ''), $r->user['id']));
    }
}
