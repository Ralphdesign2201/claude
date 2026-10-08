<?php
declare(strict_types=1);

/** Betriebsart und Mandanten-Kontext. 'single' = Einzelinstallation, 'saas' = Mehrmandanten-Betrieb mit Superadmin. */
function app_mode(): string { return (app_config()['mode'] ?? 'single') === 'saas' ? 'saas' : 'single'; }
function is_saas(): bool { return app_mode() === 'saas'; }
function is_installed(): bool { return is_file(APP_STORAGE . '/installed.lock'); }

/** Aktuell gewählter Mandant (Slug) – im Einzelbetrieb immer null. */
function tenant_slug(): ?string { return $GLOBALS['__tenant_slug'] ?? null; }
function tenant_use(?string $slug): void {
    if ($slug !== null && !preg_match('/^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/', $slug)) throw new InvalidArgumentException('Ungültiger Mandant.');
    $GLOBALS['__tenant_slug'] = $slug;
}
/** Verzeichnis für Datenbank, Logo und Backups des aktuellen Mandanten. */
function data_dir(): string {
    $s = tenant_slug();
    return $s === null ? APP_STORAGE : APP_STORAGE . '/tenants/' . $s;
}

/** Prüft Plan-Limits; liefert Fehlertext oder null. $what: 'users' | 'invoices' */
function saas_limit_error(string $what): ?string {
    if (!is_saas() || !($t = current_tenant())) return null;
    if ($what === 'users' && (int)$t['max_users'] > 0) {
        $n = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($n >= (int)$t['max_users']) return 'Ihr Tarif „' . $t['plan_name'] . '“ erlaubt höchstens ' . (int)$t['max_users'] . ' Benutzer. Bitte unter „Abo“ den Tarif wechseln.';
    }
    if ($what === 'invoices' && (int)$t['max_invoices'] > 0) {
        $st = db()->prepare('SELECT COUNT(*) FROM invoices WHERE invoice_date >= ?'); $st->execute([date('Y-m-01')]);
        if ((int)$st->fetchColumn() >= (int)$t['max_invoices']) return 'Ihr Tarif „' . $t['plan_name'] . '“ erlaubt höchstens ' . (int)$t['max_invoices'] . ' Rechnungen pro Monat. Bitte unter „Abo“ den Tarif wechseln.';
    }
    return null;
}

