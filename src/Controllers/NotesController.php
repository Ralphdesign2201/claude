<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Support\Db;
use App\Support\Validator;
use App\Support\Where;

final class NotesController
{
    private const SCHEMA = [
        'clientId' => [],
        'projectId' => [],
        'body' => ['required' => true, 'min' => 1, 'max' => 20000],
        'pinned' => ['type' => 'bool'],
    ];

    public static function index(Request $r): Response
    {
        $where = (new Where())->eq('n.clientId', $r->q('clientId'))->eq('n.projectId', $r->q('projectId'));

        $notes = Db::all(
            'SELECT n.*, a.id AS author__id, a.name AS author__name
             FROM "Note" n LEFT JOIN "User" a ON a.id = n.authorId'
            . $where->sql() . ' ORDER BY n.pinned DESC, n.createdAt DESC',
            $where->params(),
        );
        return Response::json(Casts::rows($notes, ['pinned']));
    }

    public static function create(Request $r): Response
    {
        $data = Validator::validate($r->body(), self::SCHEMA);
        $id = Db::insert('Note', $data + ['authorId' => $r->user['id']]);

        return Response::json(Casts::row(Db::find('Note', $id), ['pinned']), 201);
    }

    public static function update(Request $r): Response
    {
        $id = $r->param('id');
        Db::update('Note', $id, Validator::validate($r->body(), self::SCHEMA, partial: true), 'Notiz nicht gefunden');

        return Response::json(Casts::row(Db::find('Note', $id), ['pinned']));
    }

    public static function delete(Request $r): Response
    {
        Db::delete('Note', $r->param('id'), 'Notiz nicht gefunden');
        return Response::noContent();
    }
}
