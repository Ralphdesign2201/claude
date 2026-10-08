<?php
declare(strict_types=1);

/** Gemeinsame Teile von install.php (Einzelinstallation) und superinstall.php (SaaS). */

function inst_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function inst_requirements(bool $saas): array {
    $w = fn(string $d) => is_dir($d) ? is_writable($d) : @mkdir($d, 0775, true);
    $r = [];
    $r[] = ['PHP-Version 8.0 oder neuer (hier ' . PHP_VERSION . ')', PHP_VERSION_ID >= 80000, true];
    $r[] = ['Ordner storage/ beschreibbar', $w(APP_STORAGE), true];
    $r[] = ['Erweiterung mbstring', extension_loaded('mbstring'), true];
    $r[] = ['Erweiterung pdo_sqlite' . ($saas ? '' : ' (oder pdo_mysql)'), extension_loaded('pdo_sqlite'), $saas || !extension_loaded('pdo_mysql')];
    if (!$saas) $r[] = ['Erweiterung pdo_mysql (nur für MySQL-Betrieb)', extension_loaded('pdo_mysql'), false];
    $r[] = ['Erweiterung sodium (Prüfung der Update-Signatur)', extension_loaded('sodium'), false];
    $r[] = ['Erweiterung zlib (komprimierte Backups/Updates)', function_exists('gzencode'), false];
    $r[] = ['Erweiterung gd (PNG-Logos, Bildumwandlung)', extension_loaded('gd'), false];
    if ($saas) $r[] = ['Erweiterung curl (PayPal- und Kreditkarten-Zahlungen)', extension_loaded('curl'), false];
    if ($saas) $r[] = ['Erweiterung openssl (HTTPS-Aufrufe)', extension_loaded('openssl'), false];
    return $r;
}
function inst_blocking(array $req): bool { foreach ($req as [$l, $ok, $must]) if ($must && !$ok) return true; return false; }

function inst_page(string $title, string $body): void {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>' . inst_h($title) . '</title><style>'
        . ':root{--bg:#f1f4f8;--card:#fff;--text:#1d2733;--muted:#66727f;--line:#d9e0e8;--accent:#1e4a7e;--ok:#1f7a4d;--bad:#b3261e;--warn:#9a6200}'
        . '@media(prefers-color-scheme:dark){:root{--bg:#12161c;--card:#1b222b;--text:#e6ebf1;--muted:#93a0ae;--line:#2b3541;--accent:#5b9bdc;--ok:#4cc38a;--bad:#ff8a80;--warn:#e8b34a}}'
        . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}'
        . 'main{max-width:680px;margin:0 auto;padding:28px 16px 60px}h1{font-size:1.5rem;margin:.2em 0}h2{font-size:1.1rem;margin:1.6em 0 .4em}.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:20px;margin:16px 0}'
        . 'label{display:block;font-size:.88rem;color:var(--muted);margin-top:12px}input,select{display:block;width:100%;margin-top:4px;padding:9px 11px;font:inherit;color:var(--text);background:var(--bg);border:1px solid var(--line);border-radius:7px}'
        . 'input[type=radio],input[type=checkbox]{display:inline;width:auto;margin:0 6px 0 0}.row{display:grid;grid-template-columns:1fr 1fr;gap:0 14px}@media(max-width:560px){.row{grid-template-columns:1fr}}'
        . '.btn{display:inline-block;margin-top:18px;padding:11px 20px;border:0;border-radius:8px;background:var(--accent);color:#fff;font:inherit;font-weight:600;cursor:pointer;text-decoration:none}.btn.alt{background:transparent;color:var(--accent);border:1px solid var(--line)}'
        . '.msg{padding:10px 14px;border-radius:8px;margin:12px 0;border:1px solid var(--line)}.err{border-color:var(--bad);color:var(--bad)}.ok{border-color:var(--ok);color:var(--ok)}'
        . 'ul.req{list-style:none;padding:0;margin:0}ul.req li{padding:4px 0}.y{color:var(--ok)}.n{color:var(--bad)}.w{color:var(--warn)}.muted{color:var(--muted)}code{background:var(--bg);padding:1px 6px;border-radius:5px;word-break:break-all}'
        . '</style></head><body><main>' . $body . '</main></body></html>';
}

function inst_req_html(array $req): string {
    $h = '<ul class="req">';
    foreach ($req as [$l, $ok, $must]) $h .= '<li class="' . ($ok ? 'y' : ($must ? 'n' : 'w')) . '">' . ($ok ? '✓' : ($must ? '✗' : '!')) . ' ' . inst_h($l) . ($ok ? '' : ($must ? ' – <b>erforderlich</b>' : ' – empfohlen')) . '</li>';
    return $h . '</ul>';
}

function inst_token(): string { if (empty($_SESSION['inst'])) $_SESSION['inst'] = bin2hex(random_bytes(16)); return $_SESSION['inst']; }
function inst_check(): void { if (!hash_equals($_SESSION['inst'] ?? '', (string)($_POST['t'] ?? ''))) { http_response_code(400); inst_page('Fehler', '<h1>Formular abgelaufen</h1><p>Bitte die Seite neu laden.</p>'); exit; } }

function inst_valid_user(string $u): bool { return (bool)preg_match('/^[A-Za-z0-9._@-]{3,60}$/', $u); }

function inst_selfdelete(string $file): bool { return @unlink($file); }

/** Basis-URL der Installation (Schema + Host + Pfad), nur bei gültigem Host. */
function inst_base_url(): string {
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d{1,5})?$/i', $host)) $host = 'localhost';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}
function inst_is_https(): bool { return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'; }

/** Prüft, ob storage/ und app/ von außen lesbar sind. @return array ['ok'=>bool|null, 'msg'=>string] */
function inst_probe(string $root): array {
    $marker = bin2hex(random_bytes(8));
    $probes = ['storage' => $root . '/storage/_probe_' . $marker . '.txt', 'app' => $root . '/app/_probe_' . $marker . '.txt'];
    $exposed = []; $unknown = false;
    foreach ($probes as $dir => $file) {
        @file_put_contents($file, $marker);
        $url = inst_base_url() . '/' . $dir . '/' . basename($file);
        $body = false;
        if (function_exists('curl_init')) { $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => false]); $body = curl_exec($ch); if (curl_errno($ch)) $unknown = true; curl_close($ch); }
        else { $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]); $body = @file_get_contents($url, false, $ctx); if ($body === false) $unknown = true; }
        if (is_string($body) && str_contains($body, $marker)) $exposed[] = $dir . '/';
        @unlink($file);
    }
    if ($exposed) return ['ok' => false, 'msg' => 'ACHTUNG: ' . implode(' und ', $exposed) . ' ist von außen erreichbar! Bitte Document-Root auf den Ordner public/ stellen oder die Ordner in der Server-Konfiguration sperren (nginx: location ~ ^/(app|storage)/ { deny all; }), bevor Sie das Programm nutzen.'];
    return ['ok' => $unknown ? null : true, 'msg' => $unknown ? 'Der automatische Test, ob storage/ und app/ geschützt sind, war nicht möglich. Bitte manuell prüfen: Aufruf von ' . inst_base_url() . '/storage/config.php darf nichts anzeigen (403/404).' : 'storage/ und app/ sind von außen nicht lesbar ✓'];
}
