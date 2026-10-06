<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Support\Db;
use App\Support\Validator;
use App\Support\Where;

final class TasksController
{
    public const SCHEMA = [
        'title' => ['required' => true, 'min' => 1, 'max' => 500],
        'description' => ['max' => 20000],
        'status' => ['enum' => ['OPEN', 'IN_PROGRESS', 'DONE']],
        'priority' => ['enum' => ['LOW', 'MEDIUM', 'HIGH', 'URGENT']],
        'dueDate' => ['type' => 'datetime', 'emptyOk' => true],
        'position' => ['type' => 'int'],
        'assigneeId' => [],
    ];

    public static function index(Request $r): Response
    {
        $where = (new Where())
            ->eq('t.assigneeId', $r->q('assigneeId'))
            ->eq('t.status', $r->q('status'))
            ->eq('t.priority', $r->q('priority'));

        return Response::json(Db::all(
            'SELECT t.*, p.id AS project__id, p.name AS project__name, p.clientId AS project__clientId,
                    a.id AS assignee__id, a.name AS assignee__name
             FROM "Task" t
             JOIN "Project" p ON p.id = t.projectId
             LEFT JOIN "User" a ON a.id = t.assigneeId'
            . $where->sql() . ' ORDER BY t.dueDate IS NULL, t.dueDate ASC, t.createdAt DESC',
            $where->params(),
        ));
    }

    public static function update(Request $r): Response
    {
        $id = $r->param('id');
        $data = Validator::validate($r->body(), self::SCHEMA, partial: true);
        Db::update('Task', $id, $data, 'Aufgabe nicht gefunden');

        return Response::json(Db::find('Task', $id));
    }

    public static function delete(Request $r): Response
    {
        Db::delete('Task', $r->param('id'), 'Aufgabe nicht gefunden');
        return Response::noContent();
    }
}
