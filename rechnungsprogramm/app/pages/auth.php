<?php
declare(strict_types=1);

function auth_setup(): void {
    if ((int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) redirect('login');
    $err = ''; $old = ['username' => 'admin', 'display_name' => ''];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $old = ['username' => post('username'), 'display_name' => post('display_name')];
        $pw = (string)($_POST['password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._@-]{3,60}$/', $old['username'])) $err = 'Der Benutzername darf 3–60 Zeichen lang sein (Buchstaben, Ziffern, . _ @ -).';
        elseif (strlen($pw) < 8) $err = 'Das Passwort muss mindestens 8 Zeichen lang sein.';
        elseif ($pw !== (string)($_POST['password2'] ?? '')) $err = 'Die Passwörter stimmen nicht überein.';
        else {
            $pdo = db();
            $pdo->beginTransaction();
            $roleId = seed_roles($pdo);
            $pdo->prepare('INSERT INTO users(username, display_name, email, password_hash, role_id, active) VALUES (?,?,?,?,?,1)')
                ->execute([$old['username'], $old['display_name'], '', password_hash($pw, PASSWORD_DEFAULT), $roleId]);
            $uid = (int)$pdo->lastInsertId();
            $pdo->commit();
            set_setting('payment_days', '14'); set_setting('invoice_prefix', 'RE-');
            set_setting('cron_token', bin2hex(random_bytes(16)));
            session_regenerate_id(true);
            $_SESSION['uid'] = $uid;
            flash('Willkommen! Bitte zuerst die Firmendaten eintragen.');
            redirect('settings');
        }
    }
    render('setup', ['err' => $err, 'old' => $old], 'Einrichtung');
}

function auth_login(): void {
    if (logged_in()) redirect('dashboard');
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if ($wait = throttle_blocked()) $err = 'Zu viele Fehlversuche. Bitte in ' . ceil($wait / 60) . ' Min. erneut versuchen.';
        else {
            $st = db()->prepare('SELECT * FROM users WHERE LOWER(username) = LOWER(?) AND active = 1');
            $st->execute([post('username')]);
            $u = $st->fetch();
            // immer ein Hash-Vergleich, damit die Antwortzeit nichts über existierende Benutzer verrät
            $ok = password_verify((string)($_POST['password'] ?? ''), $u['password_hash'] ?? '$2y$10$usesomesillystringforsaltthatnoonewillguess12345678901234567');
            if ($u && $ok) {
                throttle_clear();
                session_regenerate_id(true);
                $_SESSION['uid'] = (int)$u['id'];
                db()->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $u['id']]);
                if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash((string)$_POST['password'], PASSWORD_DEFAULT), $u['id']]);
                redirect('dashboard');
            }
            throttle_fail(); usleep(400000);
            $err = 'Benutzername oder Passwort falsch.';
        }
    }
    render('login', ['err' => $err, 'user' => post('username')], 'Anmelden');
}

function auth_logout(): void {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . url('login'));
    exit;
}
