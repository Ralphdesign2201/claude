<?php
declare(strict_types=1);
// Wird von cron.php aufgerufen: CLI (php cron.php [--force]) oder per URL mit ?token=...
require APP_ROOT . '/init.php';
$cli = PHP_SAPI === 'cli';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
if (!is_installed()) { http_response_code(503); exit("Nicht installiert\n"); }
$saas = is_saas();
if (!$cli) {
    $tok = $saas ? csetting('cron_token') : setting('cron_token');
    if (!rate_hit('cron', 30, 600) || $tok === '' || !hash_equals($tok, (string)($_GET['token'] ?? ''))) { http_response_code(403); exit("Forbidden\n"); }
}
$force = $cli ? in_array('--force', $argv ?? [], true) : isset($_GET['force']);

/** Aufräumen: alte Sitzungs-, Drosselungs- und Update-Dateien, alte Audit-Einträge. */
function cron_cleanup(): void {
    foreach (glob(APP_STORAGE . '/sessions/sess_*') ?: [] as $f) if (@filemtime($f) < time() - 2 * 86400) @unlink($f);
    foreach (glob(APP_STORAGE . '/throttle/*.json') ?: [] as $f) if (@filemtime($f) < time() - 2 * 86400) @unlink($f);
    foreach (glob(APP_STORAGE . '/update_pending/*.rgu') ?: [] as $f) if (@filemtime($f) < time() - 86400) @unlink($f);
}
function cron_prune_audit(PDO $pdo): void { $pdo->prepare('DELETE FROM audit_log WHERE ts < ?')->execute([date('Y-m-d H:i:s', strtotime('-180 days'))]); }

try {
    cron_cleanup();
    if (!$saas) {
        if ($force) { $n = backup_create('auto'); backup_prune(); echo "Backup erstellt (erzwungen): $n\n"; }
        elseif ($n = backup_run_if_due()) echo "Backup erstellt: $n\n";
        else echo "Kein Backup fällig (Intervall: " . setting('backup_interval', 'off') . ").\n";
        cron_prune_audit(db());
    } else {
        $n = 0; $errs = 0; $warn = 0; $t0 = time();
        cron_prune_audit(cdb());
        foreach (cq('SELECT * FROM tenants ORDER BY id') as $t) {
            if (time() - $t0 > 240) { echo "Zeitlimit erreicht – Rest beim nächsten Lauf.\n"; break; }
            try {
                $t = tenant_effective($t);
                tenant_migrate_if_needed($t);
                tenant_use($t['slug']);
                if ($force ? (bool)backup_create('auto') : (bool)backup_run_if_due()) { backup_prune(); $n++; }
                cron_prune_audit(db());
                // Hinweis kurz vor Ende der Testphase
                if ($t['status'] === 'trial' && $t['trial_ends'] && !(int)$t['warned_trial'] && strtotime($t['trial_ends']) - time() < 3 * 86400 && strtotime($t['trial_ends']) >= strtotime('today')) {
                    $e = central_mail($t['owner_email'], 'Ihre Testphase endet bald – ' . csetting('brand_name', APP_NAME), "Hallo " . $t['owner_name'] . ",\n\nIhre Testphase für " . $t['company'] . " endet am " . date_de($t['trial_ends']) . ". Wählen Sie jetzt einen Tarif, damit Sie ohne Unterbrechung weiterarbeiten können:\n\n" . app_url('billing') . "\n\nIhre Daten bleiben in jedem Fall erhalten.\n");
                    if ($e === null) { cexec('UPDATE tenants SET warned_trial = 1 WHERE id = ?', [$t['id']]); $warn++; }
                }
            } catch (Throwable $e) { $errs++; error_log('cron tenant ' . $t['slug'] . ': ' . $e->getMessage()); }
            finally { tenant_use(null); }
        }
        echo "Mandanten geprüft; Backups: $n, Trial-Hinweise: $warn, Fehler: $errs.\n";
    }
} catch (Throwable $e) { http_response_code(500); echo "Fehler\n"; error_log('cron: ' . $e->getMessage()); }
