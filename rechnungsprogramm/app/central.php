<?php
declare(strict_types=1);

/** Zentrale Datenbank des SaaS-Betriebs (Mandanten, Tarife, Zahlungen, Superadmins). */
const CENTRAL_SCHEMA_VERSION = 'c2';

function central_schema(): array {
    return [
        'settings' => ['pk' => 'name', 'cols' => ['name' => 'str:64', 'value' => 'str:4000']],
        'superadmins' => ['cols' => ['id' => 'pk', 'username' => 'str:60!', 'display_name' => 'str:120', 'email' => 'str:190', 'password_hash' => 'str:255', 'active' => 'int', 'created_at' => 'ts', 'last_login' => 'ts?',
            'totp_secret' => 'str:255', 'totp_enabled' => 'int', 'totp_last' => 'int', 'recovery_codes' => 'str:1000', 'failed_logins' => 'int', 'locked_until' => 'ts?']],
        'plans' => ['cols' => ['id' => 'pk', 'name' => 'str:80', 'description' => 'str:500', 'price_cents' => 'int', 'interval_unit' => 'str:8=month', 'currency' => 'str:3=EUR',
            'max_users' => 'int', 'max_invoices' => 'int', 'active' => 'int', 'sort' => 'int', 'paypal_product_id' => 'str:64', 'paypal_plan_id' => 'str:64', 'stripe_price_id' => 'str:64']],
        'tenants' => ['cols' => ['id' => 'pk', 'slug' => 'str:40!', 'company' => 'str:190', 'owner_name' => 'str:120', 'owner_email' => 'str:190', 'plan_id' => 'int?>plans:SET NULL',
            'status' => 'str:12=trial', 'trial_ends' => 'date?', 'period_end' => 'date?', 'provider' => 'str:12', 'provider_customer' => 'str:64', 'provider_subscription' => 'str:64',
            'cancel_at_period_end' => 'int', 'notes' => 'str:2000', 'db_version' => 'str:12', 'created_at' => 'ts', 'last_login' => 'ts?', 'warned_trial' => 'int', 'email_verified' => 'int', 'verify_token' => 'str:64']],
        'payments' => ['cols' => ['id' => 'pk', 'tenant_id' => 'int>tenants:CASCADE', 'provider' => 'str:12', 'provider_ref' => 'str:100!', 'amount_cents' => 'int', 'currency' => 'str:3=EUR',
            'status' => 'str:20', 'description' => 'str:255', 'created_at' => 'ts']],
        'audit_log' => ['cols' => ['id' => 'pk', 'ts' => 'ts', 'username' => 'str:120', 'action' => 'str:60', 'detail' => 'str:500', 'ip' => 'str:45'], 'index' => [['ts']]],
        'password_resets' => ['cols' => ['id' => 'pk', 'sa_id' => 'int>superadmins:CASCADE', 'token_hash' => 'str:64!', 'expires' => 'ts', 'used' => 'int', 'created_at' => 'ts']],
        'webhook_events' => ['cols' => ['id' => 'pk', 'provider' => 'str:12', 'event_id' => 'str:100!', 'type' => 'str:80', 'created_at' => 'ts']],
    ];
}

function cdb(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('PHP-Erweiterung pdo_sqlite fehlt.');
    $pdo = new PDO('sqlite:' . APP_STORAGE . '/central.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_STATEMENT_CLASS => ['RgStatement']]);
    $pdo->exec('PRAGMA busy_timeout = 10000'); $pdo->exec('PRAGMA foreign_keys = ON'); @$pdo->exec('PRAGMA journal_mode = WAL'); $pdo->exec('PRAGMA synchronous = NORMAL');
    $ready = false;
    try { $st = $pdo->query("SELECT value FROM settings WHERE name = 'schema_version'"); $ready = $st && $st->fetchColumn() === CENTRAL_SCHEMA_VERSION; } catch (PDOException $e) { if (!preg_match('/no such table/i', $e->getMessage())) throw $e; }
    if (!$ready) migrate($pdo, central_schema(), CENTRAL_SCHEMA_VERSION);
    return $pdo;
}

function csetting(string $key, string $default = ''): string {
    static $cache = null;
    if ($key === '__reset') { $cache = null; return ''; }
    if ($cache === null) { $cache = []; foreach (cdb()->query('SELECT name, value FROM settings') as $r) $cache[$r['name']] = in_array($r['name'], SECRET_SETTINGS, true) ? secret_decrypt((string)$r['value']) : (string)$r['value']; }
    return $cache[$key] ?? $default;
}
function set_csetting(string $key, string $value): void { db_set(cdb(), $key, in_array($key, SECRET_SETTINGS, true) ? secret_encrypt($value) : $value); csetting('__reset'); }

function cq(string $sql, array $p = []): array { $st = cdb()->prepare($sql); $st->execute($p); return $st->fetchAll(); }
function cq1(string $sql, array $p = []): ?array { $r = cq($sql, $p); return $r[0] ?? null; }
function cexec(string $sql, array $p = []): void { cdb()->prepare($sql)->execute($p); }
