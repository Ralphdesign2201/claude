<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Support\Activity;
use App\Support\Db;
use App\Support\Pagination;
use App\Support\Validator;
use App\Support\Where;

final class ProjectsController
{
    private const SCHEMA = [
        'clientId' => ['required' => true, 'min' => 1],
        'name' => ['required' => true, 'min' => 1, 'max' => 255],
        'description' => ['max' => 20000],
        'status' => ['enum' => ['PLANNED', 'IN_PROGRESS', 'REVIEW', 'DONE', 'ON_HOLD', 'CANCELLED']],
        'budget' => ['type' => 'number'],
        'hourlyRate' => ['type' => 'number'],
        'startDate' => ['type' => 'datetime', 'emptyOk' => true],
        'dueDate' => ['type' => 'datetime', 'emptyOk' => true],
        'ownerId' => [],
    ];

    public static function index(Request $r): Response
    {
        $p = Pagination::from($r);
        $where = (new Where())
            ->eq('p.status', $r->q('status'))
            ->eq('p.clientId', $r->q('clientId'))
            ->eq('p.ownerId', $r->q('ownerId'))
            ->search(['p.name'], $r->q('search'));

        $items = Db::all(
            'SELECT p.*, c.id AS client__id, c.name AS client__name, c.company AS client__company,
                    o.id AS owner__id, o.name AS owner__name,
                    (SELECT COUNT(*) FROM "Task" t WHERE t.projectId = p.id) AS _count__tasks,
                    (SELECT COUNT(*) FROM "Invoice" i WHERE i.projectId = p.id) AS _count__invoices
             FROM "Project" p
             JOIN "Client" c ON c.id = p.clientId
             LEFT JOIN "User" o ON o.id = p.ownerId'
            . $where->sql() . ' ORDER BY p.createdAt DESC LIMIT ? OFFSET ?',
            [...$where->params(), $p['pageSize'], $p['offset']],
        );
        $total = (int) Db::value('SELECT COUNT(*) FROM "Project" p' . $where->sql(), $where->params());

        return Response::json(Pagination::wrap($items, $total, $p['page'], $p['pageSize']));
    }

    public static function show(Request $r): Response
    {
        $id = $r->param('id');
        $project = Db::require('Project', $id, 'Projekt nicht gefunden');

        $project['client'] = Db::find('Client', $project['clientId']);
        $project['owner'] = $project['ownerId']
            ? Db::one('SELECT "id", "name", "email" FROM "User" WHERE "id" = ?', [$project['ownerId']])
            : null;
        $project['tasks'] = Db::all('SELECT * FROM "Task" WHERE "projectId" = ? ORDER BY "position" ASC, "createdAt" ASC', [$id]);
        $project['timeEntries'] = Casts::rows(Db::all('SELECT * FROM "TimeEntry" WHERE "projectId" = ? ORDER BY "date" DESC', [$id]), ['billable']);
        $project['invoices'] = Db::all('SELECT * FROM "Invoice" WHERE "projectId" = ? ORDER BY "createdAt" DESC', [$id]);
        $project['documents'] = Db::all('SELECT * FROM "Document" WHERE "projectId" = ? ORDER BY "createdAt" DESC', [$id]);
        $project['notes'] = Casts::rows(Db::all('SELECT * FROM "Note" WHERE "projectId" = ? ORDER BY "createdAt" DESC', [$id]), ['pinned']);

        return Response::json($project);
    }

    public static function create(Request $r): Response
    {
        $data = Validator::validate($r->body(), self::SCHEMA);
        $data['ownerId'] = ($data['ownerId'] ?? '') ?: $r->user['id'];

        $id = Db::insert('Project', $data);
        Activity::log('PROJECT_CREATED', "Projekt \"{$data['name']}\" wurde angelegt", $data['clientId'], $id, $r->user['id']);

        return Response::json(Db::find('Project', $id), 201);
    }

    public static function update(Request $r): Response
    {
        $id = $r->param('id');
        $data = Validator::validate($r->body(), self::SCHEMA, partial: true);
        Db::update('Project', $id, $data, 'Projekt nicht gefunden');

        $project = Db::find('Project', $id);
        Activity::log('PROJECT_UPDATED', "Projekt \"{$project['name']}\" wurde aktualisiert", $project['clientId'], $id, $r->user['id']);

        return Response::json($project);
    }

    public static function delete(Request $r): Response
    {
        Db::delete('Project', $r->param('id'), 'Projekt nicht gefunden');
        return Response::noContent();
    }

    public static function tasks(Request $r): Response
    {
        $id = $r->param('id');
        Db::require('Project', $id, 'Projekt nicht gefunden');

        return Response::json(Db::all(
            'SELECT t.*, a.id AS assignee__id, a.name AS assignee__name
             FROM "Task" t LEFT JOIN "User" a ON a.id = t.assigneeId
             WHERE t.projectId = ? ORDER BY t.position ASC, t.createdAt ASC',
            [$id],
        ));
    }

    public static function createTask(Request $r): Response
    {
        $id = $r->param('id');
        Db::require('Project', $id, 'Projekt nicht gefunden');
        $data = Validator::validate($r->body(), TasksController::SCHEMA);

        $taskId = Db::insert('Task', $data + ['projectId' => $id]);
        return Response::json(Db::find('Task', $taskId), 201);
    }
}
