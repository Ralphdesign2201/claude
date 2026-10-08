<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80000) { http_response_code(500); exit('PHP 8.0 oder neuer erforderlich.'); }
mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Berlin');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-ancestors 'none'");

require APP_ROOT . '/helpers.php';
require APP_ROOT . '/db.php';
start_session();

$routes = [
    'login' => ['auth', 'login'], 'logout' => ['auth', 'logout'], 'setup' => ['auth', 'setup'],
    'dashboard' => ['dashboard', 'index'],
    'customers' => ['customers', 'index'], 'customer_edit' => ['customers', 'edit'], 'customer_save' => ['customers', 'save'], 'customer_delete' => ['customers', 'delete'],
    'invoices' => ['invoices', 'index'], 'invoice_new' => ['invoices', 'edit'], 'invoice_edit' => ['invoices', 'edit'], 'invoice_save' => ['invoices', 'save'],
    'invoice_show' => ['invoices', 'show'], 'invoice_pdf' => ['invoices', 'pdf'], 'invoice_xml' => ['invoices', 'xml'], 'invoice_status' => ['invoices', 'status'], 'invoice_copy' => ['invoices', 'copy'],
    'offers' => ['offers', 'index'], 'offer_new' => ['offers', 'edit'], 'offer_edit' => ['offers', 'edit'], 'offer_save' => ['offers', 'save'], 'offer_show' => ['offers', 'show'],
    'offer_pdf' => ['offers', 'pdf'], 'offer_status' => ['offers', 'status'], 'offer_to_invoice' => ['offers', 'to_invoice'], 'offer_delete' => ['offers', 'delete'],
    'reminder_save' => ['reminders', 'save'], 'reminder_pdf' => ['reminders', 'pdf'], 'reminder_delete' => ['reminders', 'delete'],
    'settings' => ['settings', 'index'], 'settings_save' => ['settings', 'save'], 'password_save' => ['settings', 'password'], 'backup' => ['settings', 'backup'], 'logo' => ['settings', 'logo'],
];
$route = $_GET['r'] ?? 'dashboard';
if (!isset($routes[$route])) { http_response_code(404); $route = 'dashboard'; }

$hasUser = (int)db()->query("SELECT COUNT(*) FROM settings WHERE key = 'password_hash'")->fetchColumn() > 0;
if (!$hasUser && $route !== 'setup') redirect('setup');
if ($hasUser && !in_array($route, ['login', 'setup'], true)) require_login();

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
