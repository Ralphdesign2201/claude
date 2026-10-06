<?php

declare(strict_types=1);

// Baut ein Update-Paket (ZIP) aus dem aktuellen Projekt:
//   php Lizenz-tools/build-release.php crm [--version=1.2.0]
// Ergebnis: Lizenz-tools/releases/crm-<version>.zip  – dieses Paket im Admin-Bereich unter Lizenzen → Releases hochladen.
// Der Server prüft es und signiert es mit deinem geheimen Lizenzschlüssel (der verlässt den Server nie).

require __DIR__ . '/lib.php';

$args = parseArgs($argv);
$product = (string) ($args['_'][0] ?? 'crm');
if (!preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $product)) {
    fail('Ungültige Produkt-Kennung (nur Kleinbuchstaben, Ziffern, Bindestrich).');
}
$version = is_string($args['version'] ?? null) ? $args['version'] : currentVersion();
if (!validVersion($version)) {
    fail("Ungültige Versionsnummer: $version (erwartet z. B. 1.2.0)");
}
$files = codeFiles(true);
$overrides = ['VERSION' => $version . "\n"];
$manifest = buildManifest($product, $version, $files, $overrides);

$dir = TOOLS_ROOT . '/releases';
@mkdir($dir, 0775, true);
$zipPath = "$dir/$product-$version.zip";
@unlink($zipPath);
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    fail('ZIP konnte nicht angelegt werden.');
}
foreach ($files as $rel) {
    isset($overrides[$rel]) ? $zip->addFromString($rel, $overrides[$rel]) : $zip->addFile(projectRoot() . '/' . $rel, $rel);
}
$zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$zip->close();

echo "Release gebaut: Lizenz-tools/releases/$product-$version.zip\n";
echo '  ' . count($files) . ' Dateien, ' . round(filesize($zipPath) / 1024) . " KB, SHA-256 " . hash_file('sha256', $zipPath) . "\n";
echo "Nächster Schritt: im Admin-Bereich unter Lizenzen → Releases hochladen und veröffentlichen.\n";
