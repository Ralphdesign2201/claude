<?php

declare(strict_types=1);

/**
 * Integrationstest für Update-Server und Installationen: signierte Updates, verpasste Versionen, Zwischenschritte, Installer.
 * Startet mehrere Server (Update-Server und Kopien) mit eigenen Datenbanken.   Aufruf: php tests/product.php
 */

$root = dirname(__DIR__);
$base = (int) (getenv('TEST_PORT') ?: 18300);
$vp = $base;          // Update-Server
$vp2 = $base + 1;     // Update-Server mit HTTPS-Pflicht
$pp = $base + 2;      // Installation
$ip = $base + 3;      // Installation für den Installer-Test
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
    $cmd = 'cd ' . escapeshellarg($root) . ' && tar --exclude=.git --exclude=tests --exclude=.env --exclude="database/*.db*" --exclude=database/settings.json --exclude="database/*.key" --exclude=database/installed.lock -cf - . | tar -xf - -C ' . escapeshellarg($dest);
    shell_exec($cmd);
    foreach (['backups', 'uploads'] as $d) {
        @mkdir("$dest/$d", 0775, true);
    }
}

$cleanEnv = ['PATH' => (string) getenv('PATH')];


require_once $root . '/src/bootstrap.php';

/** Baut ein Update-Paket der angegebenen Version aus den Dateien dieses Projekts (wie PackageBuilder, aber mit frei wählbarer Version). */
$buildZip = static function (string $version, array $extra = []) use ($tmp): string {
    $path = "$tmp/crm-$version.zip";
    @unlink($path);
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE);
    $list = [];
    foreach (App\Services\PackageBuilder::files(true) as $rel) {
        $content = $rel === 'VERSION' ? "$version\n" : (string) file_get_contents(APP_ROOT . '/' . $rel);
        $zip->addFromString($rel, $content);
        $list[] = ['path' => $rel, 'sha256' => hash('sha256', $content)];
    }
    foreach ($extra as $name => $content) {
        $zip->addFromString($name, $content);
        $list[] = ['path' => $name, 'sha256' => hash('sha256', $content)];
    }
    $zip->addFromString('manifest.json', json_encode(['product' => 'crm', 'version' => $version, 'builtAt' => gmdate('c'), 'minPhp' => '8.1', 'files' => $list]));
    $zip->close();

    return $path;
};

/* ---------- Update-Server ---------- */
$vendorEnv = $cleanEnv + [
    'DATABASE_PATH' => "$tmp/vendor.db", 'SETTINGS_FILE' => "$tmp/vendor-settings.json", 'JWT_SECRET' => 'vendor-secret-vendor-secret-1', 'UPDATE_KEY_FILE' => "$tmp/vendor.key",
    'UPDATE_REQUIRE_HTTPS' => 'false', 'UPLOAD_DIR' => "$tmp/vendor-up", 'BACKUP_DIR' => "$tmp/vendor-bk", 'RATE_LIMIT_MAX' => '20000', 'APP_URL' => 'https://update.example.com',
];
cli($root, 'migrate.php', [], $vendorEnv);
startServer($root, $vp, $vendorEnv, "$tmp/vendor.log");
startServer($root, $vp2, ['UPDATE_REQUIRE_HTTPS' => 'true'] + $vendorEnv, "$tmp/vendor2.log");

echo "Update-Server und API-Sicherheit\n";
$res = call($vp, 'POST', '/api/auth/register', ['name' => 'Hersteller', 'email' => 'hersteller@example.com', 'password' => 'geheim1234']);
$A = $res[1]['token'];
$pubKey = call($vp, 'GET', '/api/updates/public-key')[1];
check('Öffentlicher Schlüssel mit Kennung (kid)', strlen((string) base64_decode($pubKey['publicKey'])) === 32 && strlen($pubKey['kid']) === 12, $pubKey);
$signedCall = static function (int $port, array $fields = [], string $path = '/api/updates/check', array $headers = []) use ($pubKey): array {
    $fields += ['nonce' => 'n' . bin2hex(random_bytes(10)), 'ts' => time(), 'product' => 'crm'];
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
[$r, $p, $ok, $sent] = $signedCall($vp, ['version' => '0.1.0-beta']);
check('Antwort ist signiert (Ed25519), nennt Typ, Schlüsselkennung und den Zufallswert der Anfrage', $ok && $p['type'] === 'update' && $p['kid'] === $pubKey['kid'] && $p['nonce'] === $sent['nonce'] && $p['latest'] === null && $p['target'] === null, $p);
check('Antwort ohne Cache: no-store', ($r[3]['cache-control'] ?? '') === 'no-store');
$fields = ['nonce' => 'replay-' . bin2hex(random_bytes(6)), 'ts' => time(), 'product' => 'crm'];
expect('Erste Anfrage mit neuem Zufallswert → 200', call($vp, 'POST', '/api/updates/check', $fields), 200);
check('Gleiche Anfrage noch einmal (Replay) → 409', call($vp, 'POST', '/api/updates/check', $fields)[0] === 409);
expect('Ohne Zeitstempel → 400', call($vp, 'POST', '/api/updates/check', ['nonce' => 'abcdefgh' . bin2hex(random_bytes(4)), 'product' => 'crm']), 400);
expect('Veralteter Zeitstempel (1 Stunde) → 400', call($vp, 'POST', '/api/updates/check', ['ts' => time() - 3600, 'nonce' => 'old' . bin2hex(random_bytes(8)), 'product' => 'crm']), 400);
expect('Zeitstempel in der Zukunft → 400', call($vp, 'POST', '/api/updates/check', ['ts' => time() + 3600, 'nonce' => 'fut' . bin2hex(random_bytes(8)), 'product' => 'crm']), 400);
expect('Ohne Zufallswert → 400', call($vp, 'POST', '/api/updates/check', ['ts' => time(), 'product' => 'crm']), 400);
expect('Ohne Produkt → 400', call($vp, 'POST', '/api/updates/check', ['ts' => time(), 'nonce' => 'abcdefgh' . bin2hex(random_bytes(4))]), 400);
$res = call($vp2, 'POST', '/api/updates/check', ['nonce' => 'h' . bin2hex(random_bytes(8)), 'ts' => time(), 'product' => 'crm']);
check('HTTPS-Pflicht: unverschlüsselte Anfrage → 400', $res[0] === 400 && str_contains($res[2], 'HTTPS'), $res[2]);
$res = call($vp2, 'POST', '/api/updates/check', ['nonce' => 'h' . bin2hex(random_bytes(8)), 'ts' => time(), 'product' => 'crm'], null, ['X-Forwarded-Proto: https']);
check('Mit HTTPS (Proxy-Header): erlaubt, mit HSTS-Header', $res[0] === 200 && str_contains($res[3]['strict-transport-security'] ?? '', 'max-age'), $res[3]);
check('HTTPS-Pflicht gilt auch für den Download', call($vp2, 'POST', '/api/updates/download', ['token' => str_repeat('a', 40)])[0] === 400);
expect('Alte Lizenz-Schnittstelle gibt es nicht mehr', call($vp, 'POST', '/api/license/verify', ['key' => 'x']), 404);

/* ---------- Installation (Kopie des Projekts mit Update-Server) ---------- */
echo "Installation\n";
$prod = "$tmp/product";
copyProject($root, $prod);
$prodEnv = $cleanEnv + [
    'DATABASE_PATH' => "$tmp/product.db", 'SETTINGS_FILE' => "$tmp/product-settings.json", 'JWT_SECRET' => 'product-secret-product-secret-1',
    'UPLOAD_DIR' => "$tmp/product-up", 'BACKUP_DIR' => "$tmp/product-bk", 'APP_URL' => 'https://kunde.example.com', 'RATE_LIMIT_MAX' => '20000', 'LOGIN_RATE_LIMIT_MAX' => '50',
];
file_put_contents("$prod/product.json", json_encode(['product' => 'crm', 'name' => 'Webdesigner CRM', 'server' => "http://127.0.0.1:$vp", 'publicKeys' => [$pubKey['publicKey']]]));
cli($prod, 'migrate.php', [], $prodEnv);
startServer($prod, $pp, $prodEnv, "$tmp/product.log");
$P = static fn (string $m, string $path, mixed $body = null, ?string $t = null) => call($pp, $m, $path, $body, $t);
$T = $P('POST', '/api/auth/register', ['name' => 'Kunde', 'email' => 'admin@kunde.example', 'password' => 'geheim1234'])[1]['token'];
$res = $P('GET', '/api/system/status', null, $T);
check('Status: Version und Update-Server eingetragen', $res[0] === 200 && $res[1]['version'] === '0.1.0-beta' && $res[1]['updates']['configured'] === true, $res[2]);
expect('Ändern funktioniert ohne jede Freischaltung', $P('POST', '/api/clients', ['name' => 'Neukunde'], $T), 201);
check('Lizenz-Endpunkte der Software gibt es nicht mehr', $P('GET', '/api/licenses', null, $T)[0] === 404 && $P('POST', '/api/system/license/refresh', [], $T)[0] === 404);

/* ---------- Updates ---------- */
echo "Updates\n";
$zipPath = $buildZip('9.9.9');
$z = new ZipArchive();
$z->open($zipPath);
$man = json_decode((string) $z->getFromName('manifest.json'), true);
$names = [];
for ($i = 0; $i < $z->numFiles; $i++) {
    $names[] = $z->getNameIndex($i);
}
check('Paket: Produkt, Version, keine install.php/product.json/.env/Datenbank/Schlüssel', $man['product'] === 'crm' && !in_array('public/install.php', $names, true) && !in_array('product.json', $names, true) && !preg_grep('/\.env$|\.db$|settings\.json|\.key$/', $names) && trim((string) $z->getFromName('VERSION')) === '9.9.9', array_slice($names, 0, 5));
$z->close();
$up = static function (string $path, string $channel = 'stable', bool $pub = false, string $minFrom = '') use ($vp, $A): array {
    [$body, $ct] = multipart(['channel' => $channel, 'notes' => "• Neu in " . basename($path, '.zip'), 'published' => $pub ? 'true' : 'false', 'minFrom' => $minFrom], ['file' => [basename($path), (string) file_get_contents($path)]]);
    return call($vp, 'POST', '/api/releases', $body, $A, [$ct]);
};
expect('Release hochladen (Entwurf)', $r = $up($zipPath), 201);
$rel1 = $r[1];
check('Server nennt Version, Prüfsumme und Größe; Signatur und Dateiname nie', $rel1['version'] === '9.9.9' && strlen($rel1['sha256']) === 64 && $rel1['isPublished'] === false && !isset($rel1['signature'], $rel1['fileName']), $rel1);
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
expect('Releases ohne Anmeldung → 401', call($vp, 'GET', '/api/releases'), 401);

[$code, $out] = cli($prod, 'update.php', ['--check'], $prodEnv);
check('Unveröffentlichte Version wird nicht angeboten', $code === 0 && str_contains($out, 'kein neueres Update'), $out);
call($vp, 'PATCH', '/api/releases/' . $rel1['id'], ['published' => true], $A);
[$r, $p, $ok] = $signedCall($vp, ['version' => '0.1.0-beta']);
check('Update-Prüfung: signierte Antwort mit neuer Version, Änderungen und Download-Token', $ok && $p['latest']['version'] === '9.9.9' && $p['target']['version'] === '9.9.9' && count($p['changes']) === 1 && isset($p['download']['token']), $p);
[$r, $p] = $signedCall($vp, ['product' => 'anderes-tool', 'version' => '0.1.0-beta']);
check('Anderes Produkt: nichts angeboten', $p['latest'] === null && !isset($p['download']), $p);
[$r, $p] = $signedCall($vp, ['version' => '9.9.9']);
check('Bereits aktuelle Version: kein Update angeboten', $p['latest'] === null);
$token = $signedCall($vp, ['version' => '0.1.0-beta'])[1]['download']['token'];
$res = call($vp, 'POST', '/api/updates/download', ['token' => $token]);
check('Download mit gültigem Token liefert das ZIP (Prüfsumme stimmt)', $res[0] === 200 && hash('sha256', $res[2]) === $rel1['sha256'] && ($res[3]['x-content-type-options'] ?? '') === 'nosniff', [$res[0], $res[3]]);
expect('Download mit erfundenem Token → 403', call($vp, 'POST', '/api/updates/download', ['token' => $token . 'x']), 403);
expect('Download mit manipuliertem Token → 403', call($vp, 'POST', '/api/updates/download', ['token' => substr($token, 0, 10) . 'ABC' . substr($token, 13)]), 403);
expect('Download ohne Token → 400', call($vp, 'POST', '/api/updates/download', []), 400);
[$code, $out] = cli($prod, 'update.php', ['--check'], $prodEnv);
check('Installation: „--check“ meldet die neue Version samt Änderungen', $code === 0 && str_contains($out, '9.9.9') && str_contains($out, 'Neu in crm-9.9.9'), $out);
$res = $P('POST', '/api/system/update/check', [], $T);
check('Installation: Prüfung per Oberfläche (ohne Token in der Antwort)', $res[0] === 200 && $res[1]['latest']['version'] === '9.9.9' && $res[1]['target']['version'] === '9.9.9' && !isset($res[1]['download']), $res[2]);

// Sabotage 1: Signatur in der Datenbank des Servers verfälscht
$vdb = new PDO('sqlite:' . "$tmp/vendor.db");
$origSig = (string) $vdb->query("SELECT signature FROM \"Release\" WHERE id = '{$rel1['id']}'")->fetchColumn();
$vdb->exec("UPDATE \"Release\" SET signature = '" . substr($origSig, 0, -4) . "AAAA' WHERE id = '{$rel1['id']}'");
$before = file_get_contents("$prod/src/Support/Dates.php");
[$code, $out] = cli($prod, 'update.php', ['--install'], $prodEnv);
check('Gefälschte Signatur: Update wird abgelehnt, nichts verändert', $code === 1 && str_contains($out, 'Signatur') && trim((string) file_get_contents("$prod/VERSION")) === '0.1.0-beta' && file_get_contents("$prod/src/Support/Dates.php") === $before, $out);
$vdb->exec("UPDATE \"Release\" SET signature = '$origSig' WHERE id = '{$rel1['id']}'");

// Sabotage 2: Paketdatei auf dem Server verändert
$stored = (string) $vdb->query("SELECT fileName FROM \"Release\" WHERE id = '{$rel1['id']}'")->fetchColumn();
$storedPath = "$tmp/vendor-up/releases/$stored";
$orig = (string) file_get_contents($storedPath);
file_put_contents($storedPath, $orig . 'MANIPULIERT');
[$code, $out] = cli($prod, 'update.php', ['--install'], $prodEnv);
check('Veränderte Paketdatei: Prüfsumme schlägt an, nichts verändert', $code === 1 && str_contains($out, 'Prüfsumme') && trim((string) file_get_contents("$prod/VERSION")) === '0.1.0-beta', $out);
file_put_contents($storedPath, $orig);

// Sabotage 3: Gefälschter Update-Server (gültiges JSON, falsche Signatur)
$fakeDir = "$tmp/fake";
mkdir($fakeDir);
file_put_contents("$fakeDir/index.php", '<?php $b=json_decode(file_get_contents("php://input"),true); $e=fn($s)=>rtrim(strtr(base64_encode($s),"+/","-_"),"="); $kp=sodium_crypto_sign_keypair(); $p=["v"=>3,"type"=>"update","kid"=>"x","issuedAt"=>gmdate("Y-m-d\\TH:i:s.000\\Z"),"nonce"=>$b["nonce"],"product"=>"crm","latest"=>["version"=>"9.9.9","releasedAt"=>"2026-01-01T00:00:00.000Z","notes"=>"x","sha256"=>str_repeat("a",64),"size"=>10,"minPhp"=>"8.1","signature"=>"x","channel"=>"stable"],"target"=>null,"changes"=>[]]; $j=json_encode($p); echo json_encode(["payload"=>$e($j),"signature"=>$e(sodium_crypto_sign_detached($j,sodium_crypto_sign_secretkey($kp)))]);');
$fakePort = $base + 4;
$fake = proc_open([PHP_BINARY, '-S', "127.0.0.1:$fakePort", "$fakeDir/index.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $fp, $fakeDir);
$procs[] = $fake;
usleep(500000);
$fakeProd = "$tmp/product-fake";
copyProject($root, $fakeProd);
file_put_contents("$fakeProd/product.json", json_encode(['product' => 'crm', 'server' => "http://127.0.0.1:$fakePort", 'publicKeys' => [$pubKey['publicKey']]]));
$fakeEnv = ['SETTINGS_FILE' => "$tmp/fake-settings.json", 'DATABASE_PATH' => "$tmp/fake.db", 'APP_URL' => 'https://kunde.example.com', 'JWT_SECRET' => 'fake-secret-fake-secret-1234', 'UPLOAD_DIR' => "$tmp/fake-up", 'BACKUP_DIR' => "$tmp/fake-bk"] + $cleanEnv;
cli($fakeProd, 'migrate.php', [], $fakeEnv);
[$code, $out] = cli($fakeProd, 'update.php', ['--install'], $fakeEnv);
check('Gefälschter Update-Server (falsche Signatur) wird erkannt, nichts installiert', $code === 1 && str_contains($out, 'gültige Antwort') && trim((string) file_get_contents("$fakeProd/VERSION")) === '0.1.0-beta', $out);

// Der echte Update-Lauf – vorher eine Datei „kaputt machen“ und eine alte Datei hinzufügen
file_put_contents("$prod/src/Support/Dates.php", $before . "\n// kaputt\n");
file_put_contents("$prod/src/Support/AltlastDieEsNichtMehrGibt.php", '<?php // obsolet');
$manifestOld = is_file("$prod/manifest.json") ? json_decode((string) file_get_contents("$prod/manifest.json"), true) : ['files' => []];
$manifestOld['files'][] = ['path' => 'src/Support/AltlastDieEsNichtMehrGibt.php', 'sha256' => hash('sha256', '<?php // obsolet')];
file_put_contents("$prod/manifest.json", json_encode($manifestOld));
$settingsBefore = file_exists("$tmp/product-settings.json") ? file_get_contents("$tmp/product-settings.json") : '';
$productJsonBefore = file_get_contents("$prod/product.json");
$clientsBefore = $P('GET', '/api/clients?pageSize=1', null, $T)[1]['meta']['total'];
[$code, $out] = cli($prod, 'update.php', ['--install'], $prodEnv);
check('Update wird eingespielt (CLI meldet 0.1.0-beta → 9.9.9 und die Sicherung)', $code === 0 && str_contains($out, '0.1.0-beta → 9.9.9') && str_contains($out, 'code-vor-update'), $out);
check('VERSION ist 9.9.9, manipulierte Datei wurde repariert, veraltete Datei entfernt', trim((string) file_get_contents("$prod/VERSION")) === '9.9.9' && file_get_contents("$prod/src/Support/Dates.php") === $before && !is_file("$prod/src/Support/AltlastDieEsNichtMehrGibt.php"));
check('Einstellungen, product.json und Datenbank bleiben unangetastet', (file_exists("$tmp/product-settings.json") ? file_get_contents("$tmp/product-settings.json") : '') === $settingsBefore && file_get_contents("$prod/product.json") === $productJsonBefore && is_file("$tmp/product.db") && $P('GET', '/api/clients?pageSize=1', null, $T)[1]['meta']['total'] === $clientsBefore);
$backups = glob("$tmp/product-bk/code-vor-update-0.1.0-beta-*.zip") ?: [];
check('Code-Sicherung der alten Version liegt im Backup-Ordner (und ein Daten-Backup)', count($backups) === 1 && count(glob("$tmp/product-bk/crm-backup-*.zip") ?: []) >= 1, $backups);
$res = $P('GET', '/api/system/status', null, $T);
check('Installation läuft nach dem Update; Version und Update-Verlauf stimmen', $res[0] === 200 && $res[1]['version'] === '9.9.9' && $res[1]['history'][0]['to'] === '9.9.9', $res[2]);
[$code, $out] = cli($prod, 'update.php', ['--check'], $prodEnv);
check('Danach: kein weiteres Update', $code === 0 && str_contains($out, 'kein neueres Update'), $out);
check('Download-Zähler beim Release steigt', call($vp, 'GET', '/api/releases', null, $A)[1][0]['downloads'] >= 2);

// Rücknahme per Kommandozeile
file_put_contents("$prod/src/Support/Dates.php", "<?php // vom Update ersetzt\n");
[$code, $out] = cli($prod, 'update.php', ['--rollback=' . $backups[0]], $prodEnv);
check('Rücksicherung aus der Code-Sicherung (--rollback): Stand von vor dem Update', $code === 0 && trim((string) file_get_contents("$prod/VERSION")) === '0.1.0-beta' && file_get_contents("$prod/src/Support/Dates.php") === $before . "\n// kaputt\n", $out);

// Fehlschlagende Migration: automatische Rücknahme
$r2 = $up($buildZip('9.9.10', ['database/migrations/999_kaputt.sql' => "THIS IS NOT VALID SQL;\n"]), 'stable', true);
check('Paket mit fehlerhafter Migration wird hochgeladen', $r2[0] === 201, $r2[2]);
call($vp, 'PATCH', '/api/releases/' . $rel1['id'], ['published' => false], $A); // 9.9.9 zurückziehen, damit die fehlerhafte Version die neueste ist
file_put_contents("$prod/src/Support/Dates.php", $before); // wieder sauber, damit die Rücknahme unten eindeutig prüfbar ist
[$code, $out] = cli($prod, 'update.php', ['--install'], $prodEnv);
check('Fehlerhafte Migration: Update wird zurückgenommen (Version und Dateien wie vorher)', $code === 1 && str_contains($out, 'zurückgenommen') && trim((string) file_get_contents("$prod/VERSION")) === '0.1.0-beta' && !is_file("$prod/database/migrations/999_kaputt.sql") && file_get_contents("$prod/src/Support/Dates.php") === $before, $out);
check('… die Anwendung läuft danach weiter', $P('GET', '/api/settings', null, $T)[0] === 200);
call($vp, 'DELETE', '/api/releases/' . $r2[1]['id'], null, $A);

// Beta-Kanal
call($vp, 'PATCH', '/api/releases/' . $rel1['id'], ['published' => true, 'channel' => 'beta'], $A);
check('Beta-Versionen bekommt nur, wer den Beta-Kanal wählt', $signedCall($vp, ['version' => '0.1.0-beta'])[1]['latest'] === null && $signedCall($vp, ['version' => '0.1.0-beta', 'channel' => 'beta'])[1]['latest']['version'] === '9.9.9');
call($vp, 'DELETE', '/api/releases/' . $rel1['id'], null, $A);
check('Gelöschte Version ist weg (inkl. Datei)', !is_file($storedPath) && $signedCall($vp, ['version' => '0.1.0-beta'])[1]['latest'] === null);

/* ---------- Verpasste Versionen und Zwischenschritte ---------- */
echo "Verpasste Versionen, Zwischenschritte\n";
$r1 = $up($buildZip('9.9.1'), 'stable', true);
$r2 = $up($buildZip('9.9.2'), 'stable', true);
$r3 = $up($buildZip('9.9.3'), 'stable', false, '9.9.2');
check('Releases mit Mindestversion werden angelegt', $r1[0] === 201 && $r3[0] === 201 && $r3[1]['minFrom'] === '9.9.2', [$r1[2], $r3[2]]);
expect('Ungültige Mindestversion → 400', $up($buildZip('9.9.8'), 'stable', true, 'abc'), 400);
[$r, $p, $ok] = $signedCall($vp, ['version' => '0.1.0-beta']);
check('Prüfung: neueste sichtbare ist 9.9.2 (Entwurf 9.9.3 unsichtbar), zwei Änderungen in der richtigen Reihenfolge', $ok && $p['target']['version'] === '9.9.2' && $p['latest']['version'] === '9.9.2' && count($p['changes']) === 2 && $p['changes'][0]['version'] === '9.9.1', $p);
$pubDir = "$tmp/product-public";
copyProject($root, $pubDir);
file_put_contents("$pubDir/product.json", json_encode(['product' => 'crm', 'server' => "http://127.0.0.1:$vp", 'publicKeys' => [$pubKey['publicKey']]]));
$pubEnv = $cleanEnv + ['DATABASE_PATH' => "$tmp/pub.db", 'SETTINGS_FILE' => "$tmp/pub-settings.json", 'JWT_SECRET' => 'public-secret-public-secret-001', 'UPLOAD_DIR' => "$tmp/pub-up", 'BACKUP_DIR' => "$tmp/pub-bk", 'APP_URL' => 'https://beta.example.com'];
cli($pubDir, 'migrate.php', [], $pubEnv);
[$code, $out] = cli($pubDir, 'update.php', ['--check'], $pubEnv);
check('„--check“ listet die Änderungen aller verpassten Versionen', $code === 0 && str_contains($out, 'Neu in crm-9.9.1') && str_contains($out, 'Neu in crm-9.9.2'), $out);
[$code, $out] = cli($pubDir, 'update.php', ['--install'], $pubEnv);
check('Verpasste Version wird übersprungen: ein einziger Schritt 0.1.0-beta → 9.9.2', $code === 0 && substr_count($out, 'eingespielt') === 1 && str_contains($out, '0.1.0-beta → 9.9.2') && trim((string) file_get_contents("$pubDir/VERSION")) === '9.9.2', $out);
call($vp, 'PATCH', '/api/releases/' . $r3[1]['id'], ['published' => true], $A);
[$code, $out] = cli($pubDir, 'update.php', ['--install'], $pubEnv);
check('Mindestversion erfüllt: direkt 9.9.2 → 9.9.3', $code === 0 && str_contains($out, '9.9.2 → 9.9.3') && trim((string) file_get_contents("$pubDir/VERSION")) === '9.9.3', $out);
[$code, $out] = cli($pubDir, 'update.php', ['--install'], $pubEnv);
check('Danach: nichts mehr zu tun', $code === 1 && str_contains($out, 'neuesten Stand'), $out);
$hopDir = "$tmp/product-hop";
copyProject($root, $hopDir);
file_put_contents("$hopDir/product.json", json_encode(['product' => 'crm', 'server' => "http://127.0.0.1:$vp", 'publicKeys' => [$pubKey['publicKey']]]));
$hopEnv = array_merge($pubEnv, ['DATABASE_PATH' => "$tmp/hop.db", 'SETTINGS_FILE' => "$tmp/hop-settings.json", 'UPLOAD_DIR' => "$tmp/hop-up", 'BACKUP_DIR' => "$tmp/hop-bk"]);
cli($hopDir, 'migrate.php', [], $hopEnv);
[$code, $out] = cli($hopDir, 'update.php', ['--install'], $hopEnv);
check('Version mit Mindestversion: erst Zwischenstand 9.9.2, dann automatisch 9.9.3 (zwei Schritte in einem Aufruf)', $code === 0 && substr_count($out, 'eingespielt') === 2 && str_contains($out, '0.1.0-beta → 9.9.2') && str_contains($out, '9.9.2 → 9.9.3') && trim((string) file_get_contents("$hopDir/VERSION")) === '9.9.3', $out);
foreach ([$r1, $r2, $r3] as $rr) {
    call($vp, 'DELETE', '/api/releases/' . $rr[1]['id'], null, $A);
}

/* ---------- Release und Installationspaket aus dem Server ---------- */
echo "Release und Installationspaket per Klick\n";
$si = call($vp, 'GET', '/api/releases/self', null, $A);
check('Server nennt seine Version und ob schon ein Release existiert', $si[0] === 200 && $si[1]['version'] === '0.1.0-beta' && $si[1]['released'] === false && $si[1]['serverUrl'] === 'https://update.example.com', $si[2]);
$res = call($vp, 'POST', '/api/releases/build-self', ['channel' => 'stable', 'published' => true, 'notes' => 'Erste Beta'], $A);
check('Ein Klick: signiertes Update-Paket entsteht und ist veröffentlicht', $res[0] === 201 && $res[1]['version'] === '0.1.0-beta' && $res[1]['isPublished'] === true, $res[2]);
expect('Dieselbe Version noch einmal → 409', call($vp, 'POST', '/api/releases/build-self', ['published' => true], $A), 409);
$pkg = call($vp, 'GET', '/api/releases/install-package', null, $A);
$zz = new ZipArchive();
$tmpz = "$tmp/install-pkg.zip";
file_put_contents($tmpz, $pkg[2]);
$okZip = $zz->open($tmpz) === true;
$pj = $okZip ? json_decode((string) $zz->getFromName('crm/product.json'), true) : [];
$entries = $okZip ? array_map(static fn ($i) => $zz->getNameIndex($i), range(0, $zz->numFiles - 1)) : [];
check('Installationspaket: install.php, product.json mit Update-Server und öffentlichem Schlüssel, keine Lizenzpflicht', $pkg[0] === 200 && $okZip && in_array('crm/public/install.php', $entries, true) && $pj['server'] === 'https://update.example.com' && $pj['publicKeys'] === [$pubKey['publicKey']] && !isset($pj['enforce']), [$pkg[0], $pj]);
check('Installationspaket enthält weder Datenbank noch Schlüssel noch .env', $okZip && !preg_grep('/\.db$|\.key$|(^|\/)\.env$|settings\.json|tests\//', $entries));
expect('Installationspaket nur für Admins', call($vp, 'GET', '/api/releases/install-package', null, $mt), 403);
$zz->close();
foreach (call($vp, 'GET', '/api/releases', null, $A)[1] as $x) {
    call($vp, 'DELETE', '/api/releases/' . $x['id'], null, $A);
}

/* ---------- Installer ---------- */
echo "Installer\n";
$inst = "$tmp/product-install";
copyProject($root, $inst);
file_put_contents("$inst/product.json", json_encode(['product' => 'crm', 'server' => "http://127.0.0.1:$vp", 'publicKeys' => [$pubKey['publicKey']]]));
$instEnv = $cleanEnv + ['SETTINGS_FILE' => "$tmp/inst-settings.json", 'DATABASE_PATH' => "$tmp/inst.db"];
startServer($inst, $ip, $instEnv, "$tmp/inst.log");
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
check('Installer fragt keinen Lizenzschlüssel ab', $s === 200 && !str_contains($page, 'license_key') && !str_contains($page, 'Lizenz'), substr($page, 0, 100));
$form = ['csrf' => $m[1] ?? '', 'action' => 'install', 'company' => 'Test GmbH', 'owner' => 'Test', 'username' => 'admin', 'email' => 'a@test.example', 'password' => 'langes-passwort-1', 'password2' => 'langes-passwort-1', 'url' => 'https://installiert.example.com', 'db' => 'sqlite', 'catalog' => '1'];
[$s, $page] = $curl('POST', '/install.php', $form, $jar);
check('Installation gelingt und sperrt sich', str_contains($page, 'Fertig') && is_file("$inst/database/installed.lock"), substr(strip_tags($page), 0, 400));
$it = call($ip, 'POST', '/api/auth/login', ['email' => 'admin', 'password' => 'langes-passwort-1'])[1]['token'] ?? '';
$st = call($ip, 'GET', '/api/system/status', null, $it);
check('Installierte Anwendung: anmelden, Update-Server eingetragen, Katalog nutzbar', $st[0] === 200 && $st[1]['updates']['configured'] === true && call($ip, 'GET', '/api/products', null, $it)[0] === 200, $st[2]);

echo "\n$passed bestanden, $failed fehlgeschlagen\n";
exit($failed > 0 ? 1 : 0);
