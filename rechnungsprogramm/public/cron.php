<?php
// Cronjob-Einstieg (Docroot = public/)
define('APP_ROOT', dirname(__DIR__) . '/app');
define('APP_STORAGE', dirname(__DIR__) . '/storage');
require APP_ROOT . '/cron_run.php';
