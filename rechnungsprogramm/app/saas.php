<?php
declare(strict_types=1);
require_once APP_ROOT . '/central.php';

const RESERVED_SLUGS = ['www', 'admin', 'api', 'app', 'mail', 'superadmin', 'sa', 'static', 'assets', 'public', 'storage', 'cron', 'install', 'login', 'signup', 'billing', 'test', 'demo', 'support', 'hilfe', 'help', 'shop'];

function slug_error(string $s): ?string {
    if (!preg_match('/^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/', $s)) return 'Die Firmen-ID darf 3–40 Zeichen lang sein (Kleinbuchstaben, Ziffern, Bindestrich).';
    if (in_array($s, RESERVED_SLUGS, true)) return 'Diese Firmen-ID ist reserviert.';
    if (cq1('SELECT id FROM tenants WHERE slug = ?', [$s])) return 'Diese Firmen-ID ist schon vergeben.';
    return null;
}
function slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    $s = trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    return substr($s, 0, 40);
}

function tenant_row(string $slug): ?array { return cq1('SELECT t.*, p.name AS plan_name, p.max_users, p.max_invoices, p.price_cents, p.interval_unit, p.currency FROM tenants t LEFT JOIN plans p ON p.id = t.plan_id WHERE t.slug = ?', [$slug]); }
function tenant_row_id(int $id): ?array { return cq1('SELECT t.*, p.name AS plan_name, p.max_users, p.max_invoices, p.price_cents, p.interval_unit, p.currency FROM tenants t LEFT JOIN plans p ON p.id = t.plan_id WHERE t.id = ?', [$id]); }

function tenant_from_host(): ?string {
    $base = strtolower(csetting('base_domain'));
    $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
    if ($base !== '' && str_ends_with($host, '.' . $base)) {
        $sub = substr($host, 0, -strlen($base) - 1);
        if ($sub !== '' && !str_contains($sub, '.') && $sub !== 'www') return $sub;
    }
    return null;
}

function default_plan_id(): ?int {
    $id = (int)csetting('default_plan_id');
    if ($id && cq1('SELECT id FROM plans WHERE id = ? AND active = 1', [$id])) return $id;
    $p = cq1('SELECT id FROM plans WHERE active = 1 ORDER BY sort, price_cents LIMIT 1');
    return $p ? (int)$p['id'] : null;
}

/** Legt Mandant samt eigener Datenbank und Administrator an. $d: slug, company, owner_name, owner_email, username, password */
function tenant_create(array $d): array {
    if ($e = slug_error($d['slug'])) throw new RuntimeException($e);
    $days = max(0, (int)csetting('trial_days', '14'));
    cexec('INSERT INTO tenants (slug, company, owner_name, owner_email, plan_id, status, trial_ends, db_version) VALUES (?,?,?,?,?,?,?,?)',
        [$d['slug'], $d['company'], $d['owner_name'], $d['owner_email'], default_plan_id(), 'trial', date('Y-m-d', strtotime("+$days days")), app_version()]);
    try {
        tenant_use($d['slug']);
        $dir = data_dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new RuntimeException('Mandantenordner konnte nicht angelegt werden (storage/ schreibbar?).');
        @file_put_contents($dir . '/index.html', '');
        $pdo = db();
        db_begin($pdo);
        $roleId = seed_roles($pdo);
        $pdo->prepare('INSERT INTO users(username, display_name, email, password_hash, role_id, active) VALUES (?,?,?,?,?,1)')
            ->execute([$d['username'], $d['owner_name'], $d['owner_email'], password_hash($d['password'], PASSWORD_DEFAULT), $roleId]);
        db_commit($pdo);
        foreach (['company' => $d['company'], 'email' => $d['owner_email'], 'owner' => $d['owner_name'], 'payment_days' => '14', 'invoice_prefix' => 'RE-', 'cron_token' => bin2hex(random_bytes(16)), 'app_version' => app_version()] as $k => $v) set_setting($k, $v);
    } catch (Throwable $e) {
        tenant_use(null);
        cexec('DELETE FROM tenants WHERE slug = ?', [$d['slug']]);
        tenant_rmdir(APP_STORAGE . '/tenants/' . $d['slug']);
        throw $e;
    }
    tenant_use(null);
    return tenant_row($d['slug']);
}

function tenant_rmdir(string $dir): void {
    if (!is_dir($dir) || !str_contains(realpath($dir) ?: '', realpath(APP_STORAGE . '/tenants') ?: '#')) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($dir);
}
function tenant_delete(string $slug): void {
    tenant_use(null);
    cexec('DELETE FROM tenants WHERE slug = ?', [$slug]);
    tenant_rmdir(APP_STORAGE . '/tenants/' . $slug);
}

/** Stellt sicher, dass die Mandanten-Datenbank zum Code passt (nach Updates). */
function tenant_migrate_if_needed(array $t): void {
    if (($t['db_version'] ?? '') === app_version()) return;
    $prev = tenant_slug();
    tenant_use($t['slug']);
    db(); // migrate() läuft bei geänderter SCHEMA_VERSION automatisch
    run_pending_migrations(db());
    set_setting('app_version', app_version());
    cexec('UPDATE tenants SET db_version = ? WHERE id = ?', [app_version(), $t['id']]);
    tenant_use($prev);
}

/** Effektiver Status (setzt abgelaufene Testzeiträume/Abos lazy auf 'expired'). */
function tenant_effective(array $t): array {
    $today = date('Y-m-d'); $st = $t['status'];
    if ($st === 'trial' && $t['trial_ends'] && $t['trial_ends'] < $today) $st = 'expired';
    if ($st === 'canceled' && (!$t['period_end'] || $t['period_end'] < $today)) $st = 'expired';
    if ($st === 'active' && $t['provider'] !== '' && $t['period_end'] && date('Y-m-d', strtotime($t['period_end'] . ' +14 days')) < $today) $st = 'expired'; // Zahlungsmeldung blieb aus
    if ($st === 'past_due' && $t['period_end'] && date('Y-m-d', strtotime($t['period_end'] . ' +7 days')) < $today) $st = 'expired';
    if ($st !== $t['status'] && $st === 'expired') { cexec("UPDATE tenants SET status = 'expired' WHERE id = ?", [$t['id']]); $t['status'] = 'expired'; }
    $t['access'] = in_array($st, ['trial', 'active', 'past_due', 'canceled'], true);
    $t['status'] = $st;
    return $t;
}

function tenant_status_label(string $s): string {
    return ['trial' => 'Testphase', 'active' => 'Aktiv', 'past_due' => 'Zahlung offen', 'canceled' => 'Gekündigt', 'expired' => 'Abgelaufen', 'suspended' => 'Gesperrt'][$s] ?? $s;
}

/** Aktueller Mandant (nach tenant_boot), sonst null. */
function current_tenant(): ?array { return $GLOBALS['__tenant_row'] ?? null; }

function money_c(int $cents, string $cur = 'EUR'): string { return number_format($cents / 100, 2, ',', '.') . ' ' . ($cur === 'EUR' ? '€' : $cur); }

// ---- Superadmin-Sitzung ----
function sa_user(): ?array {
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['sa'])) { $u = cq1('SELECT * FROM superadmins WHERE id = ? AND active = 1', [(int)$_SESSION['sa']]); if (!$u) unset($_SESSION['sa']); }
    }
    return $u;
}
function require_sa(): void { if (!sa_user()) redirect('sa_login'); }

/** Systemmail über die zentralen Mail-Einstellungen. */
function central_mail(string $to, string $subject, string $body): ?string {
    require_once APP_ROOT . '/mail.php';
    $GLOBALS['__mail_central'] = true;
    try { return send_mail($to, $subject, $body); } finally { $GLOBALS['__mail_central'] = false; }
}

function sa_billing_ready(): array {
    return ['stripe' => csetting('stripe_secret') !== '', 'paypal' => csetting('paypal_client_id') !== '' && csetting('paypal_secret') !== ''];
}

function send_verify_mail(array $t): void {
    $tok = bin2hex(random_bytes(32));
    cexec('UPDATE tenants SET verify_token = ? WHERE id = ?', [hash('sha256', $tok), $t['id']]);
    central_mail($t['owner_email'], 'Bitte E-Mail-Adresse bestätigen – ' . csetting('brand_name', APP_NAME), "Hallo " . $t['owner_name'] . ",\n\nbitte bestätigen Sie Ihre E-Mail-Adresse, damit Sie Rechnungen und Angebote per E-Mail an Ihre Kunden senden können:\n\n" . app_url('verify', ['t' => $t['slug'], 'k' => $tok]) . "\n");
}
