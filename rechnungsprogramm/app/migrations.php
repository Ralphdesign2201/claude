<?php
declare(strict_types=1);

/**
 * Datenbank-Migrationen (zusätzlich zur automatischen Schema-Anlage in db.php).
 * Jede Migration läuft pro Datenbank genau einmal. Neue Einträge nur am Ende anfügen, IDs nie ändern.
 * Beispiel:  '1.1-beispiel' => function (PDO $pdo): void { $pdo->exec('UPDATE ...'); },
 */
function app_migrations(): array {
    return [
    ];
}

function run_pending_migrations(PDO $pdo): array {
    $done = json_decode(db_get($pdo, 'applied_migrations') ?: '[]', true) ?: [];
    $ran = [];
    foreach (app_migrations() as $id => $fn) {
        if (in_array($id, $done, true)) continue;
        $fn($pdo);
        $done[] = $id; $ran[] = $id;
        db_set($pdo, 'applied_migrations', json_encode($done));
    }
    return $ran;
}
