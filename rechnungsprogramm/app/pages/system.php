<?php
declare(strict_types=1);

function system_cron_url(): string {
    return app_base_url() . '/cron.php?token=' . setting('cron_token');
}
function system_cron_path(): string {
    return is_file(dirname(APP_ROOT) . '/public/cron.php') ? dirname(APP_ROOT) . '/public/cron.php' : dirname(APP_ROOT) . '/cron.php';
}

function system_password_ok(): bool {
    return password_verify((string)($_POST['confirm_password'] ?? ''), (string)current_user()['password_hash']);
}

function system_backups(): void {
    if (setting('cron_token') === '') set_setting('cron_token', bin2hex(random_bytes(16)));
    $cfg = app_config();
    render('backups', [
        'list' => backup_list(), 'driver' => db_driver(), 'counts' => db_counts(db()), 'mysql' => $cfg['mysql'],
        'interval' => setting('backup_interval', 'off'), 'keep' => setting('backup_keep', '7'), 'pseudo' => setting('backup_pseudo') === '1',
        'cronUrl' => system_cron_url(), 'cronCmd' => 'php ' . system_cron_path(), 'gz' => function_exists('gzencode'),
        'mysqlOk' => extension_loaded('pdo_mysql'), 'sqliteOk' => extension_loaded('pdo_sqlite'),
        'lastAuto' => (function () { foreach (backup_list() as $b) if ($b['kind'] === 'auto') return $b['ts']; return 0; })(),
    ], 'Datenbank & Backups');
}

function system_save(): void {
    csrf_check();
    $iv = post('backup_interval'); if (!in_array($iv, ['off', 'daily', 'weekly', 'monthly'], true)) $iv = 'off';
    set_setting('backup_interval', $iv);
    set_setting('backup_keep', (string)max(1, min(365, (int)post('backup_keep', '7'))));
    set_setting('backup_pseudo', isset($_POST['backup_pseudo']) ? '1' : '0');
    if (isset($_POST['new_token'])) set_setting('cron_token', bin2hex(random_bytes(16)));
    $n = backup_prune();
    flash('Backup-Einstellungen gespeichert.' . ($n ? " $n alte Sicherung(en) gelöscht." : ''));
    redirect('backups');
}

function system_create(): void {
    csrf_check();
    try { $n = backup_create('manual'); audit('backup_created', $n); flash('Backup erstellt: ' . $n); }
    catch (Throwable $e) { flash('Backup fehlgeschlagen: ' . $e->getMessage(), 'err'); }
    redirect('backups');
}

function system_download(): void {
    $n = (string)($_GET['name'] ?? '');
    $f = backup_dir() . '/' . $n;
    if (!backup_valid_name($n) || !is_file($f)) { http_response_code(404); exit('Nicht gefunden.'); }
    audit('backup_downloaded', $n);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $n . '"');
    header('Content-Length: ' . filesize($f));
    header('Cache-Control: private, no-store');
    readfile($f);
}

function system_delete(): void {
    csrf_check();
    $n = post('name');
    if (backup_valid_name($n) && is_file(backup_dir() . '/' . $n)) { @unlink(backup_dir() . '/' . $n); audit('backup_deleted', $n); flash('Backup gelöscht.'); }
    redirect('backups');
}

function system_upload(): void {
    csrf_check();
    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        flash($f && $f['error'] === UPLOAD_ERR_INI_SIZE ? 'Die Datei ist größer als das Upload-Limit des Servers (upload_max_filesize).' : 'Bitte eine Backup-Datei auswählen.', 'err');
        redirect('backups');
    }
    try { backup_decode((string)file_get_contents($f['tmp_name'])); }
    catch (Throwable $e) { flash($e->getMessage(), 'err'); redirect('backups'); }
    $name = 'backup_upload_' . date('Ymd_His') . '_' . bin2hex(random_bytes(2)) . '.rgb';
    move_uploaded_file($f['tmp_name'], backup_dir() . '/' . $name);
    @chmod(backup_dir() . '/' . $name, 0600);
    audit('backup_uploaded', $name);
    flash('Backup hochgeladen. Sie können es jetzt wiederherstellen.');
    redirect('backups');
}

function system_restore(): void {
    csrf_check();
    $n = post('name'); $f = backup_dir() . '/' . $n;
    if (!backup_valid_name($n) || !is_file($f)) { flash('Backup nicht gefunden.', 'err'); redirect('backups'); }
    if (!rate_hit('confirm_pw', 5, 600, (string)current_user()['id'])) { flash('Zu viele Versuche. Bitte später erneut versuchen.', 'err'); redirect('backups'); }
    if (!system_password_ok()) { audit('restore_denied', 'Falsches Passwort bei Wiederherstellung'); flash('Passwort falsch – es wurde nichts verändert.', 'err'); redirect('backups'); }
    try {
        audit('restore_started', $n);
        backup_restore(backup_decode((string)file_get_contents($f)));
    } catch (Throwable $e) { error_log($e->getMessage()); flash('Wiederherstellung fehlgeschlagen: ' . $e->getMessage() . ' (Die Sicherheitskopie vor dem Versuch liegt in der Liste.)', 'err'); redirect('backups'); }
    $_SESSION = []; session_regenerate_id(true);
    flash('Backup wiederhergestellt. Bitte erneut anmelden (Benutzer stammen jetzt aus dem Backup).');
    redirect('login');
}

function system_mysql_from_post(): array {
    return ['host' => post('db_host', 'localhost'), 'port' => (int)(post('db_port') ?: 3306), 'name' => post('db_name'), 'user' => post('db_user'), 'pass' => (string)($_POST['db_pass'] ?? '')];
}

function system_db_test(): void {
    csrf_check();
    $m = system_mysql_from_post();
    try {
        $pdo = db_connect(['driver' => 'mysql', 'mysql' => $m]);
        $has = db_has_data($pdo);
        flash('Verbindung zu „' . $m['name'] . '“ erfolgreich (MySQL/MariaDB ' . $pdo->query('SELECT VERSION()')->fetchColumn() . ').' . ($has ? ' Achtung: In dieser Datenbank liegen bereits Programmdaten.' : ''));
    } catch (Throwable $e) { flash('Verbindung fehlgeschlagen: ' . $e->getMessage(), 'err'); }
    redirect('backups');
}

function system_db_switch(): void {
    csrf_check();
    if (!rate_hit('confirm_pw', 5, 600, (string)current_user()['id'])) { flash('Zu viele Versuche. Bitte später erneut versuchen.', 'err'); redirect('backups'); }
    if (!system_password_ok()) { flash('Passwort falsch – es wurde nichts verändert.', 'err'); redirect('backups'); }
    $to = post('target');
    try {
        if ($to === 'mysql') { $m = system_mysql_from_post(); $res = db_switch(['driver' => 'mysql', 'mysql' => $m], isset($_POST['overwrite'])); }
        elseif ($to === 'sqlite') $res = db_switch(['driver' => 'sqlite'], isset($_POST['overwrite']));
        else throw new RuntimeException('Unbekanntes Ziel.');
        audit('db_switched', 'Ziel: ' . $to);
        flash('Umstellung abgeschlossen: ' . ($to === 'mysql' ? 'MySQL/MariaDB' : 'SQLite') . ' ist jetzt aktiv (' . ($res['invoices'] ?? 0) . ' Rechnungen, ' . ($res['customers'] ?? 0) . ' Kunden übernommen). Die alte Datenbank bleibt unverändert als Rückfall erhalten.');
    } catch (Throwable $e) { error_log($e->getMessage()); flash('Umstellung fehlgeschlagen: ' . $e->getMessage(), 'err'); }
    redirect('backups');
}
