<?php
declare(strict_types=1);

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function base_url(): string {
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    return rtrim($dir, '/');
}
function asset_url(string $f): string {
    // Wird über PHP ausgeliefert (index.php?r=asset): immer die Datei aus dem Projektordner, nie ein veralteter Cache; v = Inhalts-Stempel
    $p = dirname(APP_ROOT) . '/public/assets/' . basename($f);
    $v = is_file($p) ? substr((string)sha1_file($p), 0, 10) : APP_VERSION;
    return base_url() . '/index.php?r=asset&f=' . rawurlencode(basename($f)) . '&v=' . $v;
}
function url(string $route = 'dashboard', array $params = []): string {
    return base_url() . '/index.php?' . http_build_query(['r' => $route] + $params);
}
function redirect(string $route, array $params = []) {
    header('Location: ' . url($route, $params));
    exit;
}

// ---- Session, CSRF, Flash ----
function start_session(): void {
    @ini_set('session.use_strict_mode', '1'); @ini_set('session.use_only_cookies', '1'); @ini_set('session.use_trans_sid', '0'); @ini_set('session.gc_maxlifetime', '86400');
    session_name('rg_sid');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => request_is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    $dir = APP_STORAGE . '/sessions';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
    session_start();
    if (mt_rand(1, 100) === 1) foreach (glob($dir . '/sess_*') ?: [] as $f) if (@filemtime($f) < time() - 2 * 86400) @unlink($f);
    // Leerlauf- und Gesamtdauer, Bindung an den Browser (User-Agent)
    $now = time(); $ua = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if (isset($_SESSION['_t0']) && ($now - ($_SESSION['_last'] ?? 0) > 8 * 3600 || $now - $_SESSION['_t0'] > 24 * 3600 || ($_SESSION['_ua'] ?? '') !== $ua)) {
        $_SESSION = []; session_regenerate_id(true);
    }
    if (!isset($_SESSION['_t0'])) { $_SESSION['_t0'] = $now; $_SESSION['_ua'] = $ua; }
    $_SESSION['_last'] = $now;
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Ungültiges Formular-Token. Bitte Seite neu laden.');
    }
}
function flash(?string $msg = null, string $type = 'ok') {
    if ($msg !== null) { $_SESSION['flash'][] = [$type, $msg]; return null; }
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// ---- Eingaben / Formate ----
function post(string $k, string $d = ''): string { $v = $_POST[$k] ?? $d; return is_scalar($v) ? trim((string)$v) : $d; }

/** Entfernt unerwartete Array-Eingaben (verhindert Typfehler/Warnungen durch manipulierte Anfragen). */
function sanitize_input(): void {
    $arrayFields = ['description', 'quantity', 'unit', 'unit_price', 'vat_rate', 'perm'];
    foreach ($_GET as $k => $v) if (!is_string($v)) unset($_GET[$k]);
    foreach ($_COOKIE as $k => $v) if (!is_string($v)) unset($_COOKIE[$k]);
    foreach ($_POST as $k => $v) {
        if (is_array($v)) {
            if (!in_array(preg_replace('/\[\]$/', '', (string)$k), $arrayFields, true)) { unset($_POST[$k]); continue; }
            foreach ($v as $i => $x) if (!is_scalar($x)) unset($_POST[$k][$i]);
        }
    }
    foreach ($_FILES as $k => $f) if (!is_array($f) || !is_string($f['name'] ?? null) || !is_string($f['tmp_name'] ?? null)) unset($_FILES[$k]);
}

function parse_decimal(string $s): float {
    $s = trim(str_replace(['€', ' ', "\xc2\xa0"], '', $s));
    if ($s === '') return 0.0;
    if (str_contains($s, ',')) $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
    return is_numeric($s) ? (float)$s : 0.0;
}
/** Ist das eine gültige Geldangabe (1.234,56 / 1234.56 / 12 €)? Leer zählt als gültig (= 0). */
function money_valid(string $s): bool {
    $s = trim(str_replace(['€', ' ', "\xc2\xa0"], '', $s));
    return $s === '' || (bool)preg_match('/^\d{1,3}(\.\d{3})*(,\d{1,4})?$|^\d+([.,]\d{1,4})?$/', $s);
}
function parse_cents(string $s): int { return (int)round(parse_decimal($s) * 100); }

function money(int $cents): string { return number_format($cents / 100, 2, ',', '.') . ' €'; }
function money_plain(int $cents): string { return number_format($cents / 100, 2, ',', '.'); }
function qty_fmt(float $q): string {
    $s = rtrim(rtrim(number_format($q, 3, ',', '.'), '0'), ',');
    return $s === '' ? '0' : $s;
}
function date_de(?string $iso): string {
    if (!$iso) return '';
    $t = strtotime($iso);
    return $t ? date('d.m.Y', $t) : '';
}
function valid_date(string $s): ?string {
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return ($d && $d->format('Y-m-d') === $s) ? $s : null;
}

// ---- Einstellungen ----
function setting(string $key, string $default = ''): string {
    static $cache = [];
    $ck = data_dir();
    if ($key === '__reset') { unset($cache[$ck]); return ''; }
    if (!isset($cache[$ck])) {
        $cache[$ck] = [];
        foreach (db()->query('SELECT name, value FROM settings') as $r) $cache[$ck][$r['name']] = in_array($r['name'], SECRET_SETTINGS, true) ? secret_decrypt((string)$r['value']) : (string)$r['value'];
    }
    return $cache[$ck][$key] ?? $default;
}
function set_setting(string $key, string $value): void {
    db_set(db(), $key, in_array($key, SECRET_SETTINGS, true) ? secret_encrypt($value) : $value);
    setting('__reset');
}

// ---- Rechnungsberechnung ----
/** @param array<int,array{quantity:float,unit_price:int,vat_rate:float}> $items */
function calc_invoice(array $items): array {
    $net = 0; $byRate = []; $lines = [];
    foreach ($items as $it) {
        $total = (int)round($it['quantity'] * $it['unit_price']);
        $lines[] = $total;
        $net += $total;
        $k = (string)$it['vat_rate'];
        $byRate[$k] = ($byRate[$k] ?? 0) + $total;
    }
    $vat = 0; $vatByRate = [];
    foreach ($byRate as $rate => $sum) {
        $v = (int)round($sum * (float)$rate / 100);
        $vatByRate[$rate] = $v;
        $vat += $v;
    }
    return ['lines' => $lines, 'net' => $net, 'vat' => $vat, 'gross' => $net + $vat, 'vat_by_rate' => $vatByRate, 'net_by_rate' => $byRate];
}

function status_label(string $s, bool $overdue = false): string {
    if ($s === 'open') return $overdue ? 'Überfällig' : 'Offen';
    return ['paid' => 'Bezahlt', 'cancelled' => 'Storniert'][$s] ?? $s;
}
function is_overdue(array $inv): bool {
    return $inv['status'] === 'open' && $inv['due_date'] < date('Y-m-d');
}

function render(string $view, array $vars = [], string $title = ''): void {
    extract($vars);
    ob_start();
    require APP_ROOT . '/views/' . $view . '.php';
    $content = ob_get_clean();
    require APP_ROOT . '/views/' . ($GLOBALS['__layout'] ?? (in_array($view, ['login', 'sa_login', 'login_2fa', 'forgot', 'reset'], true) ? 'layout_auth' : 'layout')) . '.php';
}

const REMINDER_LEVELS = [1 => 'Zahlungserinnerung', 2 => '1. Mahnung', 3 => '2. Mahnung (letzte Mahnung)'];
function reminder_default_text(int $level, array $inv): string {
    $nr = $inv['invoice_number']; $d = date_de($inv['invoice_date']);
    return [
        1 => "sicherlich ist es Ihrer Aufmerksamkeit entgangen, dass unsere Rechnung $nr vom $d noch nicht beglichen wurde. Wir bitten Sie, den offenen Betrag bis zum unten genannten Datum zu überweisen. Sollten Sie bereits gezahlt haben, betrachten Sie dieses Schreiben bitte als gegenstandslos.",
        2 => "trotz unserer Zahlungserinnerung konnten wir für die Rechnung $nr vom $d keinen Zahlungseingang feststellen. Wir bitten Sie dringend, den offenen Betrag zuzüglich der aufgeführten Kosten bis zum unten genannten Datum zu überweisen.",
        3 => "die Rechnung $nr vom $d ist trotz Mahnung weiterhin offen. Wir fordern Sie letztmalig auf, den Gesamtbetrag bis zum unten genannten Datum zu begleichen. Danach behalten wir uns vor, ohne weitere Ankündigung rechtliche Schritte einzuleiten; dadurch entstehende Kosten gehen zu Ihren Lasten.",
    ][$level];
}

function offer_status_label(string $s, bool $expired = false): string {
    if ($s === 'open') return $expired ? 'Abgelaufen' : 'Offen';
    return ['accepted' => 'Angenommen', 'declined' => 'Abgelehnt'][$s] ?? $s;
}

// ---- Navigation und Darstellung ----
/** Aktive Oberfläche: eigene Wahl des Benutzers, sonst Standard des Betriebs ('top' = obere Leiste, 'side' = Seitenleiste). */
function layout_locked(): bool { return is_saas() && csetting('tenant_layout_lock', '0') === '1'; }
/** Plattform-Vorgabe für Mandanten (SaaS), sonst "top". */
function platform_layout(): string { return is_saas() && csetting('tenant_layout', 'top') === 'side' ? 'side' : 'top'; }
/** Standard ohne eigene Benutzerwahl. */
function ui_layout_default(): string { $v = setting('ui_layout', ''); return in_array($v, ['top', 'side'], true) ? $v : platform_layout(); }
function ui_layout(): string {
    if (layout_locked()) return platform_layout();
    $u = current_user();
    $own = (string)($u['ui_layout'] ?? '');
    if (in_array($own, ['top', 'side'], true)) return $own;
    $v = setting('ui_layout', '');
    if (!in_array($v, ['top', 'side'], true)) $v = platform_layout();
    return $v === 'side' ? 'side' : 'top';
}

/** Menügruppen für Kopf- und Seitenleiste (nur, was der Benutzer sehen darf). @return array<int,array{0:string,1:array}> */
function nav_groups(): array {
    $tn = is_saas() ? current_tenant() : null; $u = current_user();
    $g = [
        ['', [['dashboard', 'Übersicht', true, ['dashboard']]]],
        ['Verkauf', [['offers', 'Angebote', can('offers'), ['offer']], ['invoices', 'Rechnungen', can('invoices'), ['invoice', 'reminder']], ['deliveries', 'Lieferscheine', can('deliveries'), ['deliver']]]],
        ['Stammdaten', [['customers', 'Kunden', can('customers'), ['customer']], ['catalog', 'Leistungen & Artikel', can('catalog'), ['catalog']]]],
        ['Auswertung', [['datev', 'Export', can('export'), ['datev']]]],
        ['Verwaltung', [
            ['settings', 'Einstellungen', can('settings'), ['settings']],
            ['backups', 'Datenbank & Backups', can('system'), ['backups', 'backup']],
            ['updates', 'Updates', can('system') && !is_saas(), ['update']],
            ['users', 'Benutzer', can('users'), ['user']],
            ['roles', 'Rollen', can('users'), ['role']],
            ['audit', 'Protokoll', can('users'), ['audit']],
            ['billing', 'Abo & Zahlung', $tn && (int)($u['is_system'] ?? 0) === 1, ['billing']],
        ]],
    ];
    foreach ($g as &$grp) $grp[1] = array_values(array_filter($grp[1], fn($i) => $i[2]));
    unset($grp);
    return array_values(array_filter($g, fn($grp) => $grp[1] !== []));
}
function nav_active(string $cur, array $prefixes): bool { foreach ($prefixes as $p) if (str_starts_with($cur, $p)) return true; return false; }

/** Aktive Katalogeinträge für die Auswahl in Rechnungen, Angeboten und Lieferscheinen. */
function catalog_active(): array {
    if (!can('catalog')) return [];
    return db()->query("SELECT * FROM catalog_items WHERE active = 1 ORDER BY kind, LOWER(name) LIMIT 2000")->fetchAll();
}

