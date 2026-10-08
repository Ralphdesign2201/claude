<?php
// Cronjob-Einstieg (Docroot = Projektordner)
define('APP_ROOT', __DIR__ . '/app');
define('APP_STORAGE', __DIR__ . '/storage');
require APP_ROOT . '/cron_run.php';
