<?php
declare(strict_types=1);

function dashboard_index(): void {
    $pdo = db(); $today = date('Y-m-d');
    $one = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetch(PDO::FETCH_NUM); })();
    [$openCnt, $openSum] = $one("SELECT COUNT(*), COALESCE(SUM(gross_amount),0) FROM invoices WHERE status='open'");
    [$odCnt, $odSum] = $one("SELECT COUNT(*), COALESCE(SUM(gross_amount),0) FROM invoices WHERE status='open' AND due_date < ?", [$today]);
    [$monthSum] = $one("SELECT COALESCE(SUM(gross_amount),0) FROM invoices WHERE status='paid' AND substr(paid_date,1,7) = ?", [date('Y-m')]);
    [$yearNet] = $one("SELECT COALESCE(SUM(net_amount),0) FROM invoices WHERE status != 'cancelled' AND substr(invoice_date,1,4) = ?", [date('Y')]);
    [$custCnt] = $one('SELECT COUNT(*) FROM customers');
    $recent = $pdo->query("SELECT i.*, c.company, c.firstname, c.lastname FROM invoices i JOIN customers c ON c.id = i.customer_id ORDER BY i.invoice_date DESC, i.id DESC LIMIT 8")->fetchAll();
    $overdue = $pdo->prepare("SELECT i.*, c.company, c.firstname, c.lastname FROM invoices i JOIN customers c ON c.id = i.customer_id WHERE i.status='open' AND i.due_date < ? ORDER BY i.due_date LIMIT 8");
    $overdue->execute([$today]);
    render('dashboard', compact('openCnt', 'openSum', 'odCnt', 'odSum', 'monthSum', 'yearNet', 'custCnt', 'recent') + ['overdueList' => $overdue->fetchAll(), 'setupMissing' => setting('company') === ''], 'Übersicht');
}
