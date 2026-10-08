<?php
declare(strict_types=1);

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-ancestors 'none'");

require APP_ROOT . '/init.php';
start_session();

$routes = [
    'login' => ['auth', 'login'], 'logout' => ['auth', 'logout'], 'setup' => ['auth', 'setup'],
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
    'dashboard' => '*', 'logo' => '*', 'profile' => '*', 'profile_save' => '*', 'logout' => '*',
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
$route = $_GET['r'] ?? 'dashboard';
if (!isset($routes[$route])) { http_response_code(404); $route = 'dashboard'; }

$hasUser = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
if (!$hasUser && $route !== 'setup') redirect('setup');
if ($hasUser && !in_array($route, ['login', 'setup'], true)) {
    require_login();
    $need = $perms[$route] ?? null;
    if ($need !== '*') { if ($need === null) $need = ['system', 'w']; require_can($need[0], $need[1]); }
    if (setting('backup_pseudo') === '1') { try { backup_run_if_due(); } catch (Throwable $e) { error_log('Auto-Backup: ' . $e->getMessage()); } }
}

[$file, $fn] = $routes[$route];
require APP_ROOT . '/pages/' . $file . '.php';
$handler = $file . '_' . $fn;
try {
    $handler();
} catch (Throwable $e) {
    error_log($e->getMessage());
    http_response_code(500);
    render('error', ['message' => 'Es ist ein Fehler aufgetreten.'], 'Fehler');
}
