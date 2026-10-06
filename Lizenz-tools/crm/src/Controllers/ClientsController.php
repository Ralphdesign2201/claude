<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Support\Activity;
use App\Support\Db;
use App\Support\Pagination;
use App\Support\Validator;
use App\Support\Where;

final class ClientsController
{
    private const SCHEMA = [
        'name' => ['required' => true, 'min' => 1, 'max' => 255],
        'company' => ['max' => 255],
        'email' => ['email' => true, 'emptyOk' => true, 'max' => 255],
        'phone' => ['max' => 100],
        'website' => ['max' => 500],
        'address' => ['max' => 500],
        'city' => ['max' => 255],
        'zip' => ['max' => 50],
        'country' => ['max' => 100],
        'vatId' => ['max' => 100],
        'status' => ['enum' => ['LEAD', 'ACTIVE', 'INACTIVE', 'ARCHIVED']],
        'source' => ['max' => 255],
        'tags' => ['max' => 1000],
        'notesText' => ['max' => 20000],
        'avatarUrl' => ['max' => 1000],
        'ownerId' => [],
    ];

    private const CONTACT_SCHEMA = [
        'name' => ['required' => true, 'min' => 1, 'max' => 255],
        'role' => ['max' => 255],
        'email' => ['email' => true, 'emptyOk' => true, 'max' => 255],
        'phone' => ['max' => 100],
        'isPrimary' => ['type' => 'bool'],
    ];

    public static function index(Request $r): Response
    {
        $p = Pagination::from($r);
        $where = (new Where())
            ->eq('c.status', $r->q('status'))
            ->eq('c.ownerId', $r->q('ownerId'))
            ->search(['c.name', 'c.company', 'c.email', 'c.phone'], $r->q('search'));
        if ($tag = $r->q('tag')) {
            $where->raw("c.tags LIKE ? ESCAPE '\\'", [Db::like($tag)]);
        }
        $order = $r->q('sort') === 'name' ? 'c.name COLLATE NOCASE ASC' : 'c.createdAt DESC';

        $items = Db::all(
            'SELECT c.*, o.id AS owner__id, o.name AS owner__name,
                    (SELECT COUNT(*) FROM "Project" p WHERE p.clientId = c.id) AS _count__projects,
                    (SELECT COUNT(*) FROM "Invoice" i WHERE i.clientId = c.id) AS _count__invoices
             FROM "Client" c LEFT JOIN "User" o ON o.id = c.ownerId'
            . $where->sql() . " ORDER BY $order LIMIT ? OFFSET ?",
            [...$where->params(), $p['pageSize'], $p['offset']],
        );
        $total = (int) Db::value('SELECT COUNT(*) FROM "Client" c' . $where->sql(), $where->params());

        return Response::json(Pagination::wrap($items, $total, $p['page'], $p['pageSize']));
    }

    public static function show(Request $r): Response
    {
        $id = $r->param('id');
        $client = Db::require('Client', $id, 'Kunde nicht gefunden');

        $client['owner'] = $client['ownerId']
            ? Db::one('SELECT "id", "name", "email" FROM "User" WHERE "id" = ?', [$client['ownerId']])
            : null;
        $client['contacts'] = Casts::rows(Db::all('SELECT * FROM "Contact" WHERE "clientId" = ? ORDER BY "createdAt" ASC', [$id]), ['isPrimary']);
        foreach (['projects' => 'Project', 'invoices' => 'Invoice', 'contracts' => 'Contract', 'notes' => 'Note', 'documents' => 'Document'] as $key => $table) {
            $client[$key] = Db::all("SELECT * FROM \"$table\" WHERE \"clientId\" = ? ORDER BY \"createdAt\" DESC", [$id]);
        }
        $client['notes'] = Casts::rows($client['notes'], ['pinned']);
        $client['activities'] = Db::all('SELECT * FROM "Activity" WHERE "clientId" = ? ORDER BY "createdAt" DESC LIMIT 30', [$id]);

        return Response::json($client);
    }

    public static function create(Request $r): Response
    {
        $data = Validator::validate($r->body(), self::SCHEMA);
        $data['ownerId'] = ($data['ownerId'] ?? '') ?: $r->user['id'];

        $id = Db::insert('Client', $data);
        Activity::log('CLIENT_CREATED', "Kunde \"{$data['name']}\" wurde angelegt", $id, null, $r->user['id']);

        return Response::json(Db::find('Client', $id), 201);
    }

    public static function update(Request $r): Response
    {
        $id = $r->param('id');
        $data = Validator::validate($r->body(), self::SCHEMA, partial: true);
        Db::update('Client', $id, $data, 'Kunde nicht gefunden');

        $client = Db::find('Client', $id);
        Activity::log('CLIENT_UPDATED', "Kunde \"{$client['name']}\" wurde aktualisiert", $id, null, $r->user['id']);

        return Response::json($client);
    }

    public static function delete(Request $r): Response
    {
        Db::delete('Client', $r->param('id'), 'Kunde nicht gefunden');
        return Response::noContent();
    }

    public static function createContact(Request $r): Response
    {
        $clientId = $r->param('id');
        Db::require('Client', $clientId, 'Kunde nicht gefunden');
        $data = Validator::validate($r->body(), self::CONTACT_SCHEMA);

        $id = Db::insert('Contact', $data + ['clientId' => $clientId]);
        return Response::json(Casts::row(Db::find('Contact', $id), ['isPrimary']), 201);
    }

    public static function updateContact(Request $r): Response
    {
        $contact = self::contactOf($r);
        $data = Validator::validate($r->body(), self::CONTACT_SCHEMA, partial: true);
        Db::update('Contact', $contact['id'], $data);

        return Response::json(Casts::row(Db::find('Contact', $contact['id']), ['isPrimary']));
    }

    public static function deleteContact(Request $r): Response
    {
        Db::delete('Contact', self::contactOf($r)['id']);
        return Response::noContent();
    }

    /** @return array<string,mixed> */
    private static function contactOf(Request $r): array
    {
        return Db::one('SELECT * FROM "Contact" WHERE "id" = ? AND "clientId" = ?', [$r->param('contactId'), $r->param('id')])
            ?? throw ApiError::notFound('Ansprechpartner nicht gefunden');
    }
}
