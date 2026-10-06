<?php

declare(strict_types=1);

// Erzeugt alle fälligen Abo-Rechnungen und sichert die Daten (Backup). Täglich per Cron aufrufen, z. B.:
//   0 6 * * *  php /pfad/zum/projekt/bin/cron.php

require __DIR__ . '/../src/bootstrap.php';

use App\Services\BackupService;
use App\Services\RecurringService;

App\Services\HealthService::touchCron();
App\Services\UpdateService::checkIfDue(); // neue Version nur als Hinweis im Dashboard vormerken

$results = RecurringService::runDue();

if ($results === []) {
    echo "Keine fälligen Abos.\n";
}
foreach ($results as $r) {
    echo sprintf("%s  %s  %s%s\n", $r['number'], $r['title'], $r['sent'] ? 'per E-Mail gesendet' : 'als Entwurf angelegt', $r['error'] ? ' (E-Mail-Fehler: ' . $r['error'] . ')' : '');
}
if ($results !== []) {
    echo count($results) . " Rechnung(en) erzeugt.\n";
}

// Support: gelöste Tickets schließen, lange unbeantwortete „Wartet auf Kunde“-Tickets als gelöst markieren
$tickets = App\Services\TicketService::autoClose();
if ($tickets['closed'] + $tickets['resolved'] > 0) {
    echo "Tickets: {$tickets['closed']} geschlossen, {$tickets['resolved']} als gelöst markiert.\n";
}

// Tägliche Sicherung: nur wenn das letzte Backup älter als BACKUP_INTERVAL_HOURS ist
$status = 0;
try {
    $backup = BackupService::runIfDue();
    echo $backup ? "Backup {$backup['name']} erstellt.\n" : "Backup aktuell – nichts zu tun.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup fehlgeschlagen: ' . $e->getMessage() . "\n");
    $status = 1;
}
exit($status);
