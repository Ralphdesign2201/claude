<?php
declare(strict_types=1);

/** Zwei-Faktor-Verwaltung (TOTP) für Mandanten-Benutzer (users) und Superadmins (superadmins). */
function twofa_page(string $table, array $u, string $route, bool $central): void {
    $pdo = $central ? cdb() : db();
    if (!in_array($table, ACCOUNT_TABLES, true)) throw new LogicException('table');
    $issuer = $central ? csetting('brand_name', APP_NAME) . ' Superadmin' : (is_saas() ? csetting('brand_name', APP_NAME) : (setting('company') ?: APP_NAME));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $act = post('action');
        if ($act === 'start' && (int)$u['totp_enabled'] === 0) { $_SESSION['twofa_new'] = totp_new_secret(); }
        elseif ($act === 'confirm' && (int)$u['totp_enabled'] === 0) {
            $sec = (string)($_SESSION['twofa_new'] ?? '');
            $step = $sec !== '' ? totp_verify($sec, post('code')) : null;
            if ($step === null) flash('Der Code stimmt nicht. Bitte erneut versuchen (Uhrzeit des Handys prüfen).', 'err');
            else {
                [$plain, $hash] = recovery_codes_new();
                $pdo->prepare("UPDATE $table SET totp_secret = ?, totp_enabled = 1, totp_last = ?, recovery_codes = ? WHERE id = ?")->execute([secret_encrypt($sec), $step, json_encode($hash), $u['id']]);
                $_SESSION['twofa_codes'] = $plain; unset($_SESSION['twofa_new']);
                audit('2fa_enabled', 'Zwei-Faktor-Anmeldung aktiviert', (string)$u['username'], $central);
                flash('Zwei-Faktor-Anmeldung ist aktiv. Bitte die Wiederherstellungscodes sicher aufbewahren.');
            }
        } elseif (in_array($act, ['disable', 'regen'], true) && (int)$u['totp_enabled'] === 1) {
            if (!password_verify((string)($_POST['password'] ?? ''), (string)$u['password_hash'])) flash('Passwort falsch.', 'err');
            elseif ($act === 'disable' && !second_factor_ok($pdo, $table, $u, post('code'))) flash('Der Code ist falsch.', 'err');
            elseif ($act === 'disable') {
                $pdo->prepare("UPDATE $table SET totp_secret = '', totp_enabled = 0, totp_last = 0, recovery_codes = '' WHERE id = ?")->execute([$u['id']]);
                audit('2fa_disabled', 'Zwei-Faktor-Anmeldung deaktiviert', (string)$u['username'], $central);
                flash('Zwei-Faktor-Anmeldung deaktiviert.');
            } else {
                [$plain, $hash] = recovery_codes_new();
                $pdo->prepare("UPDATE $table SET recovery_codes = ? WHERE id = ?")->execute([json_encode($hash), $u['id']]);
                $_SESSION['twofa_codes'] = $plain; audit('2fa_recovery_regenerated', 'Wiederherstellungscodes neu erzeugt', (string)$u['username'], $central);
            }
        }
        redirect($route);
    }
    $codes = $_SESSION['twofa_codes'] ?? null; unset($_SESSION['twofa_codes']);
    $new = (string)($_SESSION['twofa_new'] ?? '');
    render('twofa', ['u' => $u, 'route' => $route, 'codes' => $codes, 'new' => $new, 'uri' => $new !== '' ? totp_uri($new, (string)$u['username'], $issuer) : '', 'left' => count(json_decode((string)$u['recovery_codes'], true) ?: [])], 'Zwei-Faktor-Anmeldung');
}
