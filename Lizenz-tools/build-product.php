<?php

declare(strict_types=1);

// Baut die auslieferbare, lizenzgeschützte Fassung des Produkts:
//   php Lizenz-tools/build-product.php crm [--server=https://lizenz.deine-domain.de] [--public-key=BASE64 ...] [--key-file=pfad/zu/license.key]
// Ergebnis: Lizenz-tools/crm/ (Ordner) und Lizenz-tools/dist/crm-<version>.zip (zum Weitergeben an Kunden).
// Server-Adresse und öffentlicher Schlüssel werden in Lizenz-tools/vendor.json gemerkt (beides ist nicht geheim).
// --draft baut ohne Server/Schlüssel (Platzhalter), z. B. zum Ansehen oder Testen.
// --no-license baut eine Fassung OHNE Lizenzpflicht (z. B. die erste Beta): sie läuft ohne Schlüssel, kann sich aber über den Update-Server
//   (öffentliche Releases) aktualisieren. Server und öffentlicher Schlüssel sind dafür trotzdem nötig, sonst gibt es keine Updates.

require __DIR__ . '/lib.php';

$args = parseArgs($argv);
$product = (string) ($args['_'][0] ?? 'crm');
if (!preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $product)) {
    fail('Ungültige Produkt-Kennung.');
}

$vendor = vendorConfig();
if (is_string($args['server'] ?? null)) {
    $vendor['server'] = rtrim((string) $args['server'], '/');
}
if (is_string($args['public-key'] ?? null)) {
    $vendor['publicKeys'] = [(string) $args['public-key']];
}
if (is_string($args['key-file'] ?? null)) {
    // Öffentlichen Schlüssel aus der geheimen Datei ableiten; der geheime Teil wird nirgends gespeichert
    $secret = base64_decode(trim((string) @file_get_contents((string) $args['key-file'])), true);
    if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        fail('Die Schlüsseldatei ist ungültig.');
    }
    $vendor['publicKeys'] = [base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret))];
}
$draft = isset($args['draft']);
$noLicense = isset($args['no-license']);
if ($draft && $vendor['server'] === '') {
    $vendor['server'] = 'https://lizenz.example.com';
}
if ($noLicense && !$draft && $vendor['server'] === '') {
    fwrite(STDERR, "Hinweis: Ohne --server und --public-key kann diese Fassung später keine Updates laden. Du kannst sie trotzdem bauen und product.json später ergänzen.\n");
} elseif (!$draft && !preg_match('#^https://[^\s/]+#', (string) $vendor['server']) && !str_starts_with((string) $vendor['server'], 'http://127.0.0.1')) {
    fail('Bitte die Adresse deines Lizenzservers angeben: --server=https://lizenz.deine-domain.de');
}
if (!$draft && !($noLicense && $vendor['server'] === '') && $vendor['publicKeys'] === []) {
    fail('Bitte den öffentlichen Schlüssel angeben: --public-key=… (zu finden unter https://dein-server/api/license/public-key) oder --key-file=…');
}
if (!$draft && $vendor['server'] !== '') {
    file_put_contents(TOOLS_ROOT . '/vendor.json', json_encode($vendor, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

$version = currentVersion();
$out = TOOLS_ROOT . '/' . $product;
removeTree($out);
mkdir($out, 0775, true);

$files = array_merge(codeFiles(false), ['database/.htaccess']);
$files = array_values(array_unique($files));
sort($files);
foreach ($files as $rel) {
    @mkdir(dirname("$out/$rel"), 0775, true);
    copy(projectRoot() . '/' . $rel, "$out/$rel");
}
// Mitgelieferte Schutzdateien für Ordner, die zur Laufzeit entstehen
foreach (['uploads', 'backups'] as $d) {
    @mkdir("$out/$d", 0775, true);
    copy(projectRoot() . '/database/.htaccess', "$out/$d/.htaccess");
}
foreach (['src', 'bin', 'examples'] as $d) {
    if (!is_file("$out/$d/.htaccess") && is_file(projectRoot() . "/$d/.htaccess")) {
        copy(projectRoot() . "/$d/.htaccess", "$out/$d/.htaccess");
    }
}

// Liste der Dateien, die Updates verwalten dürfen (ohne Installer und Schutzdateien außerhalb der Update-Ordner)
$updateFiles = codeFiles(true);
file_put_contents("$out/manifest.json", json_encode(buildManifest($product, $version, $updateFiles), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents("$out/product.json", json_encode([
    'product' => $product, 'name' => 'Webdesigner CRM', 'server' => $vendor['server'], 'publicKeys' => $vendor['publicKeys'],
    'enforce' => !$noLicense, 'builtAt' => gmdate('Y-m-d\TH:i:s\Z'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
$licenseLine = $noLicense ? '' : ' Dein Lizenzschlüssel wird dort abgefragt.';
$kind = $noLicense ? ' (ohne Lizenz)' : '';
file_put_contents("$out/INSTALL.txt", <<<TXT
Webdesigner CRM $version$kind – Installation

1. Diesen Ordnerinhalt auf deinen Webspace hochladen (am besten in eine eigene Subdomain, z. B. crm.deine-domain.de, mit https).
2. https://deine-domain/install.php im Browser öffnen und den Anweisungen folgen.{$licenseLine}
3. Danach install.php löschen.

Updates: im Programm unter Einstellungen → Version & Updates (bzw. Lizenz & Updates) oder per Kommandozeile: php bin/update.php --check | --install
Verpasste Versionen werden automatisch in einem Schritt nachgeholt.
Rechtstexte (Impressum, Datenschutz, AGB): im Admin unter System → Rechtstexte.

TXT);

$dist = TOOLS_ROOT . '/dist';
@mkdir($dist, 0775, true);
$zipPath = "$dist/$product-$version" . ($noLicense ? '-ohne-lizenz' : '') . '.zip';
@unlink($zipPath);
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile()) {
        $zip->addFile($f->getPathname(), $product . '/' . substr($f->getPathname(), strlen($out) + 1));
    }
}
$zip->close();

echo "Produkt gebaut: Lizenz-tools/$product/ (" . count($files) . " Dateien) und Lizenz-tools/dist/" . basename($zipPath) . "\n";
echo "Lizenzserver: {$vendor['server']}\n";
if ($draft) {
    echo "ENTWURF: Server und öffentlicher Schlüssel sind Platzhalter. Vor der Auslieferung neu bauen mit:\n  php Lizenz-tools/build-product.php crm --server=https://lizenz.deine-domain.de --key-file=pfad/zu/license.key\n";
}
