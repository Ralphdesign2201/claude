<?php

declare(strict_types=1);

// Erstellt ein Backup (Datenbank + Uploads) und räumt alte Sicherungen auf. Täglich per Cron aufrufen, z. B.:
//   30 2 * * *  php /pfad/zum/projekt/bin/backup.php
// Oder einfach bin/cron.php nutzen – das sichert automatisch, wenn das letzte Backup älter als 24 Stunden ist.

require __DIR__ . '/../src/bootstrap.php';

use App\Services\BackupService;

try {
    $b = BackupService::create();
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup fehlgeschlagen: ' . $e->getMessage() . "\n");
    exit(1);
}

printf("Backup %s (%s KB, %d Dokumente%s) erstellt.\n", $b['name'], number_format($b['size'] / 1024, 0, ',', '.'), $b['uploads'], $b['encrypted'] ? ', verschlüsselt' : '');
if ($b['copied']) {
    echo "Kopie in BACKUP_COPY_DIR gespeichert.\n";
}
if ($b['copyError']) {
    fwrite(STDERR, 'Warnung: ' . $b['copyError'] . "\n");
}
if ($b['deleted'] > 0) {
    echo $b['deleted'] . " alte Sicherung(en) gelöscht.\n";
}
