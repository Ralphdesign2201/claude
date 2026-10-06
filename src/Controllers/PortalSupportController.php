<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Services\PortalService;
use App\Services\TicketService;
use App\Support\Db;
use App\Support\RateLimit;
use App\Support\Validator;

/** Support im Kundenportal: Kunden sehen und bearbeiten ausschließlich ihre eigenen Tickets. */
final class PortalSupportController
{
    public static function list(Request $r): Response
    {
        $client = PortalService::authenticate($r);
        $rows = Db::all(
            'SELECT t."id", t."number", t."subject", t."status", t."priority", t."category", t."createdAt", t."lastActivityAt", t."unreadCustomer", t."rating",
                (SELECT SUBSTR(m."body", 1, 120) FROM "TicketMessage" m WHERE m."ticketId" = t."id" AND m."kind" IN (\'CUSTOMER\', \'STAFF\') ORDER BY m."createdAt" DESC, m."id" DESC LIMIT 1) AS preview
             FROM "Ticket" t WHERE t."clientId" = ? ORDER BY t."lastActivityAt" DESC',
            [$client['id']],
        );

        return Response::json([
            'tickets' => array_map(static fn ($t) => $t + ['unread' => (bool) $t['unreadCustomer']], $rows),
            'categories' => TicketService::categories(),
        ]);
    }

    public static function create(Request $r): Response
    {
        $client = PortalService::authenticate($r);
        if (RateLimit::hit('tk:' . $client['id'], 3600) > 15) {
            throw new ApiError(429, 'Zu viele neue Anfragen in kurzer Zeit – bitte später erneut versuchen.');
        }
        $b = TicketsController::form($r);
        $categories = TicketService::categories();
        $category = TicketsController::text($b, 'category', 80);
        if ($category !== null && !in_array($category, $categories, true)) {
            throw ApiError::badRequest('Ungültige Kategorie');
        }
        $ticket = TicketService::create(
            $client['id'],
            TicketsController::text($b, 'subject', 200, true, 3),
            TicketsController::text($b, 'message', 10000, true),
            [
                'source' => 'PORTAL', 'priority' => TicketsController::enum($b, 'priority', ['NORMAL', 'HIGH'], 'NORMAL'), 'category' => $category,
                'files' => TicketService::uploads($r),
            ],
        );

        return Response::json(TicketService::detail($ticket['id'], false), 201);
    }

    public static function show(Request $r): Response
    {
        $client = PortalService::authenticate($r);
        $t = TicketService::ownTicket($r->param('id'), $client['id']);
        Db::run('UPDATE "Ticket" SET "unreadCustomer" = 0 WHERE "id" = ? AND "unreadCustomer" = 1', [$t['id']]);

        return Response::json(TicketService::detail($t['id'], false));
    }

    public static function message(Request $r): Response
    {
        $client = PortalService::authenticate($r);
        $t = TicketService::ownTicket($r->param('id'), $client['id']);
        if (RateLimit::hit('tkm:' . $client['id'], 3600) > 60) {
            throw new ApiError(429, 'Zu viele Nachrichten in kurzer Zeit – bitte später erneut versuchen.');
        }
        $b = TicketsController::form($r);
        TicketService::addMessage($t['id'], 'CUSTOMER', TicketsController::text($b, 'message', 10000, true), null, TicketService::uploads($r));
        Db::run('UPDATE "Ticket" SET "unreadCustomer" = 0 WHERE "id" = ?', [$t['id']]);

        return Response::json(TicketService::detail($t['id'], false), 201);
    }

    public static function close(Request $r): Response
    {
        $client = PortalService::authenticate($r);
        $t = TicketService::customerAction($r->param('id'), $client['id'], 'close');

        return Response::json(TicketService::detail($t['id'], false));
    }

    public static function reopen(Request $r): Response
    {
        $client = PortalService::authenticate($r);
        $t = TicketService::customerAction($r->param('id'), $client['id'], 'reopen');

        return Response::json(TicketService::detail($t['id'], false));
    }

    public static function rate(Request $r): Response
    {
        $client = PortalService::authenticate($r);
        $d = Validator::validate($r->body(), ['rating' => ['required' => true, 'type' => 'int'], 'comment' => ['max' => 1000, 'emptyOk' => true]]);
        if ($d['rating'] < 1 || $d['rating'] > 5) {
            throw ApiError::badRequest('Bitte 1 bis 5 Sterne vergeben');
        }
        $t = TicketService::rate($r->param('id'), $client['id'], $d['rating'], (string) ($d['comment'] ?? ''));

        return Response::json(TicketService::detail($t['id'], false));
    }

    public static function attachment(Request $r): Response
    {
        $client = PortalService::authenticate($r);

        return TicketsController::fileResponse(TicketService::attachment($r->param('attId'), $client['id']));
    }

    public static function faq(Request $r): Response
    {
        PortalService::authenticate($r);

        return Response::json(Db::all('SELECT "id", "title", "body", "category" FROM "FaqArticle" WHERE "published" = 1 ORDER BY "sortOrder" ASC, "title" COLLATE NOCASE ASC'));
    }
}
