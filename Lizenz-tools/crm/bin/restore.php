<?php

declare(strict_types=1);

// Stellt ein Backup wieder her:   php bin/restore.php /pfad/zu/crm-backup-….zip --yes
// Verschlüsselte Backups: Passwort in BACKUP_PASSPHRASE (.env oder Umgebung).
// Die aktuelle Datenbank wird vorher als backups/vor-wiederherstellung-….sqlite gesichert.
// Wichtig: Während der Wiederherstellung sollte niemand mit dem System arbeiten.

require __DIR__ . '/../src/bootstrap.php';

use App\Services\BackupService;
use App\Support\Env;

$args = array_slice($argv, 1);
$confirmed = in_array('--yes', $args, true);
$file = array_values(array_filter($args, static fn ($a) => !str_starts_with($a, '--')))[0] ?? null;

if ($file === null) {
    fwrite(STDERR, "Aufruf: php bin/restore.php <backup.zip> --yes\n");
    exit(2);
}
if (!$confirmed) {
    fwrite(STDERR, "Das ersetzt die aktuelle Datenbank durch den Stand aus dem Backup:\n  $file\n"
        . "Die bisherige Datenbank wird vorher gesichert. Zum Ausführen mit --yes bestätigen.\n");
    exit(1);
}

try {
    $r = BackupService::restore($file, Env::get('BACKUP_PASSPHRASE', '') ?? '');
} catch (Throwable $e) {
    fwrite(STDERR, 'Wiederherstellung fehlgeschlagen: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Datenbank wiederhergestellt: {$r['database']}\n";
echo "{$r['uploads']} Dokument(e) wiederhergestellt.\n";
if ($r['safetyCopy']) {
    echo "Vorheriger Stand gesichert unter: {$r['safetyCopy']}\n";
}
