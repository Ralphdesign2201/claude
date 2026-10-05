<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Support\Dates;
use App\Support\Db;
use App\Support\InvoiceMath;

final class DashboardController
{
    public static function summary(Request $r): Response
    {
        $byStatus = [];
        foreach (Db::all('SELECT "status", COUNT(*) AS n FROM "Client" GROUP BY "status"') as $row) {
            $byStatus[$row['status']] = (int) $row['n'];
        }

        $invoices = Db::all(
            'SELECT i.status, i.taxRate, i.discount, i.dueDate,
                    COALESCE((SELECT SUM(ROUND(quantity * unitPrice, 2)) FROM "InvoiceItem" WHERE invoiceId = i.id), 0) AS subtotal,
                    COALESCE((SELECT SUM(amount) FROM "Payment" WHERE invoiceId = i.id), 0) AS paid
             FROM "Invoice" i',
        );

        $now = Dates::now();
        $revenuePaid = $outstanding = $overdue = 0.0;
        foreach ($invoices as $inv) {
            $total = InvoiceMath::totals((float) $inv['subtotal'], $inv['taxRate'], $inv['discount'])['total'];
            $revenuePaid += $inv['paid'];
            $balance = $total - $inv['paid'];
            if ($balance > 0 && $inv['status'] !== 'CANCELLED') {
                $outstanding += $balance;
                if ($inv['dueDate'] !== null && $inv['dueDate'] < $now) {
                    $overdue += $balance;
                }
            }
        }

        $upcoming = Db::all(
            'SELECT t.*, p.id AS project__id, p.name AS project__name, p.clientId AS project__clientId
             FROM "Task" t JOIN "Project" p ON p.id = t.projectId
             WHERE t.status != \'DONE\' AND t.dueDate IS NOT NULL
             ORDER BY t.dueDate ASC LIMIT 8',
        );
        $activities = Db::all(
            'SELECT a.*, c.id AS client__id, c.name AS client__name,
                    p.id AS project__id, p.name AS project__name,
                    u.id AS user__id, u.name AS user__name
             FROM "Activity" a
             LEFT JOIN "Client" c ON c.id = a.clientId
             LEFT JOIN "Project" p ON p.id = a.projectId
             LEFT JOIN "User" u ON u.id = a.userId
             ORDER BY a.createdAt DESC LIMIT 15',
        );

        return Response::json([
            'clients' => [
                'total' => (int) Db::value('SELECT COUNT(*) FROM "Client"'),
                'byStatus' => (object) $byStatus,
            ],
            'projects' => [
                'active' => (int) Db::value('SELECT COUNT(*) FROM "Project" WHERE "status" IN (\'PLANNED\', \'IN_PROGRESS\', \'REVIEW\')'),
            ],
            'tasks' => [
                'open' => (int) Db::value('SELECT COUNT(*) FROM "Task" WHERE "status" != \'DONE\''),
                'upcoming' => $upcoming,
            ],
            'revenue' => [
                'paid' => round($revenuePaid, 2),
                'outstanding' => round($outstanding, 2),
                'overdue' => round($overdue, 2),
            ],
            'activities' => $activities,
        ]);
    }
}
