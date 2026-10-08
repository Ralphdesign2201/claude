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
        if (!empty($_SESSION['uid'])) {
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

// ---- Login-Bremse pro IP (Dateien in storage/throttle) ----
function throttle_file(): string {
    $d = APP_STORAGE . '/throttle'; if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d . '/' . sha1(($_SERVER['REMOTE_ADDR'] ?? 'cli') . '|' . (string)(setting('cron_token') ?: 'x')) . '.json';
}
function throttle_blocked(): int {
    $f = throttle_file(); $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    return max(0, (int)($d['until'] ?? 0) - time());
}
function throttle_fail(): void {
    $f = throttle_file(); $d = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    if (time() - (int)($d['t'] ?? 0) > 900) $d = ['n' => 0];
    $d['n'] = (int)($d['n'] ?? 0) + 1; $d['t'] = time();
    if ($d['n'] >= 5) $d['until'] = time() + min(900, 30 * ($d['n'] - 4));
    @file_put_contents($f, json_encode($d), LOCK_EX);
}
function throttle_clear(): void { @unlink(throttle_file()); }
