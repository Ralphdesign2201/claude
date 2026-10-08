<?php
declare(strict_types=1);
require_once APP_ROOT . '/billing_lib.php';

function public_home(): void {
    $plans = cq('SELECT * FROM plans WHERE active = 1 ORDER BY sort, price_cents');
    render('home', ['plans' => $plans, 'trial' => (int)csetting('trial_days', '14'), 'signup' => csetting('signup_open', '1') === '1', 'ready' => sa_billing_ready()], csetting('brand_name', APP_NAME));
}

function public_page(): void {
    $p = (string)($_GET['p'] ?? '');
    $titles = ['impressum' => 'Impressum', 'datenschutz' => 'Datenschutzerklärung', 'agb' => 'Allgemeine Geschäftsbedingungen'];
    if (!isset($titles[$p])) { http_response_code(404); redirect('home'); }
    render('page', ['title' => $titles[$p], 'text' => csetting('page_' . $p)], $titles[$p]);
}

const DISPOSABLE_DOMAINS = ['mailinator.com', 'guerrillamail.com', 'guerrillamail.net', '10minutemail.com', 'trashmail.com', 'trashmail.de', 'yopmail.com', 'tempmail.com', 'temp-mail.org', 'sharklasers.com', 'getnada.com', 'maildrop.cc', 'dispostable.com', 'throwawaymail.com', 'fakeinbox.com', 'wegwerfmail.de', 'einrot.com', 'spam4.me', 'mailnesia.com', 'mytemp.email'];
function disposable_email(string $email): bool { $d = strtolower(substr(strrchr($email, '@') ?: '', 1)); return in_array($d, DISPOSABLE_DOMAINS, true); }
function form_stamp(): string { $t = time(); return $t . '.' . hash_hmac('sha256', 'f' . $t, secret_key()); }
function form_stamp_ok(string $s): bool {
    [$t, $h] = array_pad(explode('.', $s, 2), 2, '');
    return ctype_digit($t) && hash_equals(hash_hmac('sha256', 'f' . $t, secret_key()), $h) && time() - (int)$t >= 3 && time() - (int)$t <= 7200;
}

function public_signup(): void {
    if (csetting('signup_open', '1') !== '1') { render('error', ['message' => 'Neue Registrierungen sind derzeit nicht möglich.'], 'Registrierung'); return; }
    $err = ''; $v = ['company' => '', 'owner_name' => '', 'owner_email' => '', 'slug' => ''];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        foreach ($v as $k => $_) $v[$k] = post($k);
        $v['slug'] = $v['slug'] !== '' ? strtolower($v['slug']) : slugify($v['company']);
        $pw = (string)($_POST['password'] ?? '');
        if (post('website') !== '') { redirect('home'); } // Honeypot
        if (!rate_hit('signup_try', 25, 3600) || rate_count('signup_ok', 3600) >= 4) $err = 'Von Ihrer Adresse wurden gerade zu viele Konten angelegt. Bitte später erneut versuchen.';
        elseif ($v['company'] === '' || $v['owner_name'] === '') $err = 'Bitte Firma und Ihren Namen angeben.';
        elseif (!filter_var($v['owner_email'], FILTER_VALIDATE_EMAIL)) $err = 'Bitte eine gültige E-Mail-Adresse angeben.';
        elseif (!form_stamp_ok((string)($_POST['fs'] ?? ''))) $err = 'Das Formular war zu schnell abgeschickt oder ist abgelaufen. Bitte erneut versuchen.';
        elseif (disposable_email($v['owner_email'])) $err = 'Bitte eine dauerhafte E-Mail-Adresse verwenden (keine Wegwerf-Adresse).';
        elseif ($e = password_error($pw, $v['owner_email'])) $err = $e;
        elseif ($pw !== (string)($_POST['password2'] ?? '')) $err = 'Die Passwörter stimmen nicht überein.';
        elseif (!isset($_POST['terms'])) $err = 'Bitte AGB und Datenschutzerklärung akzeptieren.';
        elseif ($e = slug_error($v['slug'])) $err = $e;
        elseif (cq1('SELECT id FROM tenants WHERE LOWER(owner_email) = LOWER(?)', [$v['owner_email']]) && csetting('allow_same_email', '0') !== '1') $err = 'Mit dieser E-Mail-Adresse existiert bereits ein Konto. Bitte melden Sie sich an.';
        else {
            try {
                $t = tenant_create(['slug' => $v['slug'], 'company' => $v['company'], 'owner_name' => $v['owner_name'], 'owner_email' => $v['owner_email'], 'username' => $v['owner_email'], 'password' => $pw]);
                tenant_use($t['slug']);
                $uid = (int)db()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
                session_regenerate_id(true);
                $_SESSION['tenant'] = $t['slug']; $_SESSION['uid'] = $uid;
                $login = app_url('login', ['t' => $t['slug']]);
                rate_hit('signup_ok', 99, 3600);
                audit('tenant_signup', 'Registrierung ' . $t['slug'], $v['owner_email'], true);
                try { send_verify_mail($t); } catch (Throwable $e) { error_log('verify mail: ' . $e->getMessage()); }
                @central_mail($v['owner_email'], 'Willkommen bei ' . csetting('brand_name', APP_NAME), "Hallo " . $v['owner_name'] . ",\n\nIhr Konto für " . $v['company'] . " ist eingerichtet. Ihre Testphase läuft bis zum " . date_de($t['trial_ends']) . ".\n\nAnmeldung: " . $login . "\nFirmen-ID: " . $t['slug'] . "\nBenutzername: " . $v['owner_email'] . "\n\nViel Erfolg!\n" . csetting('company'));
                tenant_use(null);
                flash('Willkommen! Ihr Konto ist angelegt – Ihre Testphase läuft bis ' . date_de($t['trial_ends']) . '. Bitte tragen Sie zuerst Ihre Firmendaten ein.');
                redirect('settings');
            } catch (Throwable $e) { tenant_use(null); error_log('Signup: ' . $e->getMessage()); $err = 'Registrierung fehlgeschlagen: ' . $e->getMessage(); }
        }
    }
    render('signup', ['err' => $err, 'v' => $v, 'trial' => (int)csetting('trial_days', '14'), 'stamp' => form_stamp()], 'Kostenlos testen');
}
