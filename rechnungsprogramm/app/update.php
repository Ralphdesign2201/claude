<?php
declare(strict_types=1);
require_once APP_ROOT . '/update_pubkey.php';

const UPDATE_FORMAT = 'rechnungsprogramm-update';

function update_path_ok(string $p): bool {
    if (!preg_match('#^(app|public)/[A-Za-z0-9._/-]+$#', $p) && !in_array($p, ['index.php', 'cron.php'], true)) return false;
    if (str_contains($p, '..') || str_contains($p, '//') || str_ends_with($p, '/')) return false;
    return true;
}

/** Liest und prüft ein Update-Paket (Signatur, Format). @return array Nutzdaten */
function update_decode(string $raw): array {
    if (!function_exists('sodium_crypto_sign_verify_detached')) throw new RuntimeException('Die PHP-Erweiterung „sodium“ fehlt – sie ist zur Prüfung der Update-Signatur nötig. Bitte beim Hoster aktivieren.');
    if (strncmp($raw, "\x1f\x8b", 2) === 0) {
        if (!function_exists('gzdecode')) throw new RuntimeException('PHP-zlib fehlt.');
        $raw = @gzdecode($raw, 300 * 1024 * 1024);
        if ($raw === false) throw new RuntimeException('Die Update-Datei ist beschädigt.');
    }
    $outer = json_decode($raw, true);
    if (!is_array($outer) || !isset($outer['payload'], $outer['sig'])) throw new RuntimeException('Das ist keine Update-Datei dieses Programms.');
    $sig = base64_decode((string)$outer['sig'], true);
    $pub = base64_decode(UPDATE_PUBLIC_KEY, true);
    if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || !sodium_crypto_sign_verify_detached($sig, (string)$outer['payload'], $pub))
        throw new RuntimeException('Die Signatur der Update-Datei ist ungültig. Es werden nur Originaldateien des Herstellers installiert.');
    $p = json_decode((string)$outer['payload'], true);
    if (!is_array($p) || ($p['format'] ?? '') !== UPDATE_FORMAT || !is_array($p['steps'] ?? null) || !isset($p['version'], $p['prev'])) throw new RuntimeException('Update-Datei hat ein unbekanntes Format.');
    foreach (array_keys($p['blobs'] ?? []) as $path) if (!update_path_ok((string)$path)) throw new RuntimeException('Update enthält einen nicht erlaubten Pfad.');
    foreach ($p['steps'] as $st) foreach (array_merge($st['paths'] ?? [], $st['deleted'] ?? []) as $path) if (!update_path_ok((string)$path)) throw new RuntimeException('Update enthält einen nicht erlaubten Pfad.');
    return $p;
}

/** Welche Schritte sind noch nicht installiert? @return array ['steps'=>[], 'write'=>[path=>bytes], 'delete'=>[path]] */
function update_plan(array $pkg, string $installed): array {
    if (($pkg['product'] ?? APP_NAME) !== APP_NAME) throw new RuntimeException('Diese Update-Datei gehört zu einem anderen Produkt.');
    if (version_compare($pkg['version'], $installed, '<=')) throw new RuntimeException('Version ' . $installed . ' ist bereits installiert – diese Datei (Version ' . $pkg['version'] . ') enthält nichts Neues.');
    if (version_compare($installed, (string)$pkg['prev'], '<')) throw new RuntimeException('Ihre Version ' . $installed . ' ist zu alt für diese Datei (sie setzt mindestens ' . $pkg['prev'] . ' voraus). Bitte zuerst die kumulative Update-Datei mit niedrigerem Startpunkt einspielen.');
    $steps = array_values(array_filter($pkg['steps'], fn($s) => version_compare($s['version'], $installed, '>')));
    usort($steps, fn($a, $b) => version_compare($a['version'], $b['version']));
    $write = []; $delete = [];
    foreach ($steps as $s) foreach ($s['paths'] ?? [] as $path) {
        if (isset($pkg['blobs'][$path])) { $write[$path] = base64_decode($pkg['blobs'][$path], true); unset($delete[$path]); }
    }
    foreach ($steps as $s) foreach ($s['deleted'] ?? [] as $path) if (!isset($pkg['blobs'][$path])) $delete[$path] = true;
    foreach ($write as $path => $bytes) if ($bytes === false) throw new RuntimeException('Der Inhalt von ' . $path . ' ist beschädigt – das Update wurde nicht installiert.');
    return ['steps' => $steps, 'write' => $write, 'delete' => array_keys($delete), 'target' => $pkg['version']];
}

function update_root(): string { return dirname(APP_ROOT); }

/** Schreibt die Dateien (mit Sicherung und Rollback). */
function update_apply(array $plan): string {
    $root = update_root();
    $bk = APP_STORAGE . '/update_backup/' . date('Ymd_His') . '_' . app_version() . '_to_' . $plan['target'];
    if (!is_dir($bk) && !@mkdir($bk, 0775, true)) throw new RuntimeException('storage/ ist nicht beschreibbar.');
    $touched = [];
    $restore = function () use (&$touched, $bk, $root) {
        foreach ($touched as $rel => $had) {
            $dest = $root . '/' . $rel;
            if ($had) @copy($bk . '/files/' . $rel, $dest); else @unlink($dest);
            if (function_exists('opcache_invalidate')) @opcache_invalidate($dest, true);
        }
    };
    try {
        foreach (array_merge(array_keys($plan['write']), $plan['delete']) as $rel) {
            $dest = $root . '/' . $rel;
            $had = is_file($dest);
            if ($had) { $b = $bk . '/files/' . $rel; if (!is_dir(dirname($b))) @mkdir(dirname($b), 0775, true); if (!@copy($dest, $b)) throw new RuntimeException("Sicherung von $rel fehlgeschlagen."); }
            $touched[$rel] = $had;
        }
        foreach ($plan['write'] as $rel => $bytes) {
            if ($bytes === false) throw new RuntimeException("Inhalt von $rel ist ungültig.");
            $dest = $root . '/' . $rel;
            if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0775, true)) throw new RuntimeException('Ordner für ' . $rel . ' nicht anlegbar.');
            $tmp = $dest . '.upd' . bin2hex(random_bytes(3));
            if (file_put_contents($tmp, $bytes) === false || !@rename($tmp, $dest)) { @unlink($tmp); throw new RuntimeException("$rel ist nicht beschreibbar (Dateirechte prüfen)."); }
            if (function_exists('opcache_invalidate')) @opcache_invalidate($dest, true);
        }
        foreach ($plan['delete'] as $rel) { $dest = $root . '/' . $rel; if (is_file($dest)) @unlink($dest); }
    } catch (Throwable $e) {
        $restore();
        throw $e;
    }
    return $bk;
}

function update_history(): array {
    $raw = is_saas() ? csetting('update_history') : setting('update_history');
    return json_decode($raw ?: '[]', true) ?: [];
}
function update_history_add(array $entry): void {
    $h = update_history(); array_unshift($h, $entry); $h = array_slice($h, 0, 30);
    if (is_saas()) set_csetting('update_history', json_encode($h, JSON_UNESCAPED_UNICODE)); else set_setting('update_history', json_encode($h, JSON_UNESCAPED_UNICODE));
}
function update_pending_dir(): string { $d = APP_STORAGE . '/update_pending'; if (!is_dir($d)) @mkdir($d, 0775, true); return $d; }
