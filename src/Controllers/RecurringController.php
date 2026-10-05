<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Services\InvoiceService;
use App\Services\RecurringService;
use App\Support\Activity;
use App\Support\Db;
use App\Support\InvoiceMath;
use App\Support\Validator;
use App\Support\Where;

final class RecurringController
{
    private const SCHEMA = [
        'clientId' => ['required' => true, 'min' => 1],
        'projectId' => [],
        'title' => ['required' => true, 'min' => 1, 'max' => 255],
        'intervalUnit' => ['enum' => ['MONTHLY', 'QUARTERLY', 'HALF_YEARLY', 'YEARLY']],
        'startDate' => ['required' => true, 'type' => 'datetime'],
        'endDate' => ['type' => 'datetime', 'emptyOk' => true],
        'active' => ['type' => 'bool'],
        'autoSend' => ['type' => 'bool'],
        'paymentDays' => ['type' => 'int'],
        'taxRate' => ['type' => 'number'],
        'discount' => ['type' => 'number'],
        'notes' => ['max' => 20000],
        'currency' => ['min' => 3, 'max' => 3],
        'items' => ['type' => 'list', 'required' => true, 'minItems' => 1, 'items' => InvoiceService::ITEM_SCHEMA],
    ];

    public static function index(Request $r): Response
    {
        $where = (new Where())->eq('r.clientId', $r->q('clientId'));
        if ($r->q('active') !== null) {
            $where->raw('r."active" = ?', [$r->q('active') === 'true' || $r->q('active') === '1' ? 1 : 0]);
        }

        $rows = Db::all(
            'SELECT r.*, c.id AS client__id, c.name AS client__name, c.company AS client__company,
                    (SELECT COUNT(*) FROM "Invoice" i WHERE i.recurringId = r.id) AS invoiceCount
             FROM "Recurring" r JOIN "Client" c ON c.id = r.clientId'
            . $where->sql() . ' ORDER BY r."active" DESC, r."nextRunDate" ASC',
            $where->params(),
        );

        $items = [];
        $ids = array_column($rows, 'id');
        if ($ids !== []) {
            foreach (Db::all('SELECT * FROM "RecurringItem" WHERE "recurringId" IN (' . Db::in($ids) . ') ORDER BY "position" ASC', $ids) as $row) {
                $items[$row['recurringId']][] = $row;
            }
        }

        $monthly = 0.0;
        $rows = array_map(static function ($rec) use ($items, &$monthly) {
            $rec = self::decorate($rec, $items[$rec['id']] ?? []);
            if ($rec['active']) {
                $monthly += $rec['monthlyNet'];
            }
            return $rec;
        }, $rows);

        return Response::json([
            'items' => $rows,
            'summary' => [
                'active' => count(array_filter($rows, static fn ($x) => $x['active'])),
                'monthlyRevenue' => round($monthly, 2),
                'yearlyRevenue' => round($monthly * 12, 2),
            ],
        ]);
    }

    public static function show(Request $r): Response
    {
        $rec = RecurringService::load($r->param('id'));
        $rec['client'] = Db::find('Client', $rec['clientId']);
        $rec = self::decorate($rec, $rec['items']);
        $rec['invoices'] = Db::all('SELECT "id", "number", "issueDate", "status" FROM "Invoice" WHERE "recurringId" = ? ORDER BY "createdAt" DESC LIMIT 20', [$rec['id']]);
        return Response::json($rec);
    }

    public static function create(Request $r): Response
    {
        $data = Validator::validate($r->body(), self::SCHEMA);
        $items = $data['items'];
        unset($data['items']);
        self::checkDates($data);

        $id = (string) Db::transaction(static function () use ($data, $items) {
            $id = Db::insert('Recurring', $data + ['nextRunDate' => $data['startDate'], 'occurrence' => 0]);
            self::insertItems($id, $items);
            return $id;
        });

        Activity::log('RECURRING_CREATED', "Abo „{$data['title']}“ wurde angelegt", $data['clientId'], $data['projectId'] ?? null, $r->user['id']);
        $r->params['id'] = $id;
        return Response::json(self::show($r)->jsonData(), 201);
    }

    public static function update(Request $r): Response
    {
        $id = $r->param('id');
        $data = Validator::validate($r->body(), self::SCHEMA, partial: true);
        $items = $data['items'] ?? null;
        unset($data['items']);

        Db::transaction(static function () use ($id, $data, $items) {
            $current = Db::require('Recurring', $id, 'Abo nicht gefunden');
            $merged = $data + $current;
            self::checkDates($merged);
            if (isset($data['startDate']) || isset($data['intervalUnit'])) {
                $data['nextRunDate'] = RecurringService::iso(
                    RecurringService::scheduleDate($merged['startDate'], $merged['intervalUnit'], (int) $current['occurrence']),
                );
            }
            Db::update('Recurring', $id, $data, 'Abo nicht gefunden');
            if ($items !== null) {
                Db::run('DELETE FROM "RecurringItem" WHERE "recurringId" = ?', [$id]);
                self::insertItems($id, $items);
            }
        });

        return self::show($r);
    }

    public static function delete(Request $r): Response
    {
        Db::delete('Recurring', $r->param('id'), 'Abo nicht gefunden');
        return Response::noContent();
    }

    /** „Jetzt abrechnen“: erzeugt die nächste Rechnung des Abos sofort (als Entwurf) und schaltet den Zeitplan weiter. */
    public static function run(Request $r): Response
    {
        $rec = Db::require('Recurring', $r->param('id'), 'Abo nicht gefunden');
        if (!$rec['active']) {
            throw ApiError::badRequest('Das Abo ist beendet oder pausiert');
        }
        $result = RecurringService::runOne($rec['id'], $r->user['id'], false);

        return Response::json(InvoiceService::detail($result['invoiceId']), 201);
    }

    /** @param list<array<string,mixed>> $items @return array<string,mixed> */
    private static function decorate(array $rec, array $items): array
    {
        $rec['active'] = (bool) $rec['active'];
        $rec['autoSend'] = (bool) $rec['autoSend'];
        $rec['items'] = $items;
        $totals = InvoiceMath::withTotals($rec, $items, [])['totals'];
        $rec['totals'] = $totals;
        $net = $totals['subtotal'] - (float) $rec['discount'];
        $rec['monthlyNet'] = round($net / RecurringService::MONTHS[$rec['intervalUnit']], 2);
        return $rec;
    }

    /** @param array<string,mixed> $data */
    private static function checkDates(array $data): void
    {
        if (!empty($data['endDate']) && !empty($data['startDate']) && $data['endDate'] < $data['startDate']) {
            throw ApiError::badRequest('Das Enddatum liegt vor dem Startdatum');
        }
    }

    /** @param list<array<string,mixed>> $items */
    private static function insertItems(string $recurringId, array $items): void
    {
        foreach ($items as $index => $item) {
            Db::insert('RecurringItem', $item + ['position' => $index, 'recurringId' => $recurringId]);
        }
    }
}
