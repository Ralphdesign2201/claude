<?php
declare(strict_types=1);

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; form-action 'self'; base-uri 'none'; object-src 'none'; frame-ancestors 'none'");
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
header('X-Permitted-Cross-Domain-Policies: none');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header_remove('X-Powered-By');

require APP_ROOT . '/init.php';
if (!valid_host((string)($_SERVER['HTTP_HOST'] ?? ''))) { http_response_code(400); exit('Bad Request'); }
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 80 * 1024 * 1024) { http_response_code(413); exit('Request too large'); }
if (request_is_https()) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
elseif ((app_config()['force_https'] ?? false) === true && !in_array(preg_replace('/:\d+$/', '', (string)$_SERVER['HTTP_HOST']), ['localhost', '127.0.0.1'], true)) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') { header('Location: https://' . $_SERVER['HTTP_HOST'] . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301); exit; }
    http_response_code(400); exit('HTTPS erforderlich');
}
sanitize_input();
if (($_GET['r'] ?? '') === 'asset') { // Stylesheet/Skript: immer frisch aus dem Projektordner (kein Cache-Problem nach Updates), ohne Sitzung
    $f = (string)($_GET['f'] ?? ''); $types = ['app.css' => 'text/css; charset=utf-8', 'app.js' => 'application/javascript; charset=utf-8'];
    $path = dirname(APP_ROOT) . '/public/assets/' . $f;
    if (!isset($types[$f]) || !is_file($path)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Not found'); }
    $etag = '"' . sha1_file($path) . '"';
    header('Content-Type: ' . $types[$f]); header('ETag: ' . $etag); header('Cache-Control: no-cache'); header_remove('Pragma'); header('Content-Security-Policy: default-src \'none\'');
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
    header('Content-Length: ' . (string)filesize($path)); readfile($path); exit;
}
start_session();

$routes = [
    'login' => ['auth', 'login'], 'login_2fa' => ['auth', 'login_2fa'], 'logout' => ['auth', 'logout'], 'forgot' => ['auth', 'forgot'], 'reset' => ['auth', 'reset'],
    'catalog' => ['catalog', 'index'], 'catalog_edit' => ['catalog', 'edit'], 'catalog_save' => ['catalog', 'save'], 'catalog_delete' => ['catalog', 'delete'], 'catalog_import' => ['catalog', 'import'], 'catalog_export' => ['catalog', 'export'],
    'audit' => ['users', 'audit'], 'twofa' => ['users', 'twofa'],
    'updates' => ['updates', 'index'], 'update_upload' => ['updates', 'upload'], 'update_install' => ['updates', 'install'], 'update_finish' => ['updates', 'finish'],
    'dashboard' => ['dashboard', 'index'],
    'customers' => ['customers', 'index'], 'customer_edit' => ['customers', 'edit'], 'customer_save' => ['customers', 'save'], 'customer_delete' => ['customers', 'delete'],
    'invoices' => ['invoices', 'index'], 'invoice_new' => ['invoices', 'edit'], 'invoice_edit' => ['invoices', 'edit'], 'invoice_save' => ['invoices', 'save'],
    'invoice_show' => ['invoices', 'show'], 'invoice_pdf' => ['invoices', 'pdf'], 'invoice_xml' => ['invoices', 'xml'], 'invoice_status' => ['invoices', 'status'], 'invoice_copy' => ['invoices', 'copy'],
    'offers' => ['offers', 'index'], 'offer_new' => ['offers', 'edit'], 'offer_edit' => ['offers', 'edit'], 'offer_save' => ['offers', 'save'], 'offer_show' => ['offers', 'show'],
    'offer_pdf' => ['offers', 'pdf'], 'offer_status' => ['offers', 'status'], 'offer_to_invoice' => ['offers', 'to_invoice'], 'offer_delete' => ['offers', 'delete'],
    'deliveries' => ['delivery', 'index'], 'delivery_new' => ['delivery', 'edit'], 'delivery_edit' => ['delivery', 'edit'], 'delivery_save' => ['delivery', 'save'], 'delivery_show' => ['delivery', 'show'],
    'delivery_pdf' => ['delivery', 'pdf'], 'delivery_delete' => ['delivery', 'delete'],
    'datev' => ['datev', 'index'], 'datev_export' => ['datev', 'export'],
    'mail_new' => ['mails', 'form'], 'mail_send' => ['mails', 'send'], 'mail_test' => ['mails', 'test'],
    'reminder_save' => ['reminders', 'save'], 'reminder_pdf' => ['reminders', 'pdf'], 'reminder_delete' => ['reminders', 'delete'],
    'settings' => ['settings', 'index'], 'settings_save' => ['settings', 'save'], 'logo' => ['settings', 'logo'],
    'users' => ['users', 'index'], 'user_edit' => ['users', 'edit'], 'user_save' => ['users', 'save'], 'user_delete' => ['users', 'delete'],
    'roles' => ['users', 'roles'], 'role_edit' => ['users', 'role_edit'], 'role_save' => ['users', 'role_save'], 'role_delete' => ['users', 'role_delete'],
    'profile' => ['users', 'profile'], 'profile_save' => ['users', 'profile_save'],
    'backups' => ['system', 'backups'], 'backup_create' => ['system', 'create'], 'backup_download' => ['system', 'download'], 'backup_delete' => ['system', 'delete'],
    'backup_upload' => ['system', 'upload'], 'backup_restore' => ['system', 'restore'], 'backup_settings' => ['system', 'save'], 'db_test' => ['system', 'db_test'], 'db_switch' => ['system', 'db_switch'],
];

// Rechte je Route: [Modul, Stufe]; '*' = jeder angemeldete Benutzer
$perms = [
    'updates' => ['system', 'w'], 'update_upload' => ['system', 'w'], 'update_install' => ['system', 'w'], 'update_finish' => ['system', 'w'],
    'catalog' => ['catalog', 'r'], 'catalog_export' => ['catalog', 'r'], 'catalog_edit' => ['catalog', 'w'], 'catalog_save' => ['catalog', 'w'], 'catalog_delete' => ['catalog', 'w'], 'catalog_import' => ['catalog', 'w'],
    'dashboard' => '*', 'logo' => '*', 'twofa' => '*', 'audit' => ['users', 'r'], 'profile' => '*', 'profile_save' => '*', 'logout' => '*',
    'customers' => ['customers', 'r'], 'customer_edit' => ['customers', 'r'], 'customer_save' => ['customers', 'w'], 'customer_delete' => ['customers', 'w'],
    'invoices' => ['invoices', 'r'], 'invoice_new' => ['invoices', 'w'], 'invoice_edit' => ['invoices', 'w'], 'invoice_save' => ['invoices', 'w'], 'invoice_show' => ['invoices', 'r'],
    'invoice_pdf' => ['invoices', 'r'], 'invoice_xml' => ['invoices', 'r'], 'invoice_status' => ['invoices', 'w'], 'invoice_copy' => ['invoices', 'w'],
    'reminder_save' => ['invoices', 'w'], 'reminder_pdf' => ['invoices', 'r'], 'reminder_delete' => ['invoices', 'w'],
    'offers' => ['offers', 'r'], 'offer_new' => ['offers', 'w'], 'offer_edit' => ['offers', 'w'], 'offer_save' => ['offers', 'w'], 'offer_show' => ['offers', 'r'], 'offer_pdf' => ['offers', 'r'],
    'offer_status' => ['offers', 'w'], 'offer_to_invoice' => ['offers', 'w'], 'offer_delete' => ['offers', 'w'],
    'deliveries' => ['deliveries', 'r'], 'delivery_new' => ['deliveries', 'w'], 'delivery_edit' => ['deliveries', 'w'], 'delivery_save' => ['deliveries', 'w'], 'delivery_show' => ['deliveries', 'r'],
    'delivery_pdf' => ['deliveries', 'r'], 'delivery_delete' => ['deliveries', 'w'],
    'datev' => ['export', 'r'], 'datev_export' => ['export', 'r'],
    'mail_new' => ['mail', 'w'], 'mail_send' => ['mail', 'w'], 'mail_test' => ['settings', 'w'],
    'settings' => ['settings', 'r'], 'settings_save' => ['settings', 'w'],
    'users' => ['users', 'r'], 'user_edit' => ['users', 'w'], 'user_save' => ['users', 'w'], 'user_delete' => ['users', 'w'],
    'roles' => ['users', 'r'], 'role_edit' => ['users', 'w'], 'role_save' => ['users', 'w'], 'role_delete' => ['users', 'w'],
    'backups' => ['system', 'r'], 'backup_create' => ['system', 'w'], 'backup_download' => ['system', 'w'], 'backup_delete' => ['system', 'w'],
    'backup_upload' => ['system', 'w'], 'backup_restore' => ['system', 'w'], 'backup_settings' => ['system', 'w'], 'db_test' => ['system', 'w'], 'db_switch' => ['system', 'w'],
];

// ---- nicht installiert ----
if (!is_installed()) {
    http_response_code(503);
    $links = (is_file(dirname(APP_ROOT) . '/install.php') ? '<p><a href="install.php">Einzelinstallation starten (install.php)</a></p>' : '') . (is_file(dirname(APP_ROOT) . '/superinstall.php') ? '<p><a href="superinstall.php">SaaS-Installation starten (superinstall.php)</a></p>' : '');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Nicht installiert</title><body style="font-family:system-ui;max-width:560px;margin:15vh auto;padding:0 16px"><h1>' . htmlspecialchars(APP_NAME) . '</h1><p>Das Programm ist noch nicht installiert.</p>' . ($links ?: '<p>Bitte <code>install.php</code> bzw. <code>superinstall.php</code> hochladen und aufrufen.</p>') . '</body>';
    exit;
}

// ---- SaaS: zusätzliche Routen, Mandanten- und Superadmin-Kontext ----
$kind = 'tenant';
if (is_saas()) {
    require APP_ROOT . '/saas_routes.php';
    [$routes, $perms, $kinds] = saas_routes($routes, $perms);
} else {
    $kinds = [];
}
$route = (is_string($_GET['r'] ?? null) && $_GET['r'] !== '') ? $_GET['r'] : (is_saas() && empty($_SESSION['tenant']) && empty($_SESSION['sa']) ? 'home' : (is_saas() && empty($_SESSION['tenant']) ? 'sa_dashboard' : 'dashboard'));
if (!isset($routes[$route])) { http_response_code(404); $route = is_saas() && empty($_SESSION['tenant']) ? 'home' : 'dashboard'; }
$kind = is_saas() ? ($kinds[$route] ?? 'tenant') : 'tenant';

if ($kind === 'sa') {
    $GLOBALS['__layout'] = 'layout_sa';
    require_sa();
} elseif ($kind === 'public' || $kind === 'webhook') {
    if ($kind === 'public' && in_array($route, ['home', 'signup', 'page'], true)) $GLOBALS['__layout'] = 'layout_public';
    if (str_starts_with($route, 'sa_')) $GLOBALS['__auth_variant'] = 'sa';
} else {
    if (is_saas()) {
        $slug = $_SESSION['tenant'] ?? null;
        $t = is_string($slug) ? tenant_row($slug) : null;
        if (!$t) { unset($_SESSION['tenant'], $_SESSION['uid']); redirect('login'); }
        tenant_use($slug);
        $t = tenant_effective($t);
        $GLOBALS['__tenant_row'] = $t;
        tenant_migrate_if_needed($t);
    }
    if (!in_array($route, ['login', 'login_2fa', 'forgot', 'reset', 'verify'], true)) {
        require_login();
        if (is_saas() && !$t['access'] && !in_array($route, ['billing', 'billing_checkout', 'billing_return', 'billing_portal', 'billing_cancel', 'logout', 'backup_create', 'backup_download', 'profile', 'profile_save', 'backups'], true)) {
            if ($t['status'] === 'suspended') { http_response_code(403); render('error', ['message' => 'Dieses Konto wurde gesperrt. Bitte wenden Sie sich an den Betreiber.'], 'Gesperrt'); exit; }
            flash(($t['status'] === 'expired' ? 'Ihre Testphase bzw. Ihr Abonnement ist abgelaufen.' : 'Ihr Zugang ist eingeschränkt.') . ' Bitte wählen Sie einen Tarif – Ihre Daten bleiben erhalten.', 'err');
            redirect('billing');
        }
        $need = $perms[$route] ?? null;
        if ($need !== '*') { if ($need === null) $need = ['system', 'w']; require_can($need[0], $need[1]); }
        foreach (['invoice_pdf' => ['pdf', 120, 60], 'offer_pdf' => ['pdf', 120, 60], 'delivery_pdf' => ['pdf', 120, 60], 'reminder_pdf' => ['pdf', 120, 60], 'invoice_xml' => ['xml', 60, 60], 'datev_export' => ['exp', 20, 60], 'backup_create' => ['bk', 6, 300], 'backup_download' => ['bkd', 20, 300], 'mail_send' => ['mail', 20, 300], 'backup_upload' => ['bku', 6, 300]] as $rr => [$bk, $mx, $win]) if ($route === $rr) rate_guard($bk, $mx, $win);
        if (setting('backup_pseudo') === '1') { try { backup_run_if_due(); } catch (Throwable $e) { error_log('Auto-Backup: ' . $e->getMessage()); } }
    }
}

if ($route === 'billing') { // Formulare dürfen zu den Zahlungsanbietern weiterleiten
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; form-action 'self' https://checkout.stripe.com https://billing.stripe.com https://www.paypal.com https://www.sandbox.paypal.com; base-uri 'none'; object-src 'none'; frame-ancestors 'none'");
}
[$file, $fn] = $routes[$route];
require APP_ROOT . '/pages/' . $file . '.php';
$handler = $file . '_' . $fn;
try {
    $handler();
} catch (Throwable $e) {
    error_log($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    render('error', ['message' => 'Es ist ein Fehler aufgetreten.'], 'Fehler');
}
