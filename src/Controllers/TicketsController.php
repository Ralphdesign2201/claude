<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Services\TicketService;
use App\Support\Db;
use App\Support\Pagination;
use App\Support\Validator;
use App\Support\Where;

/** Support-Verwaltung für Mitarbeiter: Tickets, Textbausteine und Hilfe-Artikel. */
final class TicketsController
{
    private const UPDATE_SCHEMA = [
        'subject' => ['min' => 3, 'max' => 200],
        'status' => ['enum' => ['OPEN', 'PENDING', 'ON_HOLD', 'RESOLVED', 'CLOSED']],
        'priority' => ['enum' => ['LOW', 'NORMAL', 'HIGH', 'URGENT']],
        'category' => ['max' => 80, 'emptyOk' => true],
        'tags' => ['max' => 200, 'emptyOk' => true],
        'assigneeId' => ['max' => 64, 'emptyOk' => true],
    ];

    private const LIST_SQL = 'SELECT t.*, c."id" AS client__id, c."name" AS client__name, c."company" AS client__company, u."id" AS assignee__id, u."name" AS assignee__name,
        (SELECT SUBSTR(m."body", 1, 140) FROM "TicketMessage" m WHERE m."ticketId" = t."id" AND m."kind" IN (\'CUSTOMER\', \'STAFF\') ORDER BY m."createdAt" DESC, m."id" DESC LIMIT 1) AS preview,
        (SELECT m."kind" FROM "TicketMessage" m WHERE m."ticketId" = t."id" AND m."kind" IN (\'CUSTOMER\', \'STAFF\') ORDER BY m."createdAt" DESC, m."id" DESC LIMIT 1) AS lastKind,
        (SELECT COUNT(*) FROM "TicketMessage" m WHERE m."ticketId" = t."id" AND m."kind" IN (\'CUSTOMER\', \'STAFF\')) AS messageCount
        FROM "Ticket" t JOIN "Client" c ON c."id" = t."clientId" LEFT JOIN "User" u ON u."id" = t."assigneeId"';

    public static function meta(Request $r): Response
    {
        return Response::json([
            'categories' => TicketService::categories(),
            'agents' => Db::all('SELECT "id", "name" FROM "User" ORDER BY "name" COLLATE NOCASE ASC'),
            'statuses' => TicketService::STATUSES,
            'priorities' => TicketService::PRIORITIES,
        ]);
    }

    public static function stats(Request $r): Response
    {
        return Response::json(TicketService::stats());
    }

    public static function index(Request $r): Response
    {
        $p = Pagination::from($r);
        $where = new Where();
        $status = $r->q('status') ?? 'open';
        if ($status === 'open') {
            $where->raw('t."status" IN (\'OPEN\', \'PENDING\', \'ON_HOLD\')');
        } elseif ($status !== 'all') {
            if (!isset(TicketService::STATUSES[$status])) {
                throw ApiError::badRequest('Ungültiger Status');
            }
            $where->eq('t."status"', $status);
        }
        $assignee = $r->q('assigneeId');
        if ($assignee === 'me') {
            $where->eq('t."assigneeId"', $r->user['id']);
        } elseif ($assignee === 'none') {
            $where->raw('t."assigneeId" IS NULL');
        } else {
            $where->eq('t."assigneeId"', $assignee);
        }
        $where->eq('t."priority"', $r->q('priority'))->eq('t."category"', $r->q('category'))->eq('t."clientId"', $r->q('clientId'));
        if ($r->q('view') === 'unread') {
            $where->raw('t."unreadStaff" = 1');
        }
        if ($r->q('search') !== null) {
            $like = Db::like($r->q('search'));
            $where->raw('(t."number" LIKE ? ESCAPE \'\\\' OR t."subject" LIKE ? ESCAPE \'\\\' OR c."name" LIKE ? ESCAPE \'\\\' OR c."company" LIKE ? ESCAPE \'\\\' OR EXISTS (SELECT 1 FROM "TicketMessage" x WHERE x."ticketId" = t."id" AND x."kind" != \'EVENT\' AND x."body" LIKE ? ESCAPE \'\\\'))', [$like, $like, $like, $like, $like]);
        }
        $order = match ($r->q('sort')) {
            'oldest' => 't."createdAt" ASC',
            'priority' => 'CASE t."priority" WHEN \'URGENT\' THEN 0 WHEN \'HIGH\' THEN 1 WHEN \'NORMAL\' THEN 2 ELSE 3 END ASC, t."createdAt" ASC',
            default => 't."lastActivityAt" DESC',
        };
        $sql = self::LIST_SQL . $where->sql() . ' ORDER BY ' . $order;

        if ($r->q('view') === 'overdue') {
            $rows = array_values(array_filter(Db::all($sql . ' LIMIT 1000', $where->params()), static fn ($t) => TicketService::sla($t)['overdue']));
            $total = count($rows);
            $rows = array_slice($rows, $p['offset'], $p['pageSize']);
        } else {
            $total = (int) Db::value('SELECT COUNT(*) FROM "Ticket" t JOIN "Client" c ON c."id" = t."clientId"' . $where->sql(), $where->params());
            $rows = Db::all($sql . ' LIMIT ? OFFSET ?', [...$where->params(), $p['pageSize'], $p['offset']]);
        }
        foreach ($rows as &$t) {
            $t['sla'] = TicketService::sla($t);
        }
        unset($t);

        return Response::json(Pagination::wrap($rows, $total, $p['page'], $p['pageSize']));
    }

    /** Alle Tickets eines Kunden samt Nachrichten (für die Kundenansicht). */
    public static function forClient(Request $r): Response
    {
        Db::require('Client', $r->param('id'), 'Kunde nicht gefunden');
        $ids = Db::all('SELECT "id" FROM "Ticket" WHERE "clientId" = ? ORDER BY "lastActivityAt" DESC LIMIT 30', [$r->param('id')]);

        return Response::json(array_map(static fn ($t) => TicketService::detail($t['id'], true), $ids));
    }

    public static function show(Request $r): Response
    {
        $id = $r->param('id');
        Db::require('Ticket', $id, 'Ticket nicht gefunden');
        Db::run('UPDATE "Ticket" SET "unreadStaff" = 0 WHERE "id" = ? AND "unreadStaff" = 1', [$id]);

        return Response::json(TicketService::detail($id, true));
    }

    public static function create(Request $r): Response
    {
        $b = self::form($r);
        $clientId = self::text($b, 'clientId', 64, true);
        $subject = self::text($b, 'subject', 200, true, 3);
        $body = self::text($b, 'body', 10000, true);
        $priority = self::enum($b, 'priority', array_keys(TicketService::PRIORITIES), 'NORMAL');
        $ticket = TicketService::create($clientId, $subject, $body, [
            'source' => 'ADMIN', 'priority' => $priority, 'category' => self::text($b, 'category', 80), 'assigneeId' => self::text($b, 'assigneeId', 64),
            'files' => TicketService::uploads($r), 'notifyCustomer' => self::flag($b, 'notifyCustomer'),
        ], $r->user + ['name' => self::staffName($r)]);

        return Response::json(TicketService::detail($ticket['id'], true), 201);
    }

    public static function update(Request $r): Response
    {
        $data = Validator::validate($r->body(), self::UPDATE_SCHEMA, partial: true);
        foreach (['category', 'tags', 'assigneeId'] as $k) {
            if (array_key_exists($k, $data) && $data[$k] === '') {
                $data[$k] = null;
            }
        }
        TicketService::update($r->param('id'), $data, ['id' => $r->user['id'], 'name' => self::staffName($r)]);

        return Response::json(TicketService::detail($r->param('id'), true));
    }

    public static function message(Request $r): Response
    {
        $b = self::form($r);
        $kind = self::enum($b, 'kind', ['STAFF', 'NOTE'], 'STAFF');
        $body = self::text($b, 'body', 10000, true);
        $status = self::text($b, 'status', 20);
        TicketService::addMessage($r->param('id'), $kind, $body, ['id' => $r->user['id'], 'name' => self::staffName($r)], TicketService::uploads($r), $status);

        return Response::json(TicketService::detail($r->param('id'), true), 201);
    }

    public static function bulk(Request $r): Response
    {
        $b = $r->body();
        $ids = is_array($b) && is_array($b['ids'] ?? null) ? array_values(array_filter($b['ids'], 'is_string')) : [];
        $action = is_array($b) ? (string) ($b['action'] ?? '') : '';
        $value = is_array($b) && isset($b['value']) && is_string($b['value']) && $b['value'] !== '' ? $b['value'] : null;
        if ($ids === [] || count($ids) > 100) {
            throw ApiError::badRequest('Bitte 1 bis 100 Tickets auswählen');
        }
        $field = ['status' => 'status', 'priority' => 'priority', 'assign' => 'assigneeId'][$action] ?? null;
        if ($action === 'delete' && $r->user['role'] !== 'ADMIN') {
            throw ApiError::forbidden('Nur Admins dürfen Tickets löschen');
        }
        if ($field === null && $action !== 'delete') {
            throw ApiError::badRequest('Unbekannte Aktion');
        }
        if ($field === 'status' && !isset(TicketService::STATUSES[(string) $value]) || $field === 'priority' && !isset(TicketService::PRIORITIES[(string) $value])) {
            throw ApiError::badRequest('Ungültiger Wert');
        }
        $staff = ['id' => $r->user['id'], 'name' => self::staffName($r)];
        $done = 0;
        foreach ($ids as $id) {
            if (Db::find('Ticket', $id) === null) {
                continue;
            }
            if ($action === 'delete') {
                TicketService::delete($id);
            } else {
                TicketService::update($id, [$field => $value], $staff);
            }
            $done++;
        }

        return Response::json(['done' => $done]);
    }

    public static function delete(Request $r): Response
    {
        if ($r->user['role'] !== 'ADMIN') {
            throw ApiError::forbidden('Nur Admins dürfen Tickets löschen');
        }
        TicketService::delete($r->param('id'));

        return Response::noContent();
    }

    public static function attachment(Request $r): Response
    {
        $a = TicketService::attachment($r->param('attId'), null);

        return self::fileResponse($a);
    }

    /** @param array{path:string,name:string,mime:string} $a */
    public static function fileResponse(array $a): Response
    {
        $inline = str_starts_with($a['mime'], 'image/') || $a['mime'] === 'application/pdf';
        $safe = preg_replace('/[^A-Za-z0-9._ -]/', '_', $a['name']) ?: 'datei';

        return Response::file($a['path'], [
            'Content-Type' => $a['mime'],
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $safe . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /* ---------- Textbausteine ---------- */

    public static function canned(Request $r): Response
    {
        return Response::json(Db::all('SELECT * FROM "CannedResponse" ORDER BY "title" COLLATE NOCASE ASC'));
    }

    public static function cannedCreate(Request $r): Response
    {
        $d = Validator::validate($r->body(), ['title' => ['required' => true, 'min' => 1, 'max' => 120], 'body' => ['required' => true, 'min' => 1, 'max' => 5000]]);

        return Response::json(Db::find('CannedResponse', Db::insert('CannedResponse', $d)), 201);
    }

    public static function cannedUpdate(Request $r): Response
    {
        $d = Validator::validate($r->body(), ['title' => ['min' => 1, 'max' => 120], 'body' => ['min' => 1, 'max' => 5000]], partial: true);
        Db::update('CannedResponse', $r->param('id'), $d, 'Textbaustein nicht gefunden');

        return Response::json(Db::find('CannedResponse', $r->param('id')));
    }

    public static function cannedDelete(Request $r): Response
    {
        Db::delete('CannedResponse', $r->param('id'), 'Textbaustein nicht gefunden');

        return Response::noContent();
    }

    /* ---------- Hilfe-Artikel (FAQ) ---------- */

    private const FAQ_SCHEMA = [
        'title' => ['required' => true, 'min' => 3, 'max' => 200],
        'body' => ['required' => true, 'min' => 1, 'max' => 20000],
        'category' => ['max' => 80, 'emptyOk' => true],
        'published' => ['type' => 'bool'],
        'sortOrder' => ['type' => 'int'],
    ];

    public static function faq(Request $r): Response
    {
        return Response::json(Db::all('SELECT * FROM "FaqArticle" ORDER BY "sortOrder" ASC, "title" COLLATE NOCASE ASC'));
    }

    public static function faqCreate(Request $r): Response
    {
        $d = self::faqData(Validator::validate($r->body(), self::FAQ_SCHEMA));

        return Response::json(Db::find('FaqArticle', Db::insert('FaqArticle', $d)), 201);
    }

    public static function faqUpdate(Request $r): Response
    {
        $d = self::faqData(Validator::validate($r->body(), self::FAQ_SCHEMA, partial: true));
        Db::update('FaqArticle', $r->param('id'), $d, 'Artikel nicht gefunden');

        return Response::json(Db::find('FaqArticle', $r->param('id')));
    }

    public static function faqDelete(Request $r): Response
    {
        Db::delete('FaqArticle', $r->param('id'), 'Artikel nicht gefunden');

        return Response::noContent();
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private static function faqData(array $d): array
    {
        if (isset($d['published'])) {
            $d['published'] = (int) $d['published'];
        }
        if (array_key_exists('category', $d) && $d['category'] === '') {
            $d['category'] = null;
        }

        return $d;
    }

    /* ---------- Hilfsfunktionen ---------- */

    /** @return array<string,mixed> */
    public static function form(Request $r): array
    {
        $b = $r->body();
        return is_array($b) && !array_is_list($b) ? $b : [];
    }

    public static function text(array $b, string $key, int $max, bool $required = false, int $min = 1): ?string
    {
        $v = $b[$key] ?? null;
        $v = is_string($v) ? trim($v) : null;
        if ($v === null || $v === '') {
            if ($required) {
                throw ApiError::badRequest('Pflichtfeld fehlt: ' . $key, ['formErrors' => [], 'fieldErrors' => (object) [$key => ['Pflichtfeld']]]);
            }
            return null;
        }
        if (mb_strlen($v) > $max || mb_strlen($v) < $min) {
            throw ApiError::badRequest("Ungültige Länge: $key ($min–$max Zeichen)", ['formErrors' => [], 'fieldErrors' => (object) [$key => ["$min–$max Zeichen"]]]);
        }
        return $v;
    }

    /** @param list<string> $allowed */
    public static function enum(array $b, string $key, array $allowed, string $default): string
    {
        $v = $b[$key] ?? null;
        if ($v === null || $v === '') {
            return $default;
        }
        if (!is_string($v) || !in_array($v, $allowed, true)) {
            throw ApiError::badRequest("Ungültiger Wert: $key");
        }
        return $v;
    }

    public static function flag(array $b, string $key): bool
    {
        return in_array($b[$key] ?? false, [true, 'true', '1', 1, 'on'], true);
    }

    private static function staffName(Request $r): string
    {
        return (string) (Db::value('SELECT "name" FROM "User" WHERE "id" = ?', [$r->user['id']]) ?? 'Support');
    }
}
