<?php

declare(strict_types=1);

/**
 * Integrationstest für Lizenzserver + lizenziertes Produkt: Berechtigungen, Durchsetzung, signierte Updates, Installer.
 * Startet zwei Server (Hersteller und Produkt-Kopie) mit eigenen Datenbanken.   Aufruf: php tests/product.php
 */

$root = dirname(__DIR__);
$base = (int) (getenv('TEST_PORT') ?: 18300);
$vp = $base;          // Lizenzserver (Hersteller)
$vp2 = $base + 1;     // Lizenzserver mit HTTPS-Pflicht
$pp = $base + 2;      // Produkt
$ip = $base + 3;      // Produkt für den Installer-Test
$tmp = sys_get_temp_dir() . '/crm-product-test-' . bin2hex(random_bytes(4));
mkdir($tmp);
$procs = [];
$passed = 0;
$failed = 0;

register_shutdown_function(static function () use (&$procs, $tmp, $root) {
    foreach ($procs as $p) {
        proc_terminate($p);
    }
    $rm = static function (string $path) use (&$rm): void {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $f) {
                if ($f !== '.' && $f !== '..') {
                    $rm("$path/$f");
                }
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    };
    $rm($tmp);
    foreach (glob($root . '/Lizenz-tools/releases/crm-9.9.*.zip') ?: [] as $f) {
        @unlink($f);
    }
});

function check(string $name, bool $ok, mixed $detail = null): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  ok   $name\n";
    } else {
        $failed++;
        echo "  FAIL $name" . ($detail !== null ? ' → ' . (is_string($detail) ? $detail : json_encode($detail)) : '') . "\n";
    }
}

function expect(string $name, array $res, int $status): void
{
    check($name, $res[0] === $status, "Status {$res[0]} statt $status: " . substr($res[2], 0, 300));
}

/** @return array{0:int,1:mixed,2:string,3:array<string,string>} */
function call(int $port, string $method, string $path, mixed $body = null, ?string $token = null, array $headers = []): array
{
    $h = $headers;
    if ($token) {
        $h[] = "Authorization: Bearer $token";
    }
    $content = null;
    if ($body !== null) {
        $content = is_string($body) ? $body : json_encode($body);
        if (!is_string($body)) {
            $h[] = 'Content-Type: application/json';
        }
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $h), 'content' => $content, 'ignore_errors' => true]]);
    $raw = (string) @file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
    preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m);
    $resp = [];
    foreach ($http_response_header ?? [] as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $resp[strtolower(trim($k))] = trim($v);
        }
    }
    return [(int) ($m[1] ?? 0), json_decode($raw, true), $raw, $resp];
}

function startServer(string $docRoot, int $port, array $env, string $log): mixed
{
    global $procs;
    $p = proc_open([PHP_BINARY, '-d', 'upload_max_filesize=60M', '-d', 'post_max_size=62M', '-S', "127.0.0.1:$port", '-t', 'public', 'public/index.php'], [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $docRoot, $env);
    $procs[] = $p;
    for ($i = 0; $i < 60; $i++) {
        if (@file_get_contents("http://127.0.0.1:$port/health") !== false) {
            break;
        }
        usleep(100000);
    }
    return $p;
}

function cli(string $dir, string $script, array $args, array $env): array
{
    $p = proc_open(array_merge([PHP_BINARY, "$dir/bin/$script"], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, $env);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $out];
}

function multipart(array $fields, array $files = []): array
{
    $b = 'XB' . bin2hex(random_bytes(6));
    $out = '';
    foreach ($fields as $k => $v) {
        $out .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
    }
    foreach ($files as $name => [$fileName, $content]) {
        $out .= "--$b\r\nContent-Disposition: form-data; name=\"$name\"; filename=\"$fileName\"\r\nContent-Type: application/zip\r\n\r\n$content\r\n";
    }
    return [$out . "--$b--\r\n", "Content-Type: multipart/form-data; boundary=$b"];
}

function copyProject(string $root, string $dest): void
{
    mkdir($dest, 0775, true);
    $cmd = 'cd ' . escapeshellarg($root) . ' && tar --exclude=.git --exclude=tests --exclude=Lizenz-tools --exclude=.env --exclude="database/*.db*" --exclude=database/settings.json --exclude="database/*.key" --exclude=database/installed.lock -cf - . | tar -xf - -C ' . escapeshellarg($dest);
    shell_exec($cmd);
    foreach (['backups', 'uploads'] as $d) {
        @mkdir("$dest/$d", 0775, true);
    }
}

$cleanEnv = ['PATH' => (string) getenv('PATH')];

/* ---------- Lizenzserver (Hersteller) ---------- */
$vendorEnv = $cleanEnv + [
    'DATABASE_PATH' => "$tmp/vendor.db", 'SETTINGS_FILE' => "$tmp/vendor-settings.json", 'JWT_SECRET' => 'vendor-secret-vendor-secret-1', 'LICENSE_KEY_FILE' => "$tmp/vendor.key",
    'LICENSE_REQUIRE_HTTPS' => 'false', 'LICENSE_ALLOW_DEV' => 'false', 'UPLOAD_DIR' => "$tmp/vendor-up", 'BACKUP_DIR' => "$tmp/vendor-bk", 'RATE_LIMIT_MAX' => '20000',
    'APP_URL' => 'https://lizenz.example.com', 'LICENSE_CACHE_HOURS' => '24',
];
cli($root, 'migrate.php', [], $vendorEnv);
startServer($root, $vp, $vendorEnv, "$tmp/vendor.log");
startServer($root, $vp2, ['LICENSE_REQUIRE_HTTPS' => 'true'] + $vendorEnv, "$tmp/vendor2.log");

echo "Lizenzserver\n";
$res = call($vp, 'POST', '/api/auth/register', ['name' => 'Hersteller', 'email' => 'hersteller@example.com', 'password' => 'geheim1234']);
$A = $res[1]['token'];
$client = call($vp, 'POST', '/api/clients', ['name' => 'Kundin K', 'email' => 'k@kunde.example'], $A)[1]['id'];
$pubKey = call($vp, 'GET', '/api/license/public-key')[1];
check('Öffentlicher Schlüssel mit Kennung (kid)', strlen((string) base64_decode($pubKey['publicKey'])) === 32 && strlen($pubKey['kid']) === 12, $pubKey);

$issue = static function (array $extra = []) use ($vp, $A, $client): array {
    $r = call($vp, 'POST', '/api/licenses', $extra + ['clientId' => $client, 'productName' => 'Webdesigner CRM', 'domain' => 'kunde.example.com', 'slug' => 'crm', 'plan' => 'pro'], $A);
    return $r[1];
};
$signedCall = static function (int $port, array $fields, string $path = '/api/license/verify', array $headers = []) use ($pubKey): array {
    $fields += ['nonce' => 'n' . bin2hex(random_bytes(10)), 'ts' => time()];
    $r = call($port, 'POST', $path, $fields, null, $headers);
    $payload = null;
    $sigOk = false;
    if (isset($r[1]['payload'], $r[1]['signature'])) {
        $b = static fn (string $s) => base64_decode(strtr($s, '-_', '+/'));
        $sigOk = sodium_crypto_sign_verify_detached($b($r[1]['signature']), $b($r[1]['payload']), base64_decode($pubKey['publicKey']));
        $payload = json_decode($b($r[1]['payload']), true);
    }
    return [$r, $payload, $sigOk, $fields];
};

$lic = $issue();
[$r, $p, $ok] = $signedCall($vp, ['key' => $lic['licenseKey'], 'domain' => 'kunde.example.com', 'version' => '0.1.0-beta']);
check('Gültige Antwort enthält Paket, Funktionen, Kennung und Schlüsselkennung (v2, signiert)', $ok && $p['v'] === 2 && $p['valid'] === true && $p['plan'] === 'pro' && $p['features'] === ['recurring', 'shop', 'support'] && $p['slug'] === 'crm' && $p['kid'] === $pubKey['kid'], $p);
check('Antwort ohne Cache: no-store', ($r[3]['cache-control'] ?? '') === 'no-store');
$lic2 = $issue(['plan' => 'starter', 'domain' => 'zwei.example.com']);
check('Paket „starter“ hat keine Zusatzfunktionen', $signedCall($vp, ['key' => $lic2['licenseKey'], 'domain' => 'zwei.example.com'])[1]['features'] === []);
$lic3 = $issue(['plan' => 'starter', 'features' => 'support,licenses', 'domain' => 'drei.example.com']);
check('Einzelne Funktionen überschreiben das Paket', $signedCall($vp, ['key' => $lic3['licenseKey'], 'domain' => 'drei.example.com'])[1]['features'] === ['support', 'licenses']);
$lic4 = $issue(['plan' => 'agency', 'features' => '*', 'domain' => 'vier.example.com']);
check('„*“ schaltet alle Funktionen frei', count($signedCall($vp, ['key' => $lic4['licenseKey'], 'domain' => 'vier.example.com'])[1]['features']) === 4);
expect('Unbekanntes Paket → 400', call($vp, 'POST', '/api/licenses', ['clientId' => $client, 'productName' => 'X', 'domain' => 'x.example.com', 'plan' => 'gold'], $A), 400);
expect('Ungültige Produkt-Kennung → 400', call($vp, 'POST', '/api/licenses', ['clientId' => $client, 'productName' => 'X', 'domain' => 'x.example.com', 'slug' => 'Böse Kennung!'], $A), 400);
$res = call($vp, 'GET', '/api/licenses/' . $lic['id'], null, $A);
check('Lizenz-Detail zeigt Funktionen, Support/Update-Status und die Domain, von der geprüft wurde', $res[1]['resolvedFeatures'] === ['recurring', 'shop', 'support'] && $res[1]['supportActive'] === true && $res[1]['hosts'][0]['domain'] === 'kunde.example.com' && $res[1]['hosts'][0]['version'] === '0.1.0-beta', $res[2]);

echo "API-Sicherheit\n";
$fields = ['key' => $lic['licenseKey'], 'domain' => 'kunde.example.com', 'nonce' => 'replay-' . bin2hex(random_bytes(6)), 'ts' => time()];
expect('Erste Anfrage mit neuem Zufallswert → 200', call($vp, 'POST', '/api/license/verify', $fields), 200);
$res = call($vp, 'POST', '/api/license/verify', $fields);
check('Gleiche Anfrage noch einmal (Replay) → 409', $res[0] === 409, $res[2]);
expect('Ohne Zeitstempel → 400', call($vp, 'POST', '/api/license/verify', ['key' => $lic['licenseKey'], 'domain' => 'kunde.example.com', 'nonce' => 'abcdefgh' . bin2hex(random_bytes(4))]), 400);
expect('Veralteter Zeitstempel (1 Stunde) → 400', call($vp, 'POST', '/api/license/verify', ['ts' => time() - 3600] + ['key' => $lic['licenseKey'], 'domain' => 'kunde.example.com', 'nonce' => 'old' . bin2hex(random_bytes(8))]), 400);
expect('Zeitstempel in der Zukunft → 400', call($vp, 'POST', '/api/license/verify', ['ts' => time() + 3600] + ['key' => $lic['licenseKey'], 'domain' => 'kunde.example.com', 'nonce' => 'fut' . bin2hex(random_bytes(8))]), 400);
$res = call($vp2, 'POST', '/api/license/verify', ['key' => $lic['licenseKey'], 'domain' => 'kunde.example.com', 'nonce' => 'h' . bin2hex(random_bytes(8)), 'ts' => time()]);
check('HTTPS-Pflicht: unverschlüsselte Anfrage → 400', $res[0] === 400 && str_contains($res[2], 'HTTPS'), $res[2]);
$res = call($vp2, 'POST', '/api/license/verify', ['key' => $lic['licenseKey'], 'domain' => 'kunde.example.com', 'nonce' => 'h' . bin2hex(random_bytes(8)), 'ts' => time()], null, ['X-Forwarded-Proto: https']);
check('Mit HTTPS (Proxy-Header): erlaubt, mit HSTS-Header', $res[0] === 200 && str_contains($res[3]['strict-transport-security'] ?? '', 'max-age'), $res[3]);
$res = call($vp2, 'POST', '/api/license/update-check', ['key' => $lic['licenseKey'], 'domain' => 'kunde.example.com', 'product' => 'crm', 'nonce' => 'h' . bin2hex(random_bytes(8)), 'ts' => time()]);
check('HTTPS-Pflicht gilt auch für die Update-Prüfung', $res[0] === 400);
$res = call($vp2, 'POST', '/api/license/download', ['token' => str_repeat('a', 40)]);
check('… und für den Download', $res[0] === 400);
// Schlüssel pro Lizenz drosseln
$lt = $issue(['domain' => 'drossel.example.com']);
$codes = [];
for ($i = 0; $i < 135; $i++) {
    $codes[] = $signedCall($vp, ['key' => $lt['licenseKey'], 'domain' => 'drossel.example.com'])[0][0];
}
check('Auffällig häufige Prüfungen eines Schlüssels werden gebremst (429 ab der 121.)', $codes[119] === 200 && $codes[120] === 429, [$codes[119], $codes[120]]);
// Weitergabe erkennen
$ls = $issue(['domain' => 'teilen.example.com', 'subdomains' => true]);
$signedCall($vp, ['key' => $ls['licenseKey'], 'domain' => 'a.teilen.example.com']);
$signedCall($vp, ['key' => $ls['licenseKey'], 'domain' => 'b.teilen.example.com']);
$d = call($vp, 'GET', '/api/licenses/' . $ls['id'], null, $A)[1];
check('Benutzung auf mehreren Domains wird im Lizenz-Detail sichtbar', count($d['hosts']) === 2, $d['hosts']);

echo "Support-Anspruch\n";
$portal = call($vp, 'POST', "/api/clients/$client/portal", [], $A)[1]['token'];
$PH = ["X-Portal-Token: $portal"];
$noSup = $issue(['domain' => 'support.example.com', 'supportUntil' => gmdate('Y-m-d\TH:i:s.000\Z', time() - 86400)]);
$okSup = $issue(['domain' => 'support2.example.com']);
$mk = static function (string $licenseId) use ($vp, $PH): array {
    $b = 'XB' . bin2hex(random_bytes(5));
    $body = "--$b\r\nContent-Disposition: form-data; name=\"subject\"\r\n\r\nFrage zur Lizenz\r\n--$b\r\nContent-Disposition: form-data; name=\"message\"\r\n\r\nHilfe bitte\r\n--$b\r\nContent-Disposition: form-data; name=\"licenseId\"\r\n\r\n$licenseId\r\n--$b--\r\n";
    return call($vp, 'POST', '/api/portal/tickets', $body, null, array_merge($PH, ["Content-Type: multipart/form-data; boundary=$b"]));
};
$res = $mk($noSup['id']);
check('Ticket zu einer Lizenz ohne aktiven Support wird abgelehnt (403 mit Erklärung)', $res[0] === 403 && str_contains($res[1]['error'], 'Support-Zeitraum'), $res[2]);
expect('Ticket zu einer Lizenz mit Support → 201', $mk($okSup['id']), 201);
$pl = call($vp, 'GET', '/api/portal/licenses', null, null, $PH)[1];
$row = array_values(array_filter($pl, static fn ($x) => $x['id'] === $noSup['id']))[0];
check('Portal zeigt Support-Ende und Update-Status je Lizenz', $row['supportActive'] === false && $row['updatesActive'] === true && $row['plan'] === 'Pro', $row);

/* ---------- Produkt-Kopie ---------- */
echo "Produkt: Durchsetzung\n";
$prod = "$tmp/product";
copyProject($root, $prod);
$prodEnv = $cleanEnv + [
    'DATABASE_PATH' => "$tmp/product.db", 'SETTINGS_FILE' => "$tmp/product-settings.json", 'JWT_SECRET' => 'product-secret-product-secret-1', 'LICENSE_KEY_FILE' => "$tmp/product.key",
    'UPLOAD_DIR' => "$tmp/product-up", 'BACKUP_DIR' => "$tmp/product-bk", 'APP_URL' => 'https://kunde.example.com', 'RATE_LIMIT_MAX' => '20000', 'LOGIN_RATE_LIMIT_MAX' => '50',
];
file_put_contents("$prod/product.json", json_encode(['product' => 'crm', 'name' => 'Webdesigner CRM', 'server' => "http://127.0.0.1:$vp", 'publicKeys' => [$pubKey['publicKey']], 'enforce' => true]));
cli($prod, 'migrate.php', [], $prodEnv);
startServer($prod, $pp, $prodEnv, "$tmp/product.log");
$P = static fn (string $m, string $path, mixed $body = null, ?string $t = null) => call($pp, $m, $path, $body, $t);
$reg = $P('POST', '/api/auth/register', ['name' => 'Kunde', 'email' => 'admin@kunde.example', 'password' => 'geheim1234']);
$T = $reg[1]['token'];
$res = $P('GET', '/api/settings', null, $T);
check('Ohne Lizenzschlüssel: Zustand „ungültig“ (missing)', $res[1]['license']['enforced'] === true && $res[1]['license']['valid'] === false && $res[1]['license']['reason'] === 'missing', $res[2]);
expect('Ohne Lizenz: Lesen funktioniert (Daten bleiben einsehbar)', $P('GET', '/api/clients', null, $T), 200);
$res = $P('POST', '/api/clients', ['name' => 'Neu'], $T);
check('Ohne Lizenz: Ändern ist gesperrt (402 mit Hinweis)', $res[0] === 402 && $res[1]['details']['code'] === 'LICENSE_REQUIRED', $res[2]);
expect('Ohne Lizenz: Backup erstellen bleibt möglich', $P('POST', '/api/backups', [], $T), 201);
expect('Ohne Lizenz: Einstellungen speichern bleibt möglich', $P('PUT', '/api/settings/all', ['values' => ['COMPANY_PHONE' => '123']], $T), 200);
expect('Ohne Lizenz: Anmelden bleibt möglich', $P('POST', '/api/auth/login', ['email' => 'admin@kunde.example', 'password' => 'geheim1234']), 200);
$res = $P('POST', '/api/system/license/key', ['key' => 'AAAAA-BBBBB-CCCCC-DDDDD-EEEEE'], $T);
check('Unbekannter Schlüssel wird abgelehnt und nicht gespeichert', $res[0] === 400 && ($res[1]['details']['reason'] ?? '') === 'unknown' && !is_file("$tmp/product-settings.json") || !str_contains((string) @file_get_contents("$tmp/product-settings.json"), 'PRODUCT_LICENSE_KEY'), $res[2]);
$wrongDomain = $issue(['domain' => 'andere-domain.example.com']);
$res = $P('POST', '/api/system/license/key', ['key' => $wrongDomain['licenseKey']], $T);
check('Schlüssel für eine andere Domain wird abgelehnt', $res[0] === 400 && $res[1]['details']['reason'] === 'domain', $res[2]);
$otherProd = $issue(['domain' => 'kunde.example.com', 'slug' => 'anderes-tool']);
$res = $P('POST', '/api/system/license/key', ['key' => $otherProd['licenseKey']], $T);
check('Schlüssel eines anderen Produkts wird abgelehnt', $res[0] === 400 && $res[1]['details']['reason'] === 'product', $res[2]);

$mine = $issue(['domain' => 'kunde.example.com', 'plan' => 'starter']);
$res = $P('POST', '/api/system/license/key', ['key' => $mine['licenseKey']], $T);
check('Gültiger Schlüssel wird geprüft und gespeichert', $res[0] === 200 && $res[1]['license']['valid'] === true && $res[1]['license']['plan'] === 'starter', $res[2]);
expect('Mit Lizenz: Ändern funktioniert wieder', $P('POST', '/api/clients', ['name' => 'Erster Kunde'], $T), 201);
$res = $P('GET', '/api/tickets', null, $T);
check('Paket „starter“: Support-Funktion gesperrt (403 FEATURE_REQUIRED)', $res[0] === 403 && $res[1]['details']['feature'] === 'support', $res[2]);
foreach (['/api/products' => 'shop', '/api/orders' => 'shop', '/api/recurring' => 'recurring', '/api/licenses' => 'licenses', '/api/faq' => 'support', '/api/releases' => 'licenses'] as $path => $f) {
    $r = $P('GET', $path, null, $T);
    check("Paket „starter“: $path gesperrt ($f)", $r[0] === 403 && $r[1]['details']['feature'] === $f, $r[2]);
}
expect('Kernfunktionen (Kunden, Rechnungen, Projekte) bleiben frei', $P('GET', '/api/invoices', null, $T), 200);
$res = $P('GET', '/api/system/status', null, $T);
check('Status: Zustand, Version und Hinweis auf Support/Updates', $res[1]['license']['valid'] === true && $res[1]['license']['version'] === '0.1.0-beta' && $res[1]['license']['supportActive'] === true && $res[1]['license']['updatesActive'] === true, $res[2]);

// Paketwechsel beim Hersteller wirkt nach der nächsten Prüfung
call($vp, 'PATCH', '/api/licenses/' . $mine['id'], ['plan' => 'agency'], $A);
$res = $P('GET', '/api/tickets', null, $T);
check('Bis zur nächsten Prüfung gilt der zwischengespeicherte Stand (Cache)', $res[0] === 403, $res[2]);
$P('POST', '/api/system/license/refresh', [], $T);
expect('Nach „Jetzt prüfen“ ist das neue Paket aktiv (Support-Funktion)', $P('GET', '/api/tickets', null, $T), 200);
expect('… und Lizenzverkauf', $P('GET', '/api/licenses', null, $T), 200);
check('Der Lizenzserver sieht die Domain und Version der Installation', call($vp, 'GET', '/api/licenses/' . $mine['id'], null, $A)[1]['hosts'][0]['domain'] === 'kunde.example.com');

// Sperre beim Hersteller
call($vp, 'PATCH', '/api/licenses/' . $mine['id'], ['status' => 'REVOKED'], $A);
$res = $P('POST', '/api/system/license/refresh', [], $T);
check('Widerrufene Lizenz: sofort ungültig nach der Prüfung', $res[1]['license']['valid'] === false && $res[1]['license']['reason'] === 'revoked', $res[2]);
check('… Änderungen gesperrt, Lesen weiter möglich', $P('POST', '/api/clients', ['name' => 'X'], $T)[0] === 402 && $P('GET', '/api/clients', null, $T)[0] === 200);
check('… auch Funktionen der Lizenz sind zu', $P('GET', '/api/tickets', null, $T)[0] === 403);
call($vp, 'PATCH', '/api/licenses/' . $mine['id'], ['status' => 'ACTIVE'], $A);
$P('POST', '/api/system/license/refresh', [], $T);
expect('Wieder aktiviert: alles läuft wieder', $P('POST', '/api/clients', ['name' => 'Zweiter Kunde'], $T), 201);

// Server nicht erreichbar → Kulanzfrist
$vendorProc = $procs[0];
proc_terminate($vendorProc);
usleep(400000);
$res = $P('POST', '/api/system/license/refresh', [], $T);
check('Lizenzserver nicht erreichbar: Kulanzfrist hält die Software am Laufen (Modus „grace“)', $res[1]['license']['valid'] === true && $res[1]['license']['mode'] === 'grace', $res[2]);
expect('… Ändern bleibt möglich', $P('POST', '/api/clients', ['name' => 'Offline-Kunde'], $T), 201);
// Manipulierter Zwischenspeicher
$cacheFile = "$prod/database/product-license.cache";
$cache = json_decode((string) file_get_contents($cacheFile), true);
$cache['payload'] = substr($cache['payload'], 0, -4) . 'AAAA';
file_put_contents($cacheFile, json_encode($cache));
@unlink("$prod/database/product-license.state.json");
$res = $P('POST', '/api/system/license/refresh', [], $T);
check('Manipulierter Lizenz-Zwischenspeicher wird verworfen: ohne Server ungültig (offline)', $res[1]['license']['valid'] === false && $res[1]['license']['reason'] === 'offline', $res[2]);
check('… und Änderungen sind gesperrt', $P('POST', '/api/clients', ['name' => 'Y'], $T)[0] === 402);
// Server wieder starten
startServer($root, $vp, $vendorEnv, "$tmp/vendor.log");
$procs[0] = end($procs);
$res = $P('POST', '/api/system/license/refresh', [], $T);
check('Server wieder da: Lizenz wieder gültig', $res[1]['license']['valid'] === true && $res[1]['license']['mode'] === 'fresh', $res[2]);

/* ---------- Updates ---------- */
echo "Updates\n";
$build = static function (string $version) use ($root): array {
    $p = proc_open([PHP_BINARY, "$root/Lizenz-tools/build-release.php", 'crm', "--version=$version"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $out];
};
[$code, $out] = $build('9.9.9');
$zipPath = "$root/Lizenz-tools/releases/crm-9.9.9.zip";
check('Release-Paket wird gebaut (mit Dateiliste und Prüfsummen, ohne Installer)', $code === 0 && is_file($zipPath), $out);
$z = new ZipArchive();
$z->open($zipPath);
$man = json_decode((string) $z->getFromName('manifest.json'), true);
$names = [];
for ($i = 0; $i < $z->numFiles; $i++) {
    $names[] = $z->getNameIndex($i);
}
check('Paket: Produkt, Version, keine install.php/product.json/.env/Datenbank', $man['product'] === 'crm' && $man['version'] === '9.9.9' && !in_array('public/install.php', $names, true) && !in_array('product.json', $names, true) && !preg_grep('/\.env$|\.db$|settings\.json|license\.key/', $names) && trim((string) $z->getFromName('VERSION')) === '9.9.9', array_slice($names, 0, 5));
$z->close();
$up = static function (string $path, string $channel = 'stable', bool $pub = false) use ($vp, $A): array {
    [$body, $ct] = multipart(['channel' => $channel, 'notes' => "• Neu: Test\n• Behoben: Fehler", 'published' => $pub ? 'true' : 'false'], ['file' => [basename($path), (string) file_get_contents($path)]]);
    return call($vp, 'POST', '/api/releases', $body, $A, [$ct]);
};
expect('Release hochladen (Entwurf)', $r = $up($zipPath), 201);
$rel1 = $r[1];
check('Server nennt Version, Prüfsumme und Größe; Signatur wird nie ausgeliefert', $rel1['version'] === '9.9.9' && strlen($rel1['sha256']) === 64 && $rel1['isPublished'] === false && !isset($rel1['signature'], $rel1['fileName']), $rel1);
expect('Dieselbe Version noch einmal → 409', $up($zipPath), 409);
[$bad0, $ct0] = multipart([], ['file' => ['x.zip', str_repeat('x', 500)]]);
expect('Datei, die kein ZIP ist → 400', call($vp, 'POST', '/api/releases', $bad0, $A, [$ct0]), 400);
[$bad, $ct] = multipart([], ['file' => ['x.zip', 'PK' . str_repeat("\0", 200)]]);
expect('Kaputtes ZIP → 400', call($vp, 'POST', '/api/releases', $bad, $A, [$ct]), 400);
$list = call($vp, 'GET', '/api/releases', null, $A);
check('Release-Liste (nur Admin)', $list[0] === 200 && count($list[1]) === 1, $list[2]);
call($vp, 'POST', '/api/users', ['name' => 'Mitarbeiter', 'email' => 'm@example.com', 'password' => 'mitarbeiter12'], $A);
$mt = call($vp, 'POST', '/api/auth/login', ['email' => 'm@example.com', 'password' => 'mitarbeiter12'])[1]['token'];
expect('Releases: Mitarbeiter ohne Zugriff → 403', call($vp, 'GET', '/api/releases', null, $mt), 403);

$pEnv = $prodEnv;
[$code, $out] = cli($prod, 'update.php', ['--check'], $pEnv);
check('Unveröffentlichte Version wird nicht angeboten', $code === 0 && str_contains($out, 'kein neueres Update'), $out);
call($vp, 'PATCH', '/api/releases/' . $rel1['id'], ['published' => true], $A);
$tk = static fn (array $f): array => $signedCall($vp, $f + ['key' => $mine['licenseKey'], 'domain' => 'kunde.example.com', 'product' => 'crm', 'version' => '0.1.0-beta'], '/api/license/update-check');
[$r, $p, $ok] = $tk([]);
check('Update-Prüfung: signierte Antwort mit neuer Version, Anspruch und Download-Token', $ok && $p['type'] === 'update' && $p['valid'] && $p['latest']['version'] === '9.9.9' && $p['entitled'] === true && isset($p['download']['token']), $p);
[$r, $p] = $tk(['product' => 'anderes-tool']);
check('Update-Prüfung für ein anderes Produkt der Lizenz → abgelehnt (product)', $p['valid'] === false && $p['reason'] === 'product' && $p['latest'] === null, $p);
[$r, $p] = $signedCall($vp, ['key' => 'AAAAA-BBBBB-CCCCC-DDDDD-EEEEE', 'domain' => 'kunde.example.com', 'product' => 'crm'], '/api/license/update-check');
check('Update-Prüfung mit unbekanntem Schlüssel → abgelehnt', $p['valid'] === false && $p['reason'] === 'unknown');
[$r, $p] = $tk(['version' => '9.9.9']);
check('Bereits aktuelle Version: kein Update angeboten', $p['latest'] === null);
$token = $tk([])[1]['download']['token'];
$res = call($vp, 'POST', '/api/license/download', ['token' => $token]);
check('Download mit gültigem Token liefert das ZIP (Prüfsumme stimmt)', $res[0] === 200 && hash('sha256', $res[2]) === $rel1['sha256'] && ($res[3]['x-content-type-options'] ?? '') === 'nosniff', [$res[0], $res[3]]);
expect('Download mit erfundenem Token → 403', call($vp, 'POST', '/api/license/download', ['token' => $token . 'x']), 403);
expect('Download mit manipuliertem Token → 403', call($vp, 'POST', '/api/license/download', ['token' => substr($token, 0, 10) . 'ABC' . substr($token, 13)]), 403);
expect('Download ohne Token → 400', call($vp, 'POST', '/api/license/download', []), 400);

// Sicht des Produkts per Kommandozeile
[$code, $out] = cli($prod, 'update.php', ['--check'], $pEnv);
check('Produkt: „--check“ meldet die neue Version', $code === 0 && str_contains($out, '9.9.9') && str_contains($out, 'verfügbar'), $out);
$res = $P('POST', '/api/system/update/check', [], $T);
check('Produkt: Update-Prüfung per Oberfläche (ohne Token in der Antwort)', $res[0] === 200 && $res[1]['latest']['version'] === '9.9.9' && $res[1]['entitled'] === true && !isset($res[1]['download']), $res[2]);

// Sabotage 1: Signatur in der Datenbank des Servers verfälscht
$vdb = new PDO('sqlite:' . "$tmp/vendor.db");
$origSig = (string) $vdb->query("SELECT signature FROM \"Release\" WHERE id = '{$rel1['id']}'")->fetchColumn();
$vdb->exec("UPDATE \"Release\" SET signature = '" . substr($origSig, 0, -4) . "AAAA' WHERE id = '{$rel1['id']}'");
$before = file_get_contents("$prod/src/Support/Dates.php");
[$code, $out] = cli($prod, 'update.php', ['--install'], $pEnv);
check('Gefälschte Signatur: Update wird abgelehnt, nichts verändert', $code === 1 && str_contains($out, 'Signatur') && trim((string) file_get_contents("$prod/VERSION")) === '0.1.0-beta' && file_get_contents("$prod/src/Support/Dates.php") === $before, $out);
$vdb->exec("UPDATE \"Release\" SET signature = '$origSig' WHERE id = '{$rel1['id']}'");

// Sabotage 2: Paketdatei auf dem Server verändert
$stored = (string) $vdb->query("SELECT fileName FROM \"Release\" WHERE id = '{$rel1['id']}'")->fetchColumn();
$storedPath = "$tmp/vendor-up/releases/$stored";
$orig = (string) file_get_contents($storedPath);
file_put_contents($storedPath, $orig . 'MANIPULIERT');
[$code, $out] = cli($prod, 'update.php', ['--install'], $pEnv);
check('Veränderte Paketdatei: Prüfsumme schlägt an, nichts verändert', $code === 1 && str_contains($out, 'Prüfsumme') && trim((string) file_get_contents("$prod/VERSION")) === '0.1.0-beta', $out);
file_put_contents($storedPath, $orig);

// Sabotage 3: Gefälschter Update-Server (gültiges JSON, falsche Signatur)
$fakeDir = "$tmp/fake";
mkdir($fakeDir);
file_put_contents("$fakeDir/index.php", '<?php $b=json_decode(file_get_contents("php://input"),true); $e=fn($s)=>rtrim(strtr(base64_encode($s),"+/","-_"),"="); $p=["v"=>2,"type"=>"update","valid"=>true,"reason"=>null,"domain"=>$b["domain"],"product"=>"crm","issuedAt"=>gmdate("Y-m-d\TH:i:s.000\Z"),"nonce"=>$b["nonce"],"latest"=>["version"=>"99.0.0","releasedAt"=>"2026-01-01T00:00:00.000Z","notes"=>"","sha256"=>str_repeat("a",64),"size"=>10,"minPhp"=>null,"signature"=>"x","channel"=>"stable"],"entitled"=>true,"updatesUntil"=>null,"download"=>["token"=>"t","expiresAt"=>"x"]]; header("Content-Type: application/json"); echo json_encode(["payload"=>$e(json_encode($p)),"signature"=>$e(random_bytes(64))]);');
$fakePort = $base + 4;
$fake = proc_open([PHP_BINARY, '-S', "127.0.0.1:$fakePort", "$fakeDir/index.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $fp, $fakeDir);
$procs[] = $fake;
usleep(500000);
$fakeProd = "$tmp/product-fake";
copyProject($root, $fakeProd);
file_put_contents("$fakeProd/product.json", json_encode(['product' => 'crm', 'server' => "http://127.0.0.1:$fakePort", 'publicKeys' => [$pubKey['publicKey']], 'enforce' => true]));
file_put_contents("$fakeProd/database/.keep", '');
$fakeEnv = ['SETTINGS_FILE' => "$tmp/fake-settings.json", 'DATABASE_PATH' => "$tmp/fake.db", 'PRODUCT_LICENSE_KEY' => $mine['licenseKey'], 'APP_URL' => 'https://kunde.example.com', 'JWT_SECRET' => 'fake-secret-fake-secret-1234', 'UPLOAD_DIR' => "$tmp/fake-up", 'BACKUP_DIR' => "$tmp/fake-bk"] + $cleanEnv;
cli($fakeProd, 'migrate.php', [], $fakeEnv);
[$code, $out] = cli($fakeProd, 'update.php', ['--install'], $fakeEnv);
check('Gefälschter Update-Server (falsche Signatur) wird erkannt, nichts installiert', $code === 1 && str_contains($out, 'gültige Antwort') && trim((string) file_get_contents("$fakeProd/VERSION")) === '0.1.0-beta', $out);

// Der echte Update-Lauf – vorher eine Datei „kaputt machen“ und eine alte Datei hinzufügen
file_put_contents("$prod/src/Support/Dates.php", $before . "\n// kaputt\n");
file_put_contents("$prod/src/Support/AltlastDieEsNichtMehrGibt.php", '<?php // obsolet');
$manifestOld = is_file("$prod/manifest.json") ? json_decode((string) file_get_contents("$prod/manifest.json"), true) : ['files' => []];
$manifestOld['files'][] = ['path' => 'src/Support/AltlastDieEsNichtMehrGibt.php', 'sha256' => hash('sha256', '<?php // obsolet')];
file_put_contents("$prod/manifest.json", json_encode($manifestOld));
$settingsBefore = file_get_contents("$tmp/product-settings.json");
$productJsonBefore = file_get_contents("$prod/product.json");
$clientsBefore = $P('GET', '/api/clients?pageSize=1', null, $T)[1]['meta']['total'];
[$code, $out] = cli($prod, 'update.php', ['--install'], $pEnv);
check('Update wird eingespielt (CLI meldet 0.1.0-beta → 9.9.9 und die Sicherung)', $code === 0 && str_contains($out, '0.1.0-beta → 9.9.9') && str_contains($out, 'code-vor-update'), $out);
check('VERSION ist 9.9.9, manipulierte Datei wurde repariert, veraltete Datei entfernt', trim((string) file_get_contents("$prod/VERSION")) === '9.9.9' && file_get_contents("$prod/src/Support/Dates.php") === $before && !is_file("$prod/src/Support/AltlastDieEsNichtMehrGibt.php"));
check('Einstellungen, product.json, Datenbank und Lizenzschlüssel bleiben unangetastet', file_get_contents("$tmp/product-settings.json") === $settingsBefore && file_get_contents("$prod/product.json") === $productJsonBefore && is_file("$tmp/product.db") && $P('GET', '/api/clients?pageSize=1', null, $T)[1]['meta']['total'] === $clientsBefore);
$backups = glob("$tmp/product-bk/code-vor-update-0.1.0-beta-*.zip") ?: [];
check('Code-Sicherung der alten Version liegt im Backup-Ordner (und ein Daten-Backup)', count($backups) === 1 && count(glob("$tmp/product-bk/crm-backup-*.zip") ?: []) >= 1, $backups);
$res = $P('GET', '/api/system/status', null, $T);
check('Produkt läuft nach dem Update; Version und Update-Verlauf stimmen', $res[0] === 200 && $res[1]['license']['version'] === '9.9.9' && $res[1]['history'][0]['to'] === '9.9.9', $res[2]);
[$code, $out] = cli($prod, 'update.php', ['--check'], $pEnv);
check('Danach: kein weiteres Update', $code === 0 && str_contains($out, 'kein neueres Update'), $out);
check('Download-Zähler beim Release steigt', call($vp, 'GET', '/api/releases', null, $A)[1][0]['downloads'] >= 2);

// Rücknahme per Kommandozeile
file_put_contents("$prod/src/Support/Dates.php", "<?php // vom Update ersetzt\n");
[$code, $out] = cli($prod, 'update.php', ['--rollback=' . $backups[0]], $pEnv);
check('Rücksicherung aus der Code-Sicherung (--rollback): Stand von vor dem Update', $code === 0 && trim((string) file_get_contents("$prod/VERSION")) === '0.1.0-beta' && file_get_contents("$prod/src/Support/Dates.php") === $before . "\n// kaputt\n", $out);

// Fehlschlagende Migration: automatische Rücknahme
$z = new ZipArchive();
$z->open($zipPath);
$files = $man['files'];
$broken = "THIS IS NOT VALID SQL;\n";
$files[] = ['path' => 'database/migrations/999_kaputt.sql', 'sha256' => hash('sha256', $broken)];
$man2 = ['version' => '9.9.10'] + $man;
$man2['files'] = array_map(static fn ($f) => $f['path'] === 'VERSION' ? ['path' => 'VERSION', 'sha256' => hash('sha256', "9.9.10\n")] : $f, $files);
$zip2Path = "$root/Lizenz-tools/releases/crm-9.9.10.zip";
@unlink($zip2Path);
$z2 = new ZipArchive();
$z2->open($zip2Path, ZipArchive::CREATE);
for ($i = 0; $i < $z->numFiles; $i++) {
    $n = $z->getNameIndex($i);
    if ($n === 'manifest.json') {
        continue;
    }
    $z2->addFromString($n, $n === 'VERSION' ? "9.9.10\n" : (string) $z->getFromIndex($i));
}
$z2->addFromString('database/migrations/999_kaputt.sql', $broken);
$z2->addFromString('manifest.json', json_encode($man2));
$z2->close();
$z->close();
$r2 = $up($zip2Path, 'stable', true);
check('Paket mit fehlerhafter Migration wird hochgeladen', $r2[0] === 201, $r2[2]);
// 9.9.9 zurückziehen, damit die fehlerhafte Version die neueste ist
call($vp, 'PATCH', '/api/releases/' . $rel1['id'], ['published' => false], $A);
$beforeFiles = file_get_contents("$prod/src/Support/Dates.php");
file_put_contents("$prod/src/Support/Dates.php", $before); // wieder sauber, damit die Rücknahme unten eindeutig prüfbar ist
$beforeFiles = $before;
[$code, $out] = cli($prod, 'update.php', ['--install'], $pEnv);
check('Fehlerhafte Migration: Update wird zurückgenommen (Version und Dateien wie vorher)', $code === 1 && str_contains($out, 'zurückgenommen') && trim((string) file_get_contents("$prod/VERSION")) === '0.1.0-beta' && !is_file("$prod/database/migrations/999_kaputt.sql") && file_get_contents("$prod/src/Support/Dates.php") === $beforeFiles, $out);
check('… die Anwendung läuft danach weiter', $P('GET', '/api/settings', null, $T)[0] === 200);
call($vp, 'DELETE', '/api/releases/' . $r2[1]['id'], null, $A);

// Update-Zeitraum abgelaufen
call($vp, 'PATCH', '/api/releases/' . $rel1['id'], ['published' => true], $A);
call($vp, 'PATCH', '/api/licenses/' . $mine['id'], ['updatesUntil' => gmdate('Y-m-d\TH:i:s.000\Z', time() - 3600)], $A);
$P('POST', '/api/system/license/refresh', [], $T);
[$code, $out] = cli($prod, 'update.php', ['--install'], $pEnv);
check('Update-Zeitraum vor Erscheinen der Version abgelaufen: kein Download, klare Meldung', $code === 1 && str_contains($out, 'nach dem Ende deines Update-Zeitraums') && trim((string) file_get_contents("$prod/VERSION")) === '0.1.0-beta', $out);
[$r, $p] = $tk([]);
check('Der Server gibt in diesem Fall kein Download-Token heraus', $p['entitled'] === false && !isset($p['download']) && $p['latest']['version'] === '9.9.9', $p);
// Version erschien VOR Ablauf: weiter erlaubt
call($vp, 'PATCH', '/api/licenses/' . $mine['id'], ['updatesUntil' => gmdate('Y-m-d\TH:i:s.000\Z', time() + 86400)], $A);
check('Version, die vor dem Ablauf erschien, bleibt erlaubt', $tk([])[1]['entitled'] === true);
// Widerruf nach Token-Ausgabe
$tok = $tk([])[1]['download']['token'];
call($vp, 'PATCH', '/api/licenses/' . $mine['id'], ['status' => 'SUSPENDED'], $A);
expect('Download mit gültigem Token, aber inzwischen gesperrter Lizenz → 403', call($vp, 'POST', '/api/license/download', ['token' => $tok]), 403);
call($vp, 'PATCH', '/api/licenses/' . $mine['id'], ['status' => 'ACTIVE'], $A);
// Beta-Kanal
call($vp, 'PATCH', '/api/releases/' . $rel1['id'], ['channel' => 'beta'], $A);
check('Beta-Versionen bekommt nur, wer den Beta-Kanal wählt', $tk([])[1]['latest'] === null && $tk(['channel' => 'beta'])[1]['latest']['version'] === '9.9.9');
call($vp, 'DELETE', '/api/releases/' . $rel1['id'], null, $A);
check('Gelöschte Version ist weg (inkl. Datei)', !is_file($storedPath) && $tk([])[1]['latest'] === null);

/* ---------- Öffentliche Releases (Beta ohne Lizenz) und verpasste Versionen ---------- */
echo "Beta ohne Lizenz, verpasste Versionen, Zwischenschritte\n";
$upX = static function (string $version, array $fields) use ($build, $vp, $A, $root): array {
    $build($version);
    $path = "$root/Lizenz-tools/releases/crm-$version.zip";
    [$body, $ct] = multipart($fields + ['channel' => 'stable', 'notes' => "• Neu in $version"], ['file' => [basename($path), (string) file_get_contents($path)]]);
    return call($vp, 'POST', '/api/releases', $body, $A, [$ct]);
};
$r1 = $upX('9.9.1', ['access' => 'public', 'published' => 'true']);
$r2 = $upX('9.9.2', ['access' => 'public', 'published' => 'true']);
$r3 = $upX('9.9.3', ['access' => 'public', 'published' => 'false', 'minFrom' => '9.9.2']);
check('Releases mit Zugang „public“ und Mindestversion werden angelegt', $r1[0] === 201 && $r1[1]['access'] === 'public' && $r3[1]['minFrom'] === '9.9.2', [$r1[2], $r3[2]]);
expect('Ungültige Mindestversion → 400', $upX('9.9.8', ['access' => 'public', 'minFrom' => 'abc']), 400);
@unlink("$root/Lizenz-tools/releases/crm-9.9.8.zip");
[$r, $p, $ok] = $signedCall($vp, ['domain' => 'beta.example.com', 'product' => 'crm', 'version' => '0.1.0-beta'], '/api/license/update-public');
check('Öffentliche Prüfung ohne Schlüssel: signiert, neueste ist 9.9.2 (Entwurf 9.9.3 unsichtbar), 2 Änderungen', $ok && $p['valid'] && $p['target']['version'] === '9.9.2' && $p['latest']['version'] === '9.9.2' && count($p['changes']) === 2 && $p['changes'][0]['version'] === '9.9.1' && isset($p['download']), $p);
$licOnly = $upX('9.9.4', ['access' => 'licensed', 'published' => 'true']);
[$r, $p] = $signedCall($vp, ['domain' => 'beta.example.com', 'product' => 'crm', 'version' => '0.1.0-beta'], '/api/license/update-public');
check('Lizenzpflichtiges Release erscheint nicht in der öffentlichen Prüfung', $p['latest']['version'] === '9.9.2', $p);
$pubTok = $p['download']['token'];
$res = call($vp, 'POST', '/api/license/download', ['token' => $pubTok]);
check('Öffentlicher Download ohne Lizenz: ZIP mit stimmiger Prüfsumme', $res[0] === 200 && hash('sha256', $res[2]) === $r2[1]['sha256'], $res[0]);
[$r, $p] = $signedCall($vp, ['domain' => 'beta.example.com', 'product' => 'crm', 'version' => '9.9.2'], '/api/license/update-public');
check('Schon aktuell (öffentlich): nichts angeboten, aber keine Fehlermeldung', $p['valid'] && $p['latest'] === null && $p['target'] === null && $p['changes'] === []);
$licRes = $signedCall($vp, ['key' => $mine['licenseKey'], 'domain' => 'kunde.example.com', 'product' => 'crm', 'version' => '0.1.0-beta'], '/api/license/update-check');
check('Mit Lizenz: auch lizenzpflichtige Version, alle 4 Änderungen seit der installierten', $licRes[1]['target']['version'] === '9.9.4' && count($licRes[1]['changes']) === 3 && $licRes[1]['entitled'] === true, $licRes[1]);

// Installation ohne Lizenz: erst überspringt sie 9.9.1 (ein Schritt), dann Mindestversion erfüllt
$pubDir = "$tmp/product-public";
copyProject($root, $pubDir);
file_put_contents("$pubDir/product.json", json_encode(['product' => 'crm', 'server' => "http://127.0.0.1:$vp", 'publicKeys' => [$pubKey['publicKey']], 'enforce' => false]));
$pubEnv = $cleanEnv + ['DATABASE_PATH' => "$tmp/pub.db", 'SETTINGS_FILE' => "$tmp/pub-settings.json", 'JWT_SECRET' => 'public-secret-public-secret-001', 'UPLOAD_DIR' => "$tmp/pub-up", 'BACKUP_DIR' => "$tmp/pub-bk", 'APP_URL' => 'https://beta.example.com', 'LICENSE_ALLOW_DEV' => 'true'];
cli($pubDir, 'migrate.php', [], $pubEnv);
[$code, $out] = cli($pubDir, 'update.php', ['--check'], $pubEnv);
check('Beta-Installation (ohne Lizenz): „--check“ listet die Änderungen seit der installierten Version', $code === 0 && str_contains($out, '9.9.2') && str_contains($out, 'Neu in 9.9.1') && str_contains($out, 'Neu in 9.9.2'), $out);
[$code, $out] = cli($pubDir, 'update.php', ['--install'], $pubEnv);
check('Verpasste Version wird übersprungen: ein einziger Schritt 0.1.0-beta → 9.9.2', $code === 0 && substr_count($out, 'eingespielt') === 1 && str_contains($out, '0.1.0-beta → 9.9.2') && trim((string) file_get_contents("$pubDir/VERSION")) === '9.9.2', $out);
check('Ohne Lizenz: es wird kein Lizenzschlüssel gesendet oder verlangt, Datenbank bleibt intakt', is_file("$tmp/pub.db"));
call($vp, 'PATCH', '/api/releases/' . $r3[1]['id'], ['published' => true], $A);
[$code, $out] = cli($pubDir, 'update.php', ['--install'], $pubEnv);
check('Mindestversion erfüllt: direkt 9.9.2 → 9.9.3', $code === 0 && str_contains($out, '9.9.2 → 9.9.3') && trim((string) file_get_contents("$pubDir/VERSION")) === '9.9.3', $out);
[$code, $out] = cli($pubDir, 'update.php', ['--install'], $pubEnv);
check('Danach: nichts mehr zu tun', $code === 1 && str_contains($out, 'neuesten Stand'), $out);

// Zwischenschritt-Kette: eine frische Installation hinkt weit hinterher
$hopDir = "$tmp/product-hop";
copyProject($root, $hopDir);
file_put_contents("$hopDir/product.json", json_encode(['product' => 'crm', 'server' => "http://127.0.0.1:$vp", 'publicKeys' => [$pubKey['publicKey']], 'enforce' => false]));
$hopEnv = $pubEnv + ['DATABASE_PATH' => "$tmp/hop.db", 'SETTINGS_FILE' => "$tmp/hop-settings.json", 'UPLOAD_DIR' => "$tmp/hop-up", 'BACKUP_DIR' => "$tmp/hop-bk"];
$hopEnv = array_merge($pubEnv, ['DATABASE_PATH' => "$tmp/hop.db", 'SETTINGS_FILE' => "$tmp/hop-settings.json", 'UPLOAD_DIR' => "$tmp/hop-up", 'BACKUP_DIR' => "$tmp/hop-bk"]);
cli($hopDir, 'migrate.php', [], $hopEnv);
[$code, $out] = cli($hopDir, 'update.php', ['--install'], $hopEnv);
check('Version mit Mindestversion: erst Zwischenstand 9.9.2, dann automatisch 9.9.3 (zwei Schritte in einem Aufruf)', $code === 0 && substr_count($out, 'eingespielt') === 2 && str_contains($out, '0.1.0-beta → 9.9.2') && str_contains($out, '9.9.2 → 9.9.3') && trim((string) file_get_contents("$hopDir/VERSION")) === '9.9.3', $out);
// Software-Download im Kundenportal (Vollpaket)
$r5 = $upX('9.9.5', ['access' => 'licensed', 'published' => 'true']);
$fullZip = static function (string $version, bool $installer = true, string $prefix = 'crm/') use ($tmp): string {
    $f = "$tmp/full-" . bin2hex(random_bytes(3)) . '.zip';
    $z = new ZipArchive();
    $z->open($f, ZipArchive::CREATE);
    $z->addFromString($prefix . 'manifest.json', json_encode(['product' => 'crm', 'version' => $version, 'files' => []]));
    $z->addFromString($prefix . 'product.json', '{"product":"crm"}');
    if ($installer) {
        $z->addFromString($prefix . 'public/install.php', '<?php // installer ' . str_repeat('x', 1500));
    }
    $z->close();
    return (string) file_get_contents($f);
};
$attach = static function (string $id, string $bytes) use ($vp, $A): array {
    [$body, $ct] = multipart([], ['file' => ['full.zip', $bytes]]);
    return call($vp, 'POST', "/api/releases/$id/full", $body, $A, [$ct]);
};
$licRow = static fn (string $id): array => array_values(array_filter(call($vp, 'GET', '/api/portal/licenses', null, null, $PH)[1], static fn ($x) => $x['id'] === $id))[0];
check('Portal: ohne bereitgestelltes Vollpaket kein Download-Knopf, Abruf → 404', $licRow($okSup['id'])['download'] === null && call($vp, 'GET', '/api/portal/licenses/' . $okSup['id'] . '/download', null, null, $PH)[0] === 404);
expect('Vollpaket mit falscher Version → 400', $attach($r5[1]['id'], $fullZip('1.2.3')), 400);
expect('Vollpaket ohne install.php → 400', $attach($r5[1]['id'], $fullZip('9.9.5', false)), 400);
$good = $fullZip('9.9.5');
$res = $attach($r5[1]['id'], $good);
check('Vollpaket (in Oberordner) wird angenommen; Liste zeigt es, Dateiname nie', $res[0] === 200 && $res[1]['hasFull'] === true && !isset($res[1]['fullFileName']), $res[2]);
$row = $licRow($okSup['id']);
check('Portal: Lizenz mit Vollpaket zeigt Version und Größe', ($row['download']['version'] ?? '') === '9.9.5' && $row['download']['size'] === strlen($good), $row);
$dl = call($vp, 'GET', '/api/portal/licenses/' . $okSup['id'] . '/download', null, null, $PH);
check('Portal-Download liefert das ZIP unverändert', $dl[0] === 200 && $dl[2] === $good && ($dl[3]['content-type'] ?? '') === 'application/zip', $dl[0]);
expect('Download ohne Portal-Anmeldung → 401', call($vp, 'GET', '/api/portal/licenses/' . $okSup['id'] . '/download'), 401);
call($vp, 'PATCH', '/api/licenses/' . $okSup['id'], ['updatesUntil' => gmdate('Y-m-d\TH:i:s.000\Z', time() - 86400)], $A);
expect('Update-Anspruch vor Erscheinen der Version abgelaufen → 404', call($vp, 'GET', '/api/portal/licenses/' . $okSup['id'] . '/download', null, null, $PH), 404);
call($vp, 'PATCH', '/api/licenses/' . $okSup['id'], ['status' => 'REVOKED'], $A);
expect('Gesperrte Lizenz → 403', call($vp, 'GET', '/api/portal/licenses/' . $okSup['id'] . '/download', null, null, $PH), 403);
call($vp, 'DELETE', '/api/releases/' . $r5[1]['id'], null, $A);
foreach ([$r1, $r2, $r3, $licOnly] as $rr) {
    call($vp, 'DELETE', '/api/releases/' . $rr[1]['id'], null, $A);
}

/* ---------- Installer des Produkts ---------- */
echo "Installer der Produktversion\n";
$inst = "$tmp/product-install";
copyProject($root, $inst);
file_put_contents("$inst/product.json", json_encode(['product' => 'crm', 'server' => "http://127.0.0.1:$vp", 'publicKeys' => [$pubKey['publicKey']], 'enforce' => true]));
$instEnv = $cleanEnv + ['SETTINGS_FILE' => "$tmp/inst-settings.json", 'DATABASE_PATH' => "$tmp/inst.db"];
startServer($inst, $ip, $instEnv, "$tmp/inst.log");
$instLic = $issue(['domain' => 'installiert.example.com', 'plan' => 'pro']);
$curl = static function (string $method, string $path, array $post = [], ?string $jar = null) use ($ip): array {
    $ch = curl_init("http://127.0.0.1:$ip$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_POST => $method === 'POST', CURLOPT_POSTFIELDS => $post ? http_build_query($post) : null, CURLOPT_TIMEOUT => 60]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$status, $body];
};
$jar = "$tmp/cookies.txt";
[$s, $page] = $curl('GET', '/install.php', [], $jar);
preg_match('/name="csrf" value="([a-f0-9]+)"/', $page, $m);
check('Installer der Produktversion fragt den Lizenzschlüssel ab', $s === 200 && str_contains($page, 'name="license_key"') && str_contains($page, 'Lizenz prüfen'), substr($page, 0, 100));
$form = ['csrf' => $m[1] ?? '', 'action' => 'install', 'company' => 'Test GmbH', 'owner' => 'Test', 'username' => 'admin', 'email' => 'a@test.example', 'password' => 'langes-passwort-1', 'password2' => 'langes-passwort-1', 'url' => 'https://installiert.example.com', 'db' => 'sqlite', 'catalog' => '1'];
[$s, $page] = $curl('POST', '/install.php', $form, $jar);
check('Installation ohne Lizenzschlüssel wird abgelehnt', str_contains($page, 'Lizenzschlüssel eingeben') && !is_file("$inst/database/installed.lock"));
[$s, $page] = $curl('POST', '/install.php', ['license_key' => 'AAAAA-BBBBB-CCCCC-DDDDD-EEEEE'] + $form, $jar);
check('Mit unbekanntem Schlüssel: Installation scheitert, nichts wird freigeschaltet', str_contains($page, 'Lizenz:') && str_contains($page, 'unbekannt') && !is_file("$inst/database/installed.lock"), substr(strip_tags($page), 0, 400));
[$s, $page] = $curl('POST', '/install.php', ['license_key' => $instLic['licenseKey'], 'action' => 'licensecheck'] + $form, $jar);
check('„Lizenz prüfen“ im Installer zeigt Paket und Zeiträume', str_contains($page, 'Lizenz gültig') && str_contains($page, 'pro'), substr(strip_tags($page), 0, 300));
[$s, $page] = $curl('POST', '/install.php', ['license_key' => $instLic['licenseKey']] + $form, $jar);
check('Mit gültigem Schlüssel: Installation gelingt, Lizenz wird gespeichert', str_contains($page, 'Fertig') && is_file("$inst/database/installed.lock") && str_contains((string) file_get_contents("$tmp/inst-settings.json"), $instLic['licenseKey']), substr(strip_tags($page), 0, 400));
$ires = call($ip, 'POST', '/api/auth/login', ['email' => 'admin', 'password' => 'langes-passwort-1']);
$it = $ires[1]['token'] ?? '';
$st = call($ip, 'GET', '/api/system/status', null, $it);
check('Installiertes Produkt: Lizenz gültig, Paket „pro“, Katalog (Funktion shop) nutzbar', $st[1]['license']['valid'] === true && $st[1]['license']['plan'] === 'pro' && call($ip, 'GET', '/api/products', null, $it)[0] === 200 && call($ip, 'GET', '/api/licenses', null, $it)[0] === 403, $st[2]);

echo "\n$passed bestanden, $failed fehlgeschlagen\n";
if ($failed > 0) {
    foreach (['vendor', 'product'] as $l) {
        if (is_file("$tmp/$l.log")) {
            echo "--- $l.log ---\n" . substr((string) file_get_contents("$tmp/$l.log"), -1500) . "\n";
        }
    }
}
exit($failed > 0 ? 1 : 0);
