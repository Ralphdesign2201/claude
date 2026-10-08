<?php
declare(strict_types=1);
// Wird von cron.php aufgerufen: CLI (php cron.php) oder per URL mit ?token=...
require APP_ROOT . '/init.php';
$cli = PHP_SAPI === 'cli';
header('Content-Type: text/plain; charset=utf-8');
if (!$cli) {
    $tok = setting('cron_token');
    if ($tok === '' || !hash_equals($tok, (string)($_GET['token'] ?? ''))) { http_response_code(403); exit("Forbidden\n"); }
}
try {
    $force = $cli ? in_array('--force', $argv ?? [], true) : isset($_GET['force']);
    if ($force) { $n = backup_create('auto'); backup_prune(); echo "Backup erstellt (erzwungen): $n\n"; }
    elseif ($n = backup_run_if_due()) echo "Backup erstellt: $n\n";
    else echo "Kein Backup fällig (Intervall: " . setting('backup_interval', 'off') . ").\n";
} catch (Throwable $e) { http_response_code(500); echo 'Fehler: ' . $e->getMessage() . "\n"; error_log('cron: ' . $e->getMessage()); }
