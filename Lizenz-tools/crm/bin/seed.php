<?php

declare(strict_types=1);

// Legt einen Demo-Admin, einen Beispielkunden, ein Projekt, Aufgaben und eine Rechnung an (idempotent).

require __DIR__ . '/../src/bootstrap.php';

use App\Support\Db;

$password = getenv('SEED_ADMIN_PASSWORD') ?: 'admin1234';

Db::transaction(static function () use ($password) {
    $admin = Db::one('SELECT "id" FROM "User" WHERE "email" = ?', ['admin@example.com']);
    $adminId = $admin['id'] ?? Db::insert('User', [
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'passwordHash' => password_hash($password, PASSWORD_BCRYPT),
        'role' => 'ADMIN',
    ]);

    if (!Db::find('Client', 'seed-client-1')) {
        Db::insert('Client', [
            'id' => 'seed-client-1',
            'name' => 'Anna Beispiel',
            'company' => 'Beispiel GmbH',
            'email' => 'anna@beispiel.de',
            'phone' => '+49 170 1234567',
            'website' => 'https://beispiel.de',
            'city' => 'Berlin',
            'country' => 'Deutschland',
            'status' => 'ACTIVE',
            'ownerId' => $adminId,
            'tags' => 'webdesign,stammkunde',
        ]);
    }

    if (!Db::find('Project', 'seed-project-1')) {
        Db::insert('Project', [
            'id' => 'seed-project-1',
            'clientId' => 'seed-client-1',
            'ownerId' => $adminId,
            'name' => 'Website Relaunch',
            'description' => 'Kompletter Relaunch der Firmenwebsite inkl. CMS',
            'status' => 'IN_PROGRESS',
            'budget' => 6500,
            'hourlyRate' => 90,
        ]);
        foreach ([['Wireframes erstellen', 'DONE', 'HIGH'], ['Design-Konzept', 'IN_PROGRESS', 'HIGH'], ['CMS Integration', 'OPEN', 'MEDIUM']] as $i => [$title, $status, $priority]) {
            Db::insert('Task', ['projectId' => 'seed-project-1', 'title' => $title, 'status' => $status, 'priority' => $priority, 'position' => $i]);
        }
    }

    $number = 'RE-' . gmdate('Y') . '-0001';
    if (!Db::one('SELECT 1 FROM "Invoice" WHERE "number" = ?', [$number])) {
        $invoiceId = Db::insert('Invoice', [
            'number' => $number,
            'clientId' => 'seed-client-1',
            'projectId' => 'seed-project-1',
            'status' => 'SENT',
            'taxRate' => 19,
        ]);
        Db::insert('InvoiceItem', ['invoiceId' => $invoiceId, 'description' => 'Konzeption & Design', 'quantity' => 1, 'unitPrice' => 2500, 'position' => 0]);
        Db::insert('InvoiceItem', ['invoiceId' => $invoiceId, 'description' => 'Entwicklung', 'quantity' => 20, 'unitPrice' => 90, 'position' => 1]);
    }
});

echo "Seed abgeschlossen. Login: admin@example.com / $password\n";
