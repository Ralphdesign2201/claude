<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80000) { http_response_code(500); exit('PHP 8.0 oder neuer erforderlich.'); }
mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Berlin');

@umask(0007);
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
if (is_dir(APP_STORAGE) || @mkdir(APP_STORAGE, 0770, true)) { if (!is_dir(APP_STORAGE . '/logs')) @mkdir(APP_STORAGE . '/logs', 0770, true); if (is_writable(APP_STORAGE . '/logs')) @ini_set('error_log', APP_STORAGE . '/logs/php-error.log'); }

require_once APP_ROOT . '/helpers.php';
require_once APP_ROOT . '/security.php';
require_once APP_ROOT . '/db.php';
require_once APP_ROOT . '/tenant.php';
require_once APP_ROOT . '/version.php';
require_once APP_ROOT . '/dbtools.php';
require_once APP_ROOT . '/auth.php';
require_once APP_ROOT . '/migrations.php';
if (function_exists('app_mode') && app_mode() === 'saas') require_once APP_ROOT . '/saas.php';
require_once APP_ROOT . '/twofa.php';
