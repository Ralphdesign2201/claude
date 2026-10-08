<?php
declare(strict_types=1);

function auth_setup(): void {
    if (setting('password_hash') !== '') redirect('login');
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $pw = (string)($_POST['password'] ?? '');
        if (strlen($pw) < 8) $err = 'Das Passwort muss mindestens 8 Zeichen lang sein.';
        elseif ($pw !== (string)($_POST['password2'] ?? '')) $err = 'Die Passwörter stimmen nicht überein.';
        else {
            set_setting('password_hash', password_hash($pw, PASSWORD_DEFAULT));
            set_setting('payment_days', '14'); set_setting('invoice_prefix', 'RE-');
            session_regenerate_id(true);
            $_SESSION['uid'] = 1;
            flash('Willkommen! Bitte zuerst die Firmendaten eintragen.');
            redirect('settings');
        }
    }
    render('setup', ['err' => $err], 'Einrichtung');
}

function auth_login(): void {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $now = time();
        if (($_SESSION['fail_until'] ?? 0) > $now) $err = 'Zu viele Versuche. Bitte kurz warten.';
        elseif (password_verify((string)($_POST['password'] ?? ''), setting('password_hash'))) {
            session_regenerate_id(true);
            $_SESSION['uid'] = 1; unset($_SESSION['fails']);
            redirect('dashboard');
        } else {
            $_SESSION['fails'] = ($_SESSION['fails'] ?? 0) + 1;
            if ($_SESSION['fails'] >= 5) $_SESSION['fail_until'] = $now + 30;
            usleep(400000);
            $err = 'Passwort falsch.';
        }
    }
    render('login', ['err' => $err], 'Anmelden');
}

function auth_logout(): void {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . url('login'));
    exit;
}
