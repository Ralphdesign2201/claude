<?php

declare(strict_types=1);

// Legt einen Beispielkatalog an (Einmalleistungen, Mietprodukte, Zeitleistungen). Alles ist zunächst inaktiv und
// kann in der Oberfläche unter „Produkte“ geprüft, im Preis angepasst und aktiviert werden. Mehrfaches Ausführen ist unschädlich.

require __DIR__ . '/../src/bootstrap.php';

use App\Controllers\CatalogController;

$r = CatalogController::createExamples();
echo "{$r['categories']} Kategorie(n) und {$r['products']} Produkt(e) angelegt (inaktiv).\n";
