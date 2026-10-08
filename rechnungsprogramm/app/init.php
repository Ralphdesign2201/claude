<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80000) { http_response_code(500); exit('PHP 8.0 oder neuer erforderlich.'); }
mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Berlin');

require_once APP_ROOT . '/helpers.php';
require_once APP_ROOT . '/db.php';
require_once APP_ROOT . '/dbtools.php';
require_once APP_ROOT . '/auth.php';
