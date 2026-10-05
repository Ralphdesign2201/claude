<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Support\Db;
use App\Support\Validator;
use App\Support\Where;

final class TimeEntriesController
{
    private const SCHEMA = [
        'projectId' => ['required' => true, 'min' => 1],
        'taskId' => [],
        'description' => ['max' => 5000],
        'minutes' => ['required' => true, 'type' => 'int', 'positive' => true],
        'billable' => ['type' => 'bool'],
        'date' => ['type' => 'datetime'],
    ];

    public static function index(Request $r): Response
    {
        $where = (new Where())
            ->eq('e.projectId', $r->q('projectId'))
            ->eq('e.userId', $r->q('userId'));
        if ($from = $r->qDate('from')) {
            $where->raw('e."date" >= ?', [$from]);
        }
        if ($to = $r->qDate('to')) {
            $where->raw('e."date" <= ?', [$to]);
        }

        $items = Db::all(
            'SELECT e.*, p.id AS project__id, p.name AS project__name, p.clientId AS project__clientId, p.hourlyRate AS project__hourlyRate,
                    t.id AS task__id, t.title AS task__title,
                    u.id AS user__id, u.name AS user__name
             FROM "TimeEntry" e
             JOIN "Project" p ON p.id = e.projectId
             LEFT JOIN "Task" t ON t.id = e.taskId
             LEFT JOIN "User" u ON u.id = e.userId'
            . $where->sql() . ' ORDER BY e."date" DESC',
            $where->params(),
        );
        return Response::json(Casts::rows($items, ['billable']));
    }

    public static function create(Request $r): Response
    {
        $data = Validator::validate($r->body(), self::SCHEMA);
        $id = Db::insert('TimeEntry', $data + ['userId' => $r->user['id']]);

        return Response::json(Casts::row(Db::find('TimeEntry', $id), ['billable']), 201);
    }

    public static function update(Request $r): Response
    {
        $id = $r->param('id');
        $data = Validator::validate($r->body(), self::SCHEMA, partial: true);
        Db::update('TimeEntry', $id, $data, 'Zeiteintrag nicht gefunden');

        return Response::json(Casts::row(Db::find('TimeEntry', $id), ['billable']));
    }

    public static function delete(Request $r): Response
    {
        Db::delete('TimeEntry', $r->param('id'), 'Zeiteintrag nicht gefunden');
        return Response::noContent();
    }
}
