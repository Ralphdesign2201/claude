<?php

declare(strict_types=1);

// Wendet alle Dateien aus database/migrations/*.sql der Reihe nach an (bereits angewendete werden übersprungen).
// Funktioniert für SQLite und MySQL/MariaDB (je nach DB_DRIVER bzw. den Einstellungen im Programm).

require __DIR__ . '/../src/bootstrap.php';

use App\Support\Db;
use App\Support\Migrator;

$done = Migrator::run(Db::pdo(), Db::driver(), static fn (string $name) => print("Migration $name ...\n"));
$where = Db::isMysql() ? 'MySQL/MariaDB (' . Db::config()['name'] . ')' : Db::path();
echo $done === [] ? "Datenbank ist aktuell.\n" : count($done) . " Migration(en) angewendet: $where\n";
