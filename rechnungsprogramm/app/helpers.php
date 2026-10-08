<?php
declare(strict_types=1);

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function base_url(): string {
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    return rtrim($dir, '/');
}
function asset_url(string $f): string {
    // Liegt index.php direkt in public/ (Document-Root), sonst im Projektordner (Aufruf über Wurzel-index.php)
    $inPublic = is_file(dirname($_SERVER['SCRIPT_FILENAME'] ?? '') . '/assets/app.css');
    return base_url() . ($inPublic ? '' : '/public') . '/assets/' . $f;
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
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name('rechnung_sid');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    $dir = APP_STORAGE . '/sessions';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
    session_start();
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
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
function post(string $k, string $d = ''): string { return trim((string)($_POST[$k] ?? $d)); }

function parse_decimal(string $s): float {
    $s = trim(str_replace(['€', ' ', "\xc2\xa0"], '', $s));
    if ($s === '') return 0.0;
    if (str_contains($s, ',')) $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
    return is_numeric($s) ? (float)$s : 0.0;
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
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT name, value FROM settings') as $r) $cache[$r['name']] = (string)$r['value'];
    }
    return $cache[$key] ?? $default;
}
function set_setting(string $key, string $value): void {
    db_set(db(), $key, $value);
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
    require APP_ROOT . '/views/' . (in_array($view, ['login', 'setup'], true) ? 'layout_auth' : 'layout') . '.php';
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
