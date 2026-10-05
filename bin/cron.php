<?php

declare(strict_types=1);

// Erzeugt alle fälligen Abo-Rechnungen. Täglich per Cron aufrufen, z. B.:
//   0 6 * * *  php /pfad/zum/projekt/bin/cron.php

require __DIR__ . '/../src/bootstrap.php';

use App\Services\RecurringService;

$results = RecurringService::runDue();

if ($results === []) {
    echo "Keine fälligen Abos.\n";
    exit(0);
}
foreach ($results as $r) {
    echo sprintf("%s  %s  %s%s\n", $r['number'], $r['title'], $r['sent'] ? 'per E-Mail gesendet' : 'als Entwurf angelegt', $r['error'] ? ' (E-Mail-Fehler: ' . $r['error'] . ')' : '');
}
echo count($results) . " Rechnung(en) erzeugt.\n";
