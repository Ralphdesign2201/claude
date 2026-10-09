<?php
declare(strict_types=1);
require_once APP_ROOT . '/billing_lib.php';

// ------------------------------------------------------------------ Anmeldung
function sa_complete_login(array $u) {
    session_regenerate_id(true);
    $_SESSION = ['_t0' => time(), '_ua' => $_SESSION['_ua'] ?? '', '_last' => time(), 'sa' => (int)$u['id']];
    account_ok(cdb(), 'superadmins', $u);
    audit('sa_login', 'Superadmin angemeldet', (string)$u['username'], true);
    if (csetting('sa_require_2fa', '1') === '1' && (int)$u['totp_enabled'] === 0) { flash('Bitte richten Sie jetzt die Zwei-Faktor-Anmeldung für Ihr Superadmin-Konto ein.', 'err'); redirect('sa_twofa'); }
    redirect('sa_dashboard');
}

/** Prüft Superadmin-Zugangsdaten. Leitet bei Erfolg weiter (nie zurück), gibt sonst den Fehlertext zurück. */
function sa_try_login(string $username, string $password): string {
    if (rate_count('sa_login', 900) >= 8) return 'Zu viele Anmeldeversuche. Bitte in einigen Minuten erneut versuchen.';
    $u = cq1('SELECT * FROM superadmins WHERE LOWER(username) = LOWER(?) AND active = 1', [$username]);
    $ok = password_verify($password, $u['password_hash'] ?? '$2y$10$usesomesillystringforsaltthatnoonewillguess12345678901234567');
    if ($u && account_locked($u)) { rate_hit('sa_login', 1000, 900); usleep(300000); return 'Benutzername oder Passwort falsch (oder Konto kurz gesperrt).'; }
    if ($u && $ok) {
        if ((int)$u['totp_enabled'] === 1) { $_SESSION['p2'] = ['type' => 'sa', 'id' => (int)$u['id'], 't' => time()]; redirect('sa_login_2fa'); }
        sa_complete_login($u);
    }
    rate_hit('sa_login', 1000, 900); if ($u) account_fail(cdb(), 'superadmins', $u); audit('sa_login_failed', 'Fehlgeschlagen', substr($username, 0, 60), true); usleep(400000);
    return 'Benutzername oder Passwort falsch (oder Konto kurz gesperrt).';
}

function sa_login(): void {
    if (sa_user()) redirect('sa_dashboard');
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_check(); $err = sa_try_login(post('username'), (string)($_POST['password'] ?? '')); }
    render('sa_login', ['err' => $err, 'user' => post('username')], 'Superadmin');
}

function sa_login_2fa(): void {
    $p = $_SESSION['p2'] ?? null;
    if (!$p || ($p['type'] ?? '') !== 'sa' || time() - (int)$p['t'] > 300) { unset($_SESSION['p2']); redirect('sa_login'); }
    $u = cq1('SELECT * FROM superadmins WHERE id = ? AND active = 1', [(int)$p['id']]);
    if (!$u) { unset($_SESSION['p2']); redirect('sa_login'); }
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if (rate_count('sa2fa', 600, 'sa' . $u['id']) >= 6) $err = 'Zu viele Versuche. Bitte in einigen Minuten erneut versuchen.';
        elseif (second_factor_ok(cdb(), 'superadmins', $u, (string)($_POST['code'] ?? ''))) { unset($_SESSION['p2']); rate_reset('sa2fa', 'sa' . $u['id']); sa_complete_login($u); }
        else { rate_hit('sa2fa', 1000, 600, 'sa' . $u['id']); audit('sa_2fa_failed', 'Falscher Code', (string)$u['username'], true); usleep(400000); $err = 'Der Code ist falsch oder abgelaufen.'; }
    }
    render('login_2fa', ['err' => $err, 'action' => 'sa_login_2fa', 'cancel' => 'sa_login'], 'Bestätigung');
}

function sa_logout(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_check(); audit('sa_logout', 'Abmeldung', null, true); $_SESSION = []; if (session_status() === PHP_SESSION_ACTIVE) session_destroy(); header('Location: ' . url('sa_login')); exit; }
    redirect('sa_dashboard');
}

function sa_forgot(): void {
    $done = false; $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if (!rate_hit('sa_forgot', 4, 3600)) $err = 'Zu viele Anfragen. Bitte später erneut versuchen.';
        else {
            $u = cq1("SELECT * FROM superadmins WHERE active = 1 AND email <> '' AND (LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?)) ORDER BY id LIMIT 1", [post('username'), post('username')]);
            if ($u && rate_hit('sa_forgot_u', 3, 3600, (string)$u['id'])) {
                $tok = bin2hex(random_bytes(32));
                cexec('INSERT INTO password_resets(sa_id, token_hash, expires) VALUES (?,?,?)', [$u['id'], hash('sha256', $tok), date('Y-m-d H:i:s', time() + 1800)]);
                central_mail($u['email'], 'Superadmin: Passwort zurücksetzen', "Link (30 Minuten gültig):

" . app_url('sa_reset', ['k' => $tok]) . "

Nicht angefordert? Dann ignorieren Sie diese Nachricht.
");
                audit('sa_reset_requested', 'Passwort-Zurücksetzen angefordert', (string)$u['username'], true);
            }
            usleep(300000); $done = true;
        }
    }
    render('forgot', ['saas' => false, 'done' => $done, 'err' => $err, 'tenant' => '', 'action' => 'sa_forgot', 'back' => 'sa_login'], 'Passwort vergessen');
}

function sa_reset(): void {
    $tok = (string)($_GET['k'] ?? $_POST['k'] ?? '');
    $r = preg_match('/^[0-9a-f]{64}$/', $tok) ? cq1('SELECT r.*, s.username FROM password_resets r JOIN superadmins s ON s.id = r.sa_id WHERE r.token_hash = ? AND r.used = 0 AND r.expires > ? AND s.active = 1', [hash('sha256', $tok), date('Y-m-d H:i:s')]) : null;
    if (!$r) { render('error', ['message' => 'Dieser Link ist ungültig oder abgelaufen.'], 'Link ungültig'); return; }
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check(); $pw = (string)($_POST['password'] ?? '');
        if ($e = password_error($pw, (string)$r['username'], 10)) $err = $e;
        elseif ($pw !== (string)($_POST['password2'] ?? '')) $err = 'Die Passwörter stimmen nicht überein.';
        else {
            cexec('UPDATE superadmins SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $r['sa_id']]);
            cexec('UPDATE password_resets SET used = 1 WHERE sa_id = ?', [$r['sa_id']]);
            audit('sa_password_reset', 'Passwort per Link zurückgesetzt', (string)$r['username'], true);
            flash('Passwort geändert. Bitte melden Sie sich an.'); redirect('sa_login');
        }
    }
    render('reset', ['err' => $err, 'tok' => $tok, 'slug' => null, 'action' => 'sa_reset'], 'Neues Passwort');
}

function sa_profile(): void { render('sa_profile', ['u' => sa_user()], 'Mein Konto'); }
function sa_profile_save(): void {
    csrf_check(); $u = sa_user();
    $email = post('email'); if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('Ungültige E-Mail-Adresse.', 'err'); redirect('sa_profile'); }
    cexec('UPDATE superadmins SET display_name = ?, email = ? WHERE id = ?', [post('display_name'), $email, $u['id']]);
    $new = (string)($_POST['new'] ?? '');
    if ($new !== '') {
        if (!password_verify((string)($_POST['current'] ?? ''), $u['password_hash'])) { flash('Aktuelles Passwort falsch.', 'err'); redirect('sa_profile'); }
        if ($e = password_error($new, (string)$u['username'], 10)) { flash($e, 'err'); redirect('sa_profile'); }
        if ($new !== (string)($_POST['new2'] ?? '')) { flash('Die neuen Passwörter stimmen nicht überein.', 'err'); redirect('sa_profile'); }
        cexec('UPDATE superadmins SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        session_regenerate_id(true); audit('sa_password_changed', 'Eigenes Passwort geändert', null, true);
    }
    flash('Konto gespeichert.'); redirect('sa_profile');
}
function sa_twofa(): void { twofa_page('superadmins', sa_user(), 'sa_twofa', true); }
function sa_audit(): void {
    $q = trim((string)($_GET['q'] ?? '')); $w = ''; $p = [];
    if ($q !== '') { $w = ' WHERE username LIKE ? OR action LIKE ? OR detail LIKE ?'; $p = ["%$q%", "%$q%", "%$q%"]; }
    render('audit', ['rows' => cq('SELECT * FROM audit_log' . $w . ' ORDER BY id DESC LIMIT 300', $p), 'q' => $q, 'route' => 'sa_audit', 'central' => true], 'Protokoll');
}
function sa_back_to_sa(): void { csrf_check(); audit('impersonation_end', 'Zurück zum Superadmin', null, true); unset($_SESSION['tenant'], $_SESSION['uid'], $_SESSION['impersonating']); session_regenerate_id(true); redirect('sa_tenants'); }

// ------------------------------------------------------------------ Dashboard
function sa_dashboard(): void {
    $by = []; foreach (cq('SELECT status, COUNT(*) AS c FROM tenants GROUP BY status') as $r) $by[$r['status']] = (int)$r['c'];
    $mrr = 0;
    foreach (cq("SELECT p.price_cents, p.interval_unit FROM tenants t JOIN plans p ON p.id = t.plan_id WHERE t.status IN ('active','past_due') AND t.provider <> ''") as $r) $mrr += $r['interval_unit'] === 'year' ? intdiv((int)$r['price_cents'], 12) : (int)$r['price_cents'];
    $rev30 = (int)(cq1("SELECT COALESCE(SUM(amount_cents),0) AS s FROM payments WHERE status = 'paid' AND created_at >= ?", [date('Y-m-d H:i:s', strtotime('-30 days'))])['s'] ?? 0);
    $new7 = (int)cq1('SELECT COUNT(*) AS c FROM tenants WHERE created_at >= ?', [date('Y-m-d H:i:s', strtotime('-7 days'))])['c'];
    render('sa_dashboard', ['by' => $by, 'mrr' => $mrr, 'rev30' => $rev30, 'new7' => $new7, 'total' => array_sum($by),
        'recent' => cq('SELECT t.*, p.name AS plan_name FROM tenants t LEFT JOIN plans p ON p.id = t.plan_id ORDER BY t.id DESC LIMIT 6'),
        'pays' => cq('SELECT pay.*, t.company FROM payments pay JOIN tenants t ON t.id = pay.tenant_id ORDER BY pay.id DESC LIMIT 6'),
        'ready' => sa_billing_ready(), 'cur' => csetting('currency', 'EUR'), 'cronCmd' => 'php ' . (is_file(dirname(APP_ROOT) . '/public/cron.php') ? dirname(APP_ROOT) . '/public/cron.php' : dirname(APP_ROOT) . '/cron.php'), 'cronUrl' => app_base_url() . '/cron.php?token=' . csetting('cron_token')], 'Dashboard');
}

// ------------------------------------------------------------------ Mandanten
function sa_tenants(): void {
    $st = (string)($_GET['status'] ?? ''); $q = trim((string)($_GET['q'] ?? '')); $w = []; $p = [];
    if (in_array($st, ['trial', 'active', 'past_due', 'canceled', 'expired', 'suspended'], true)) { $w[] = 't.status = ?'; $p[] = $st; }
    if ($q !== '') { $w[] = '(t.slug LIKE ? OR t.company LIKE ? OR t.owner_email LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%"); }
    $rows = cq('SELECT t.*, p.name AS plan_name FROM tenants t LEFT JOIN plans p ON p.id = t.plan_id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY t.id DESC LIMIT 500', $p);
    render('sa_tenants', ['tenants' => $rows, 'status' => $st, 'q' => $q], 'Mandanten');
}

function sa_tenant_stats(array $t): array {
    $prev = tenant_slug();
    try { tenant_use($t['slug']); $pdo = db(); $c = db_counts($pdo); $u = $c['users']; return ['users' => $u, 'invoices' => $c['invoices'], 'customers' => $c['customers']]; }
    catch (Throwable $e) { return ['users' => 0, 'invoices' => 0, 'customers' => 0]; }
    finally { tenant_use($prev); }
}

function sa_tenant(): void {
    $t = tenant_row_id((int)($_GET['id'] ?? 0)) ?: redirect('sa_tenants');
    render('sa_tenant', ['t' => $t, 'plans' => cq('SELECT * FROM plans ORDER BY sort, price_cents'), 'pays' => cq('SELECT * FROM payments WHERE tenant_id = ? ORDER BY id DESC LIMIT 20', [$t['id']]), 'stats' => sa_tenant_stats($t), 'eff' => tenant_effective($t)], $t['company']);
}

function sa_tenant_save(): void {
    csrf_check();
    $t = tenant_row_id((int)($_POST['id'] ?? 0)) ?: redirect('sa_tenants');
    $status = post('status'); if (!in_array($status, ['trial', 'active', 'past_due', 'canceled', 'expired', 'suspended'], true)) $status = $t['status'];
    $plan = (int)($_POST['plan_id'] ?? 0); if (!cq1('SELECT id FROM plans WHERE id = ?', [$plan])) $plan = (int)$t['plan_id'];
    cexec('UPDATE tenants SET company = ?, owner_name = ?, owner_email = ?, plan_id = ?, status = ?, trial_ends = ?, period_end = ?, notes = ? WHERE id = ?',
        [post('company'), post('owner_name'), post('owner_email'), $plan, $status, valid_date(post('trial_ends')), valid_date(post('period_end')), post('notes'), $t['id']]);
    audit('tenant_updated', $t['slug'] . ': Status ' . $status . ', Tarif ' . $plan, null, true);
    flash('Mandant gespeichert.');
    redirect('sa_tenant', ['id' => $t['id']]);
}

function sa_tenant_action(): void {
    csrf_check();
    $t = tenant_row_id((int)($_POST['id'] ?? 0)) ?: redirect('sa_tenants');
    $act = post('action');
    switch ($act) {
        case 'suspend': audit('tenant_suspended', $t['slug'], null, true); cexec("UPDATE tenants SET status = 'suspended' WHERE id = ?", [$t['id']]); flash('Mandant gesperrt.'); break;
        case 'activate':
            $new = ($t['provider_subscription'] !== '') ? 'active' : (($t['trial_ends'] && $t['trial_ends'] >= date('Y-m-d')) ? 'trial' : 'active');
            cexec('UPDATE tenants SET status = ? WHERE id = ?', [$new, $t['id']]); flash('Mandant freigegeben (' . tenant_status_label($new) . ').'); break;
        case 'extend':
            $from = max(strtotime($t['trial_ends'] ?: 'today'), strtotime('today'));
            cexec("UPDATE tenants SET trial_ends = ?, status = CASE WHEN status IN ('expired','trial') THEN 'trial' ELSE status END, warned_trial = 0 WHERE id = ?", [date('Y-m-d', strtotime('+7 days', $from)), $t['id']]); flash('Testphase um 7 Tage verlängert.'); break;
        case 'migrate': tenant_migrate_if_needed(array_merge($t, ['db_version' => 'force'])); flash('Datenbank des Mandanten aktualisiert.'); break;
        case 'login':
            $prev = tenant_slug(); tenant_use($t['slug']);
            $admin = db()->query('SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.is_system = 1 AND u.active = 1 ORDER BY u.id LIMIT 1')->fetch();
            if ($admin) audit('support_login', 'Support-Zugriff durch den Plattformbetreiber (' . (sa_user()['username'] ?? '') . ')', 'plattform');
            tenant_use($prev);
            if (!$admin) { flash('Dieser Mandant hat keinen aktiven Administrator.', 'err'); break; }
            audit('impersonation_start', 'Mandant ' . $t['slug'], null, true);
            session_regenerate_id(true);
            $_SESSION['tenant'] = $t['slug']; $_SESSION['uid'] = (int)$admin['id']; $_SESSION['impersonating'] = 1;
            redirect('dashboard');
        case 'delete':
            if (post('confirm') !== $t['slug']) { flash('Zum Löschen bitte die Firmen-ID „' . $t['slug'] . '“ eintippen.', 'err'); break; }
            audit('tenant_deleted', $t['slug'] . ' (' . $t['company'] . ')', null, true);
            tenant_delete($t['slug']); flash('Mandant „' . $t['company'] . '“ samt Daten gelöscht.'); redirect('sa_tenants');
    }
    redirect('sa_tenant', ['id' => $t['id']]);
}

// ------------------------------------------------------------------ Tarife
function sa_plans(): void {
    render('sa_plans', ['plans' => cq('SELECT p.*, (SELECT COUNT(*) FROM tenants t WHERE t.plan_id = p.id) AS n FROM plans p ORDER BY sort, price_cents'), 'default' => (int)csetting('default_plan_id')], 'Tarife');
}
function sa_plan_edit(): void {
    $id = (int)($_GET['id'] ?? 0);
    $p = $id ? (cq1('SELECT * FROM plans WHERE id = ?', [$id]) ?: redirect('sa_plans')) : ['id' => 0, 'name' => '', 'description' => '', 'price_cents' => 0, 'interval_unit' => 'month', 'currency' => csetting('currency', 'EUR'), 'max_users' => 0, 'max_invoices' => 0, 'active' => 1, 'sort' => 10];
    render('sa_plan_form', ['p' => $p], $id ? 'Tarif bearbeiten' : 'Neuer Tarif');
}
function sa_plan_save(): void {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $name = post('name'); $price = parse_cents(post('price')); $iv = post('interval_unit') === 'year' ? 'year' : 'month';
    if ($name === '' || $price < 0) { flash('Bitte Name und Preis angeben.', 'err'); redirect('sa_plan_edit', $id ? ['id' => $id] : []); }
    $f = [$name, post('description'), $price, $iv, strtoupper(substr(post('currency', 'EUR'), 0, 3)) ?: 'EUR', max(0, (int)post('max_users')), max(0, (int)post('max_invoices')), isset($_POST['active']) ? 1 : 0, (int)post('sort', '10')];
    if ($id) {
        $old = cq1('SELECT * FROM plans WHERE id = ?', [$id]);
        cexec('UPDATE plans SET name=?, description=?, price_cents=?, interval_unit=?, currency=?, max_users=?, max_invoices=?, active=?, sort=? WHERE id=?', array_merge($f, [$id]));
        // geänderter Preis/Intervall → bei den Zahlungsanbietern muss ein neuer Plan entstehen
        if ($old && ((int)$old['price_cents'] !== $price || $old['interval_unit'] !== $iv || $old['currency'] !== $f[4])) cexec("UPDATE plans SET stripe_price_id = '', paypal_plan_id = '' WHERE id = ?", [$id]);
    } else cexec('INSERT INTO plans(name, description, price_cents, interval_unit, currency, max_users, max_invoices, active, sort) VALUES (?,?,?,?,?,?,?,?,?)', $f);
    if (isset($_POST['make_default'])) set_csetting('default_plan_id', (string)($id ?: cdb()->lastInsertId()));
    flash('Tarif gespeichert.' . ($id ? ' Bestehende Abos behalten ihren bisherigen Preis beim Zahlungsanbieter; nur neue Abos nutzen den neuen Preis.' : ''));
    redirect('sa_plans');
}
function sa_plan_delete(): void {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    if ((int)cq1('SELECT COUNT(*) AS c FROM tenants WHERE plan_id = ?', [$id])['c'] > 0) { cexec('UPDATE plans SET active = 0 WHERE id = ?', [$id]); flash('Der Tarif wird noch genutzt und wurde deshalb nur deaktiviert.'); }
    else { cexec('DELETE FROM plans WHERE id = ?', [$id]); flash('Tarif gelöscht.'); }
    redirect('sa_plans');
}

// ------------------------------------------------------------------ Zahlungen
function sa_payments(): void {
    $rows = cq('SELECT pay.*, t.company, t.slug FROM payments pay JOIN tenants t ON t.id = pay.tenant_id ORDER BY pay.id DESC LIMIT 500');
    render('sa_payments', ['rows' => $rows, 'sum' => array_sum(array_column($rows, 'amount_cents'))], 'Zahlungen');
}

// ------------------------------------------------------------------ Einstellungen
const SA_SETTING_FIELDS = ['brand_name', 'company', 'email', 'base_domain', 'trial_days', 'currency', 'payment_mode', 'stripe_secret', 'stripe_webhook_secret', 'paypal_client_id', 'paypal_secret', 'paypal_webhook_id',
    'stripe_api_base', 'paypal_api_base', 'mail_from', 'mail_mode', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_pass', 'page_impressum', 'page_datenschutz', 'page_agb', 'landing_headline', 'landing_sub'];
const SA_SECRET_FIELDS = ['stripe_secret', 'stripe_webhook_secret', 'paypal_secret', 'smtp_pass'];

function sa_settings(): void {
    $s = []; foreach (SA_SETTING_FIELDS as $f) $s[$f] = in_array($f, SA_SECRET_FIELDS, true) ? '' : csetting($f);
    $has = []; foreach (SA_SECRET_FIELDS as $f) $has[$f] = csetting($f) !== '';
    $s['signup_open'] = csetting('signup_open', '1'); $s['payment_mode'] = csetting('payment_mode', 'sandbox'); $s['mail_mode'] = csetting('mail_mode', 'mail'); $s['smtp_secure'] = csetting('smtp_secure', 'tls'); $s['smtp_port'] = csetting('smtp_port', '587');
    render('sa_settings', ['s' => $s, 'has' => $has, 'plans' => cq('SELECT id, name FROM plans WHERE active = 1 ORDER BY sort'), 'default' => (int)csetting('default_plan_id'),
        'hook_stripe' => billing_url_abs('webhook_stripe'), 'hook_paypal' => billing_url_abs('webhook_paypal')], 'Einstellungen');
}
function sa_settings_save(): void {
    csrf_check();
    foreach (SA_SETTING_FIELDS as $f) {
        $v = in_array($f, ['page_impressum', 'page_datenschutz', 'page_agb', 'landing_sub'], true) ? trim((string)($_POST[$f] ?? '')) : post($f);
        if (in_array($f, SA_SECRET_FIELDS, true) && $v === '') continue; // leer = unverändert
        if ($f === 'payment_mode' && !in_array($v, ['sandbox', 'live'], true)) $v = 'sandbox';
        if ($f === 'trial_days') $v = (string)max(0, min(365, (int)$v));
        if ($f === 'base_domain') $v = strtolower(preg_replace('#^https?://|/.*$#', '', $v));
        if ($f === 'mail_mode' && !in_array($v, ['mail', 'smtp'], true)) $v = 'mail';
        if ($f === 'smtp_secure' && !in_array($v, ['tls', 'ssl', 'none'], true)) $v = 'tls';
        if ($f === 'currency') $v = strtoupper(substr($v, 0, 3)) ?: 'EUR';
        set_csetting($f, $v);
    }
    foreach (SA_SECRET_FIELDS as $f) if (isset($_POST['clear_' . $f])) set_csetting($f, '');
    set_csetting('tenant_layout', ($_POST['tenant_layout'] ?? '') === 'side' ? 'side' : 'top');
    set_csetting('tenant_layout_lock', isset($_POST['tenant_layout_lock']) ? '1' : '0');
    set_csetting('sa_layout', ($_POST['sa_layout'] ?? '') === 'side' ? 'side' : 'top');
    set_csetting('signup_open', isset($_POST['signup_open']) ? '1' : '0');
    set_csetting('sa_require_2fa', isset($_POST['sa_require_2fa']) ? '1' : '0');
    if (($d = (int)($_POST['default_plan_id'] ?? 0)) && cq1('SELECT id FROM plans WHERE id = ?', [$d])) set_csetting('default_plan_id', (string)$d);
    audit('sa_settings_saved', 'Plattform-Einstellungen geändert', null, true);
    flash('Einstellungen gespeichert.');
    redirect('sa_settings');
}

// ------------------------------------------------------------------ Superadmins
function sa_admins(): void { render('sa_admins', ['admins' => cq('SELECT * FROM superadmins ORDER BY LOWER(username)')], 'Superadmins'); }
function sa_admin_save(): void {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0); $user = post('username'); $pw = (string)($_POST['password'] ?? ''); $email = post('email');
    if (!preg_match('/^[A-Za-z0-9._@-]{3,60}$/', $user)) { flash('Ungültiger Benutzername.', 'err'); redirect('sa_admins'); }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('Ungültige E-Mail-Adresse.', 'err'); redirect('sa_admins'); }
    if (cq1('SELECT id FROM superadmins WHERE LOWER(username) = LOWER(?) AND id <> ?', [$user, $id])) { flash('Benutzername ist schon vergeben.', 'err'); redirect('sa_admins'); }
    if (($pw !== '' || !$id) && ($e = password_error($pw, $user, 10))) { flash($e, 'err'); redirect('sa_admins'); }
    $active = isset($_POST['active']) ? 1 : 0;
    if ($id) {
        if (!$active && ((int)sa_user()['id'] === $id || (int)cq1('SELECT COUNT(*) AS c FROM superadmins WHERE active = 1 AND id <> ?', [$id])['c'] === 0)) { flash('Es muss mindestens ein aktiver Superadmin bleiben.', 'err'); redirect('sa_admins'); }
        cexec('UPDATE superadmins SET username = ?, display_name = ?, email = ?, active = ? WHERE id = ?', [$user, post('display_name'), $email, $active, $id]);
        if ($pw !== '') cexec('UPDATE superadmins SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
    } else cexec('INSERT INTO superadmins(username, display_name, email, password_hash, active) VALUES (?,?,?,?,?)', [$user, post('display_name'), $email, password_hash($pw, PASSWORD_DEFAULT), $active]);
    audit($id ? 'sa_admin_updated' : 'sa_admin_created', 'Superadmin ' . $user . ($pw !== '' && $id ? ' (Passwort geändert)' : ''), null, true);
    flash('Superadmin gespeichert.');
    redirect('sa_admins');
}
function sa_admin_delete(): void {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    if ($id === (int)sa_user()['id']) flash('Sie können sich nicht selbst löschen.', 'err');
    elseif ((int)cq1('SELECT COUNT(*) AS c FROM superadmins WHERE active = 1 AND id <> ?', [$id])['c'] === 0) flash('Es muss mindestens ein aktiver Superadmin bleiben.', 'err');
    else { cexec('DELETE FROM superadmins WHERE id = ?', [$id]); audit('sa_admin_deleted', 'ID ' . $id, null, true); flash('Superadmin gelöscht.'); }
    redirect('sa_admins');
}
