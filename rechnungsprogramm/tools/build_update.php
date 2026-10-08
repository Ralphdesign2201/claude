<?php
declare(strict_types=1);
/**
 * Erzeugt die kumulative, signierte Update-Datei:  php tools/build_update.php <version>
 * - Version muss in tools/changelog.php stehen und mit APP_VERSION in app/version.php übereinstimmen.
 * - Speichert den Dateistand als tools/releases/<version>.json und baut updates/update-<version>.rgu
 *   mit ALLEN Schritten seit 1.0 (der Kunde installiert nur, was ihm fehlt).
 */
require __DIR__ . '/lib.php';
$ver = $argv[1] ?? '';
$root = tl_root();
$log = require __DIR__ . '/changelog.php';
if ($ver === '' || !isset($log[$ver])) { fwrite(STDERR, "Version fehlt oder nicht in tools/changelog.php: '$ver'\n"); exit(1); }
if (!preg_match("/const APP_VERSION = '([^']+)'/", (string)file_get_contents("$root/app/version.php"), $mm) || $mm[1] !== $ver) { fwrite(STDERR, "app/version.php hat Version " . ($mm[1] ?? '?') . ", erwartet $ver\n"); exit(1); }
$keyFile = __DIR__ . '/keys/update-signing.private';
if (!is_file($keyFile)) { fwrite(STDERR, "Signaturschlüssel fehlt: $keyFile\n"); exit(1); }
$secret = base64_decode(trim((string)file_get_contents($keyFile)), true);

// Manifest dieser Version speichern
$cur = tl_manifest();
if (!is_dir(__DIR__ . '/releases')) mkdir(__DIR__ . '/releases', 0775, true);
file_put_contents(__DIR__ . "/releases/$ver.json", json_encode($cur, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

// alle Manifeste bis einschließlich $ver
$manifests = [];
foreach (glob(__DIR__ . '/releases/*.json') as $f) { $v = basename($f, '.json'); if (version_compare($v, $ver, '<=')) $manifests[$v] = json_decode((string)file_get_contents($f), true); }
uksort($manifests, 'version_compare');
$steps = []; $prevM = []; $prevV = '0';
foreach ($manifests as $v => $m) {
    if (!isset($log[$v])) { fwrite(STDERR, "Changelog-Eintrag für $v fehlt\n"); exit(1); }
    $changed = array_keys(array_filter($m, fn($h, $p) => ($prevM[$p] ?? null) !== $h, ARRAY_FILTER_USE_BOTH));
    $deleted = array_values(array_diff(array_keys($prevM), array_keys($m)));
    $steps[] = ['version' => $v, 'date' => $log[$v]['date'], 'notes' => $log[$v]['notes'], 'paths' => $changed, 'deleted' => $deleted, 'migrations' => $log[$v]['migrations'] ?? []];
    $prevM = $m;
}
$blobs = [];
foreach ($steps as $s) foreach ($s['paths'] as $p) if (isset($cur[$p]) && !isset($blobs[$p])) $blobs[$p] = base64_encode((string)file_get_contents("$root/$p"));
$payload = json_encode(['format' => 'rechnungsprogramm-update', 'product' => 'HandwerkRechnung', 'prev' => '0', 'version' => $ver, 'built' => date('c'), 'steps' => $steps, 'blobs' => $blobs], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$sig = base64_encode(sodium_crypto_sign_detached($payload, $secret));
$out = gzencode(json_encode(['payload' => $payload, 'sig' => $sig]), 9);
if (!is_dir("$root/updates")) mkdir("$root/updates", 0775, true);
foreach (glob("$root/updates/update-*.rgu") as $old) unlink($old);
file_put_contents("$root/updates/update-$ver.rgu", $out);

// Änderungsprotokoll als Text
$md = "# Änderungsprotokoll\n\nAktuelle Update-Datei: `update-$ver.rgu` (kumulativ ab Version " . $steps[0]['version'] . ").\n\n";
foreach (array_reverse($steps) as $s) { $md .= "## Version {$s['version']} ({$s['date']})\n"; foreach ($s['notes'] as $n) $md .= "- $n\n"; $md .= "\n"; }
file_put_contents("$root/updates/CHANGELOG.md", $md);
printf("update-%s.rgu: %d Schritte, %d Dateien, %.1f KB\n", $ver, count($steps), count($blobs), strlen($out) / 1024);
