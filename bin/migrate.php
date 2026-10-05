<?php

declare(strict_types=1);

// Wendet alle Dateien aus database/migrations/*.sql der Reihe nach an (bereits angewendete werden übersprungen).

require __DIR__ . '/../src/bootstrap.php';

use App\Support\Db;

$pdo = Db::pdo();
$pdo->exec('CREATE TABLE IF NOT EXISTS "_migrations" ("name" TEXT PRIMARY KEY, "appliedAt" TEXT NOT NULL)');

$applied = array_column(Db::all('SELECT "name" FROM "_migrations"'), 'name');
$files = glob(APP_ROOT . '/database/migrations/*.sql') ?: [];
sort($files);

$count = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }
    echo "Migration $name ...\n";
    Db::transaction(static function () use ($pdo, $file, $name) {
        $pdo->exec((string) file_get_contents($file));
        Db::run('INSERT INTO "_migrations" ("name", "appliedAt") VALUES (?, ?)', [$name, App\Support\Dates::now()]);
    });
    $count++;
}

echo $count === 0 ? "Datenbank ist aktuell.\n" : "$count Migration(en) angewendet: " . Db::path() . "\n";
