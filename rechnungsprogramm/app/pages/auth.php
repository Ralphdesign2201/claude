<?php
declare(strict_types=1);

function complete_login(array $u, ?array $tenantRow) {
    session_regenerate_id(true);
    $keep = ['_t0' => time(), '_ua' => $_SESSION['_ua'] ?? '', '_last' => time()];
    $_SESSION = $keep;
    $_SESSION['uid'] = (int)$u['id'];
    if ($tenantRow) {
        $_SESSION['tenant'] = $tenantRow['slug'];
        cexec('UPDATE tenants SET last_login = ? WHERE id = ?', [date('Y-m-d H:i:s'), $tenantRow['id']]);
        setcookie('rg_tenant', $tenantRow['slug'], ['expires' => time() + 86400 * 90, 'path' => '/', 'httponly' => true, 'secure' => request_is_https(), 'samesite' => 'Lax']);
    }
    account_ok(db(), 'users', $u);
    if (password_needs_rehash((string)$u['password_hash'], PASSWORD_DEFAULT) && isset($_POST['password'])) db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash((string)$_POST['password'], PASSWORD_DEFAULT), $u['id']]);
    audit('login', 'Anmeldung erfolgreich', (string)$u['username']);
    redirect('dashboard');
}

function auth_login(): void {
    $saas = is_saas();
    $hostTenant = $saas ? tenant_from_host() : null;
    if ($saas && !empty($_SESSION['tenant']) && is_string($_SESSION['tenant'])) { try { tenant_use($_SESSION['tenant']); } catch (Throwable $e) { unset($_SESSION['tenant']); } }
    if ($saas && sa_user() && empty($_SESSION['impersonating']) && tenant_slug() === null) redirect('sa_dashboard');
    if ((!$saas || tenant_slug() !== null) && logged_in()) redirect('dashboard');
    $err = ''; $tenantInput = $saas ? ($hostTenant ?? strtolower(post('tenant', (string)($_GET['t'] ?? ($_COOKIE['rg_tenant'] ?? ''))))) : '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $tenantRow = null;
        if ($saas && !$hostTenant && $tenantInput === '') { // ohne Firmen-ID: Plattform-Anmeldung (Superadmin)
            require_once APP_ROOT . '/pages/sa.php';
            $err = sa_try_login(post('username'), (string)($_POST['password'] ?? ''));
            render('login', ['err' => $err, 'user' => post('username'), 'saas' => $saas, 'hostTenant' => $hostTenant, 'tenantInput' => $tenantInput], 'Anmelden'); return;
        }
        if ($saas) {
            $tenantRow = preg_match('/^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/', $tenantInput) ? tenant_row($tenantInput) : null;
            tenant_use($tenantRow ? $tenantInput : null);
        }
        $generic = $saas ? 'Firmen-ID, Benutzername oder Passwort falsch.' : 'Benutzername oder Passwort falsch.';
        if (rate_count('login', 600) >= 12) $err = 'Zu viele Anmeldeversuche von Ihrer Adresse. Bitte in einigen Minuten erneut versuchen.';
        elseif ($saas && !$tenantRow) { rate_hit('login', 1000, 600); usleep(400000); $err = $generic; }
        else {
            $st = db()->prepare('SELECT * FROM users WHERE LOWER(username) = LOWER(?) AND active = 1');
            $st->execute([post('username')]);
            $u = $st->fetch();
            // immer ein Hash-Vergleich, damit die Antwortzeit nichts über existierende Benutzer verrät
            $ok = password_verify((string)($_POST['password'] ?? ''), $u['password_hash'] ?? '$2y$10$usesomesillystringforsaltthatnoonewillguess12345678901234567');
            if ($u && account_locked($u)) { rate_hit('login', 1000, 600); usleep(300000); audit('login_locked', 'Konto vorübergehend gesperrt', (string)$u['username']); $err = $generic . ' (Bei mehreren Fehlversuchen wird das Konto kurz gesperrt.)'; }
            elseif ($u && $ok) {
                if ((int)$u['totp_enabled'] === 1) {
                    $_SESSION['p2'] = ['type' => 'tenant', 'id' => (int)$u['id'], 'tenant' => $tenantRow['slug'] ?? null, 't' => time()];
                    redirect('login_2fa');
                }
                complete_login($u, $tenantRow);
            } else {
                rate_hit('login', 1000, 600);
                if ($u) account_fail(db(), 'users', $u);
                audit('login_failed', 'Anmeldung fehlgeschlagen', substr(post('username'), 0, 60));
                usleep(400000); $err = $generic;
            }
        }
    }
    render('login', ['err' => $err, 'user' => post('username'), 'saas' => $saas, 'hostTenant' => $hostTenant, 'tenantInput' => $tenantInput], 'Anmelden');
}

function auth_login_2fa(): void {
    $p = $_SESSION['p2'] ?? null;
    if (!$p || ($p['type'] ?? '') !== 'tenant' || time() - (int)$p['t'] > 300) { unset($_SESSION['p2']); redirect('login'); }
    $tenantRow = null;
    if (is_saas()) { $tenantRow = tenant_row((string)$p['tenant']); if (!$tenantRow) redirect('login'); tenant_use($tenantRow['slug']); }
    $st = db()->prepare('SELECT * FROM users WHERE id = ? AND active = 1'); $st->execute([(int)$p['id']]); $u = $st->fetch();
    if (!$u) { unset($_SESSION['p2']); redirect('login'); }
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if (rate_count('2fa', 600, 'u' . $u['id'] . '|' . ($tenantRow['slug'] ?? '')) >= 6) $err = 'Zu viele Versuche. Bitte in einigen Minuten erneut versuchen.';
        elseif (second_factor_ok(db(), 'users', $u, (string)($_POST['code'] ?? ''))) { unset($_SESSION['p2']); rate_reset('2fa', 'u' . $u['id'] . '|' . ($tenantRow['slug'] ?? '')); complete_login($u, $tenantRow); }
        else { rate_hit('2fa', 1000, 600, 'u' . $u['id'] . '|' . ($tenantRow['slug'] ?? '')); audit('2fa_failed', 'Falscher Bestätigungscode', (string)$u['username']); usleep(400000); $err = 'Der Code ist falsch oder abgelaufen.'; }
    }
    render('login_2fa', ['err' => $err, 'action' => 'login_2fa', 'cancel' => 'login'], 'Bestätigung');
}

function auth_logout(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if (logged_in() || is_saas()) audit('logout', 'Abmeldung');
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        header('Location: ' . url('login'));
        exit;
    }
    redirect('dashboard');
}

// ---- Passwort vergessen (Mandant / Einzelinstallation) ----
function auth_forgot(): void {
    $saas = is_saas(); $done = false; $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if (!rate_hit('forgot', 5, 3600)) $err = 'Zu viele Anfragen. Bitte später erneut versuchen.';
        else {
            $slug = $saas ? strtolower(post('tenant')) : null;
            $ident = post('username');
            try {
                $tenantRow = $saas ? (preg_match('/^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/', (string)$slug) ? tenant_row($slug) : null) : null;
                if (!$saas || $tenantRow) {
                    if ($saas) tenant_use($slug);
                    $st = db()->prepare("SELECT * FROM users WHERE active = 1 AND email <> '' AND (LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?)) ORDER BY id LIMIT 1");
                    $st->execute([$ident, $ident]); $u = $st->fetch();
                    if ($u && rate_hit('forgot_u', 3, 3600, ($slug ?? '') . '|' . $u['id'])) {
                        $tok = bin2hex(random_bytes(32));
                        db()->prepare('INSERT INTO password_resets(user_id, token_hash, expires) VALUES (?,?,?)')->execute([$u['id'], hash('sha256', $tok), date('Y-m-d H:i:s', time() + 3600)]);
                        $link = app_url('reset', array_filter(['t' => $slug, 'k' => $tok]));
                        $GLOBALS['__mail_central'] = $saas;
                        $brand = $saas ? csetting('brand_name', APP_NAME) : (setting('company') ?: APP_NAME);
                        require_once APP_ROOT . '/mail.php';
                        send_mail($u['email'], 'Passwort zurücksetzen – ' . $brand, "Hallo " . ($u['display_name'] ?: $u['username']) . ",\n\nüber diesen Link können Sie ein neues Passwort vergeben (60 Minuten gültig):\n\n$link\n\nWenn Sie das nicht angefordert haben, ignorieren Sie diese Nachricht – Ihr Passwort bleibt unverändert.\n");
                        $GLOBALS['__mail_central'] = false;
                        audit('reset_requested', 'Passwort-Zurücksetzen angefordert', (string)$u['username']);
                    }
                }
            } catch (Throwable $e) { error_log('forgot: ' . $e->getMessage()); }
            usleep(300000);
            $done = true; // immer dieselbe Antwort – verrät nicht, ob das Konto existiert
        }
    }
    render('forgot', ['saas' => $saas, 'done' => $done, 'err' => $err, 'tenant' => post('tenant', (string)($_COOKIE['rg_tenant'] ?? '')), 'action' => 'forgot', 'back' => 'login'], 'Passwort vergessen');
}

function auth_reset(): void {
    $saas = is_saas(); $slug = $saas ? strtolower((string)($_GET['t'] ?? $_POST['t'] ?? '')) : null; $tok = (string)($_GET['k'] ?? $_POST['k'] ?? '');
    $bad = fn() => render('error', ['message' => 'Dieser Link ist ungültig oder abgelaufen. Bitte fordern Sie einen neuen an.'], 'Link ungültig');
    if ($saas) { $t = preg_match('/^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/', (string)$slug) ? tenant_row($slug) : null; if (!$t) { $bad(); return; } tenant_use($slug); }
    if (!preg_match('/^[0-9a-f]{64}$/', $tok)) { $bad(); return; }
    $st = db()->prepare('SELECT r.*, u.username FROM password_resets r JOIN users u ON u.id = r.user_id WHERE r.token_hash = ? AND r.used = 0 AND r.expires > ? AND u.active = 1');
    $st->execute([hash('sha256', $tok), date('Y-m-d H:i:s')]); $r = $st->fetch();
    if (!$r) { $bad(); return; }
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $pw = (string)($_POST['password'] ?? '');
        if ($e = password_error($pw, (string)$r['username'])) $err = $e;
        elseif ($pw !== (string)($_POST['password2'] ?? '')) $err = 'Die Passwörter stimmen nicht überein.';
        else {
            db()->prepare('UPDATE users SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $r['user_id']]);
            db()->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ?')->execute([$r['user_id']]);
            audit('password_reset', 'Passwort per Link zurückgesetzt', (string)$r['username']);
            flash('Passwort geändert. Bitte melden Sie sich an.');
            redirect('login', $saas ? ['t' => $slug] : []);
        }
    }
    render('reset', ['err' => $err, 'tok' => $tok, 'slug' => $slug, 'action' => 'reset'], 'Neues Passwort');
}

// ---- E-Mail-Bestätigung (SaaS) ----
function auth_verify(): void {
    $slug = strtolower((string)($_GET['t'] ?? '')); $tok = (string)($_GET['k'] ?? '');
    $t = preg_match('/^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/', $slug) ? tenant_row($slug) : null;
    if ($t && preg_match('/^[0-9a-f]{64}$/', $tok) && $t['verify_token'] !== '' && hash_equals((string)$t['verify_token'], hash('sha256', $tok))) {
        cexec("UPDATE tenants SET email_verified = 1, verify_token = '' WHERE id = ?", [$t['id']]);
        flash('E-Mail-Adresse bestätigt. Vielen Dank!');
    } else flash('Der Bestätigungslink ist ungültig oder wurde schon verwendet.', 'err');
    redirect(!empty($_SESSION['tenant']) ? 'dashboard' : 'login');
}
function auth_verify_resend(): void {
    csrf_check();
    $t = current_tenant();
    if ($t && !(int)$t['email_verified'] && rate_hit('verify_resend', 3, 3600, $t['slug'])) { send_verify_mail($t); flash('Bestätigungs-Mail wurde gesendet.'); }
    else flash('Bitte später erneut versuchen.', 'err');
    redirect('dashboard');
}
