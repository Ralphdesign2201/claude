<?php
declare(strict_types=1);
require_once APP_ROOT . '/update.php';

function updates_prefix(): string { return is_saas() ? 'sa_' : ''; }
function updates_url(string $r, array $p = []): string { return url(updates_prefix() . $r, $p); }

function updates_index(): void {
    $pending = null;
    $tok = (string)($_GET['token'] ?? '');
    if (preg_match('/^[0-9a-f]{32}$/', $tok) && is_file(update_pending_dir() . '/' . $tok . '.rgu')) {
        try {
            $pkg = update_decode((string)file_get_contents(update_pending_dir() . '/' . $tok . '.rgu'));
            $plan = update_plan($pkg, app_version());
            $pending = ['token' => $tok, 'plan' => $plan, 'files' => count($plan['write']), 'deleted' => count($plan['delete']), 'target' => $pkg['version']];
        } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    }
    render('updates', ['pending' => $pending, 'history' => update_history(), 'maxUpload' => ini_get('upload_max_filesize'), 'sodium' => function_exists('sodium_crypto_sign_verify_detached')], 'Updates');
}

function updates_upload(): void {
    csrf_check();
    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        flash($f && $f['error'] === UPLOAD_ERR_INI_SIZE ? 'Die Datei ist größer als das Upload-Limit des Servers (upload_max_filesize).' : 'Bitte eine Update-Datei (.rgu) auswählen.', 'err');
        redirect(updates_prefix() . 'updates');
    }
    $raw = (string)file_get_contents($f['tmp_name']);
    try { $pkg = update_decode($raw); update_plan($pkg, app_version()); }
    catch (Throwable $e) { flash($e->getMessage(), 'err'); redirect(updates_prefix() . 'updates'); }
    foreach (glob(update_pending_dir() . '/*.rgu') ?: [] as $old) if (filemtime($old) < time() - 86400) @unlink($old);
    $tok = bin2hex(random_bytes(16));
    file_put_contents(update_pending_dir() . '/' . $tok . '.rgu', $raw);
    redirect(updates_prefix() . 'updates', ['token' => $tok]);
}

function updates_install(): void {
    csrf_check();
    $tok = post('token');
    $f = update_pending_dir() . '/' . $tok . '.rgu';
    if (!preg_match('/^[0-9a-f]{32}$/', $tok) || !is_file($f)) { flash('Update nicht gefunden – bitte erneut hochladen.', 'err'); redirect(updates_prefix() . 'updates'); }
    try {
        $pkg = update_decode((string)file_get_contents($f));
        $plan = update_plan($pkg, app_version());
        // Sicherheitskopie der Daten vor dem Update
        if (is_saas()) { @mkdir(APP_STORAGE . '/update_backup', 0775, true); @copy(APP_STORAGE . '/central.sqlite', APP_STORAGE . '/update_backup/central_before_' . app_version() . '_' . date('Ymd_His') . '.sqlite'); }
        else backup_create('safety');
        update_apply($plan);
        update_history_add(['version' => $plan['target'], 'from' => app_version(), 'at' => date('c'), 'steps' => array_map(fn($s) => ['version' => $s['version'], 'notes' => $s['notes'] ?? []], $plan['steps'])]);
        @unlink($f);
        audit('update_installed', 'auf Version ' . $plan['target'], null, is_saas());
    } catch (Throwable $e) {
        error_log('Update: ' . $e->getMessage());
        flash('Update fehlgeschlagen, es wurde nichts verändert: ' . $e->getMessage(), 'err');
        redirect(updates_prefix() . 'updates');
    }
    redirect(updates_prefix() . 'update_finish');
}

/** Läuft nach dem Dateischreiben in einem frischen Request mit dem neuen Code. */
function updates_finish(): void {
    $ran = [];
    if (is_saas()) {
        cdb();
        set_csetting('app_version', app_version());
        $t0 = time(); $n = 0;
        foreach (cq('SELECT * FROM tenants WHERE db_version <> ?', [app_version()]) as $t) { if (time() - $t0 > 20) break; tenant_migrate_if_needed($t); $n++; }
        $left = (int)cq1('SELECT COUNT(*) AS c FROM tenants WHERE db_version <> ?', [app_version()])['c'];
        flash('Version ' . app_version() . ' ist installiert. ' . $n . ' Mandanten wurden aktualisiert' . ($left ? ", $left folgen automatisch beim nächsten Login oder Cron-Lauf." : '.'));
    } else {
        $ran = run_pending_migrations(db());
        set_setting('app_version', app_version());
        flash('Version ' . app_version() . ' ist installiert.' . ($ran ? ' Datenbank-Anpassungen: ' . count($ran) . '.' : ''));
    }
    redirect(updates_prefix() . 'updates');
}
