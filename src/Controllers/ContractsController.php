<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Support\Db;
use App\Support\Validator;
use App\Support\Where;

final class ContractsController
{
    private const SCHEMA = [
        'clientId' => ['required' => true, 'min' => 1],
        'title' => ['required' => true, 'min' => 1, 'max' => 500],
        'status' => ['enum' => ['DRAFT', 'SENT', 'SIGNED', 'CANCELLED']],
        'value' => ['type' => 'number'],
        'startDate' => ['type' => 'datetime', 'emptyOk' => true],
        'endDate' => ['type' => 'datetime', 'emptyOk' => true],
        'signedAt' => ['type' => 'datetime', 'emptyOk' => true],
        'fileUrl' => ['max' => 1000],
    ];

    public static function index(Request $r): Response
    {
        $where = (new Where())->eq('c.clientId', $r->q('clientId'))->eq('c.status', $r->q('status'));

        return Response::json(Db::all(
            'SELECT c.*, cl.id AS client__id, cl.name AS client__name
             FROM "Contract" c JOIN "Client" cl ON cl.id = c.clientId'
            . $where->sql() . ' ORDER BY c.createdAt DESC',
            $where->params(),
        ));
    }

    public static function show(Request $r): Response
    {
        $contract = Db::require('Contract', $r->param('id'), 'Vertrag nicht gefunden');
        $contract['client'] = Db::find('Client', $contract['clientId']);
        return Response::json($contract);
    }

    public static function create(Request $r): Response
    {
        $id = Db::insert('Contract', Validator::validate($r->body(), self::SCHEMA));
        return Response::json(Db::find('Contract', $id), 201);
    }

    public static function update(Request $r): Response
    {
        $id = $r->param('id');
        Db::update('Contract', $id, Validator::validate($r->body(), self::SCHEMA, partial: true), 'Vertrag nicht gefunden');
        return Response::json(Db::find('Contract', $id));
    }

    public static function delete(Request $r): Response
    {
        Db::delete('Contract', $r->param('id'), 'Vertrag nicht gefunden');
        return Response::noContent();
    }
}
