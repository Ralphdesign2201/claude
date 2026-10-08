<?php
declare(strict_types=1);

/** Module für die Rechtevergabe: Schlüssel => Bezeichnung. Stufen je Rolle: '' (kein Zugriff), 'r' (lesen), 'w' (lesen und ändern). */
const MODULES = [
    'customers' => 'Kunden', 'invoices' => 'Rechnungen & Mahnungen', 'offers' => 'Angebote', 'deliveries' => 'Lieferscheine',
    'mail' => 'E-Mail-Versand', 'export' => 'Export (DATEV, CSV)', 'settings' => 'Firmendaten & Einstellungen',
    'users' => 'Benutzer & Rollen', 'system' => 'Datenbank & Backups',
];

function role_all_write(): array { return array_fill_keys(array_keys(MODULES), 'w'); }

function seed_roles(PDO $pdo): int {
    $mk = fn(string $name, array $perm, int $sys) => $pdo->prepare('INSERT INTO roles(name, permissions, is_system) VALUES (?,?,?)')->execute([$name, json_encode($perm), $sys]);
    $mk('Administrator', role_all_write(), 1);
    $office = role_all_write(); $office['settings'] = 'r'; $office['users'] = ''; $office['system'] = '';
    $mk('Büro', $office, 0);
    $mk('Lesezugriff', ['customers' => 'r', 'invoices' => 'r', 'offers' => 'r', 'deliveries' => 'r'], 0);
    return (int)$pdo->query("SELECT id FROM roles WHERE is_system = 1 ORDER BY id LIMIT 1")->fetchColumn();
}

/** Angemeldeter Benutzer inkl. Rolle (pro Request einmal geladen) oder null. */
function current_user(bool $reload = false): ?array {
    static $u = false;
    if ($u === false || $reload) {
        $u = null;
        if (!empty($_SESSION['uid']) && !(is_saas() && tenant_slug() === null)) {
            $st = db()->prepare('SELECT u.*, r.name AS role_name, r.permissions, r.is_system FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.active = 1');
            $st->execute([(int)$_SESSION['uid']]);
            $row = $st->fetch();
            if ($row) { $row['perms'] = ((int)$row['is_system'] === 1) ? role_all_write() : (json_decode((string)$row['permissions'], true) ?: []); $u = $row; }
            else unset($_SESSION['uid']);
        }
    }
    return $u;
}
function logged_in(): bool { return current_user() !== null; }
function require_login(): void { if (!logged_in()) redirect('login'); }
function can(string $module, string $level = 'r'): bool {
    $u = current_user();
    if (!$u) return false;
    $have = $u['perms'][$module] ?? '';
    return $level === 'r' ? ($have === 'r' || $have === 'w') : $have === 'w';
}
function require_can(string $module, string $level = 'r'): void {
    if (can($module, $level)) return;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { flash('Dafür fehlt Ihnen die Berechtigung.', 'err'); redirect('dashboard'); }
    http_response_code(403);
    render('error', ['message' => 'Dafür fehlt Ihnen die Berechtigung.'], 'Kein Zugriff');
    exit;
}

/** Anzahl aktiver Benutzer mit Administrator-Rolle (außer $exceptId). */
function active_admin_count(int $exceptId = 0): int {
    $st = db()->prepare('SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.is_system = 1 AND u.active = 1 AND u.id <> ?');
    $st->execute([$exceptId]);
    return (int)$st->fetchColumn();
}

// ---- Konto-Sperre und zweiter Faktor (gemeinsam für Mandanten-Benutzer und Superadmins) ----
const ACCOUNT_TABLES = ['users', 'superadmins'];

/** Zählt einen Fehlversuch am Konto; sperrt nach 5 Fehlversuchen mit steigender Wartezeit. */
function account_fail(PDO $pdo, string $table, array $u): void {
    if (!in_array($table, ACCOUNT_TABLES, true)) return;
    $n = (int)$u['failed_logins'] + 1;
    $lock = $n >= 5 ? date('Y-m-d H:i:s', time() + min(3600, 60 * 2 ** ($n - 5))) : null;
    $pdo->prepare("UPDATE $table SET failed_logins = ?, locked_until = ? WHERE id = ?")->execute([$n, $lock, $u['id']]);
}
function account_ok(PDO $pdo, string $table, array $u): void {
    if (!in_array($table, ACCOUNT_TABLES, true)) return;
    $pdo->prepare("UPDATE $table SET failed_logins = 0, locked_until = NULL, last_login = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $u['id']]);
}
function account_locked(array $u): bool { return !empty($u['locked_until']) && strtotime((string)$u['locked_until']) > time(); }

/** Prüft TOTP-Code oder Wiederherstellungscode; verbraucht ihn. */
function second_factor_ok(PDO $pdo, string $table, array $u, string $code): bool {
    if (!in_array($table, ACCOUNT_TABLES, true)) return false;
    $code = trim($code);
    $step = totp_verify(secret_decrypt((string)$u['totp_secret']), $code, (int)$u['totp_last']);
    if ($step !== null) { $pdo->prepare("UPDATE $table SET totp_last = ? WHERE id = ?")->execute([$step, $u['id']]); return true; }
    $h = hash('sha256', str_replace('-', '', strtolower($code)));
    $codes = json_decode((string)$u['recovery_codes'], true) ?: [];
    foreach ($codes as $i => $c) if (hash_equals($c, $h)) { unset($codes[$i]); $pdo->prepare("UPDATE $table SET recovery_codes = ? WHERE id = ?")->execute([json_encode(array_values($codes)), $u['id']]); audit('2fa_recovery_used', 'Wiederherstellungscode verwendet', (string)$u['username'], $table === 'superadmins'); return true; }
    return false;
}

