<?php

declare(strict_types=1);

// Updates der lizenzierten Software:
//   php bin/update.php --check            nach einer neuen Version fragen
//   php bin/update.php --install          neueste Version laden, prüfen und einspielen (mit Sicherung und Rücknahme bei Fehlern)
//   php bin/update.php --rollback=<Datei> Code aus einer Sicherung (backups/code-vor-update-….zip) zurückspielen

require __DIR__ . '/../src/bootstrap.php';

use App\Services\UpdateService;
use App\Support\Product;

$opts = getopt('', ['check', 'install', 'rollback:']);
try {
    if (isset($opts['rollback'])) {
        echo UpdateService::rollback((string) $opts['rollback']) . " Datei(en) zurückgespielt.\n";
    } elseif (isset($opts['install'])) {
        $r = UpdateService::install();
        echo "Update {$r['from']} → {$r['to']} eingespielt ({$r['files']} Dateien, " . count($r['migrations']) . " Migration(en)). Sicherung: backups/{$r['backup']}\n";
    } elseif (isset($opts['check'])) {
        $r = UpdateService::check();
        echo 'Installiert: ' . $r['current'] . "\n";
        echo $r['latest'] === null ? "Es gibt kein neueres Update.\n" : 'Neu: ' . $r['latest']['version'] . ($r['entitled'] ? " (für deine Lizenz verfügbar)\n" : " (nicht im Update-Zeitraum deiner Lizenz)\n");
    } else {
        echo 'Webdesigner CRM ' . Product::version() . "\nAufruf: php bin/update.php --check | --install | --rollback=<Datei>\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
