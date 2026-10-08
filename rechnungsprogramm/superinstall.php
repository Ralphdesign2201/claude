<?php
/**
 * HandwerkRechnung – Installation SaaS-Version (mit Superadmin, Mandanten, Tarifen und Zahlungen).
 * Aufruf im Browser: https://ihre-domain.de/superinstall.php   –   nach der Installation bitte löschen.
 */
declare(strict_types=1);
define('APP_ROOT', __DIR__ . '/app');
define('APP_STORAGE', __DIR__ . '/storage');
if (!is_dir(APP_STORAGE)) @mkdir(APP_STORAGE, 0775, true);
require APP_ROOT . '/installer_lib.php';

if (is_file(APP_STORAGE . '/installed.lock')) {
    if (($_POST['do'] ?? '') === 'delete') { inst_selfdelete(__FILE__); header('Location: index.php'); exit; }
    inst_page('Bereits installiert', '<h1>Bereits installiert</h1><div class="card"><p>Die SaaS-Plattform ist schon eingerichtet. Aus Sicherheitsgründen sollte <code>superinstall.php</code> jetzt gelöscht werden.</p><form method="post"><input type="hidden" name="do" value="delete"><button class="btn">superinstall.php löschen</button> <a class="btn alt" href="index.php">Zur Plattform</a></form></div>');
    exit;
}

require APP_ROOT . '/init.php';
start_session();
$req = inst_requirements(true);
$err = ''; $v = ['brand' => 'HandwerkRechnung', 'operator' => '', 'sa_user' => 'superadmin', 'sa_name' => '', 'sa_email' => '', 'base_domain' => '', 'trial_days' => '14'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    inst_check();
    foreach ($v as $k => $_) $v[$k] = trim((string)($_POST[$k] ?? $v[$k]));
    $pw = (string)($_POST['password'] ?? '');
    if (inst_blocking($req)) $err = 'Bitte zuerst die erforderlichen Voraussetzungen erfüllen.';
    elseif ($v['brand'] === '') $err = 'Bitte einen Produktnamen angeben.';
    elseif (!inst_valid_user($v['sa_user'])) $err = 'Der Benutzername darf 3–60 Zeichen lang sein (Buchstaben, Ziffern, . _ @ -).';
    elseif (!filter_var($v['sa_email'], FILTER_VALIDATE_EMAIL)) $err = 'Bitte eine gültige E-Mail-Adresse für den Superadmin angeben.';
    elseif ($e = (function () use ($pw, $v) { require_once APP_ROOT . '/security.php'; return password_error($pw, $v['sa_user'], 10); })()) $err = $e;
    elseif ($pw !== (string)($_POST['password2'] ?? '')) $err = 'Die Passwörter stimmen nicht überein.';
    elseif ($v['base_domain'] !== '' && !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $v['base_domain'])) $err = 'Die Basis-Domain ist ungültig (z. B. meine-software.de).';
    else {
        try {
            save_config_early(['mode' => 'saas', 'driver' => 'sqlite', 'base_url' => inst_base_url(), 'force_https' => inst_is_https() && isset($_POST['force_https'])]);
            app_config(true);
            require_once APP_ROOT . '/saas.php';
            $cp = cdb();
            cexec('INSERT INTO superadmins(username, display_name, email, password_hash, active) VALUES (?,?,?,?,1)', [$v['sa_user'], $v['sa_name'], $v['sa_email'], password_hash($pw, PASSWORD_DEFAULT)]);
            $set = ['brand_name' => $v['brand'], 'company' => $v['operator'] !== '' ? $v['operator'] : $v['brand'], 'email' => $v['sa_email'], 'base_domain' => strtolower($v['base_domain']), 'trial_days' => (string)max(0, (int)$v['trial_days']),
                'currency' => 'EUR', 'payment_mode' => 'sandbox', 'cron_token' => bin2hex(random_bytes(16)), 'sa_require_2fa' => '1', 'app_version' => APP_VERSION, 'signup_open' => '1',
                'page_impressum' => "Bitte tragen Sie hier Ihr Impressum ein (Superadmin → Einstellungen → Rechtstexte).",
                'page_datenschutz' => "Bitte tragen Sie hier Ihre Datenschutzerklärung ein (Superadmin → Einstellungen → Rechtstexte).",
                'page_agb' => "Bitte tragen Sie hier Ihre Allgemeinen Geschäftsbedingungen ein (Superadmin → Einstellungen → Rechtstexte)."];
            foreach ($set as $k => $val) set_csetting($k, $val);
            $mk = fn($n, $d, $p, $u, $i, $s) => cexec('INSERT INTO plans(name, description, price_cents, interval_unit, currency, max_users, max_invoices, active, sort) VALUES (?,?,?,?,?,?,?,1,?)', [$n, $d, $p, 'month', 'EUR', $u, $i, $s]);
            $mk('Starter', 'Für Einzelunternehmer', 990, 1, 30, 1);
            $mk('Business', 'Für kleine Betriebe', 1990, 5, 0, 2);
            $mk('Pro', 'Für wachsende Betriebe', 3990, 20, 0, 3);
            set_csetting('default_plan_id', (string)cq1("SELECT id FROM plans WHERE name = 'Business'")['id']);
            @mkdir(APP_STORAGE . '/tenants', 0775, true); @file_put_contents(APP_STORAGE . '/tenants/index.html', '');
            $probe = inst_probe(__DIR__);
            audit('installed', 'Installation (SaaS) abgeschlossen', $v['sa_user'], true);
            file_put_contents(APP_STORAGE . '/installed.lock', date('c') . "\nmode=saas\nversion=" . APP_VERSION . "\n");
            $base = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'ihre-domain.de') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
            inst_page('Installation abgeschlossen', '<h1>SaaS-Plattform eingerichtet ✓</h1><div class="card"><p>Version ' . inst_h(APP_VERSION) . '. Superadmin-Login:</p><p><code>' . inst_h($base . '/index.php?r=sa_login') . '</code></p><p>Öffentliche Startseite mit Preisen und Registrierung:</p><p><code>' . inst_h($base . '/') . '</code></p>'
                . '<form method="post"><input type="hidden" name="do" value="delete"><button class="btn">superinstall.php löschen &amp; zum Superadmin-Login</button></form>'
                . '<div class="msg ' . ($probe['ok'] === false ? 'err' : ($probe['ok'] ? 'ok' : '')) . '">' . inst_h($probe['msg']) . '</div><h2>Nächste Schritte</h2><ol><li>Beim ersten Login die Zwei-Faktor-Anmeldung für den Superadmin einrichten (wird verlangt)</li><li>Superadmin → Einstellungen: Rechtstexte (Impressum, Datenschutz, AGB) eintragen</li><li>Superadmin → Einstellungen: PayPal und/oder Stripe (Kreditkarte) hinterlegen, Webhook-URLs dort eintragen</li><li>Superadmin → Tarife: Preise und Limits anpassen</li><li>Cronjob einrichten: <code>php ' . inst_h(__DIR__) . '/cron.php</code> (täglich)</li></ol></div>');
            exit;
        } catch (Throwable $e) {
            @unlink(APP_STORAGE . '/config.php'); @unlink(APP_STORAGE . '/central.sqlite');
            $err = 'Installation fehlgeschlagen: ' . $e->getMessage();
        }
    }
}

/** Schreibt die Konfiguration, bevor der Programmcode geladen wird. */
function save_config_early(array $cfg): void {
    $code = "<?php\n// automatisch erzeugt – nicht von Hand ändern\nreturn " . var_export($cfg, true) . ";\n";
    if (file_put_contents(APP_STORAGE . '/config.php', $code, LOCK_EX) === false) throw new RuntimeException('storage/config.php konnte nicht geschrieben werden.');
    @chmod(APP_STORAGE . '/config.php', 0600);
}

$f = fn(string $k) => inst_h($v[$k]);
inst_page('SaaS-Installation', '<h1>SaaS-Installation – ' . inst_h('HandwerkRechnung') . ' 1.0</h1><p class="muted">Mehrmandanten-Plattform mit Superadmin, Tarifen und Zahlungen (PayPal / Kreditkarte).</p>'
    . '<div class="card"><h2 style="margin-top:0">1. Voraussetzungen</h2>' . inst_req_html($req) . '</div>'
    . ($err ? '<div class="msg err">' . inst_h($err) . '</div>' : '')
    . '<form method="post" class="card"><input type="hidden" name="t" value="' . inst_token() . '">'
    . '<h2 style="margin-top:0">2. Produkt</h2>'
    . '<div class="row"><label>Produktname (erscheint auf der Startseite)<input name="brand" value="' . $f('brand') . '" required></label><label>Betreiber / Firma (für Rechnungs-Mails)<input name="operator" value="' . $f('operator') . '"></label></div>'
    . '<div class="row"><label>Basis-Domain für Mandanten (optional)<input name="base_domain" value="' . $f('base_domain') . '" placeholder="meine-software.de"></label><label>Testphase (Tage)<input name="trial_days" value="' . $f('trial_days') . '" inputmode="numeric"></label></div>'
    . '<p class="muted">Mit Basis-Domain und Wildcard-DNS (<code>*.meine-software.de</code>) melden sich Kunden unter <code>firma.meine-software.de</code> an. Ohne sie geben Kunden beim Login ihre Firmen-ID ein – das funktioniert auf jedem Webspace.</p>'
    . '<h2>3. Superadmin</h2>'
    . '<div class="row"><label>Benutzername<input name="sa_user" value="' . $f('sa_user') . '" required></label><label>Name (optional)<input name="sa_name" value="' . $f('sa_name') . '"></label></div>'
    . '<label>E-Mail<input type="email" name="sa_email" value="' . $f('sa_email') . '" required></label>'
    . '<div class="row"><label>Passwort (mind. 10 Zeichen)<input type="password" name="password" required minlength="10" autocomplete="new-password"></label><label>Wiederholen<input type="password" name="password2" required minlength="10" autocomplete="new-password"></label></div>'
    . (inst_is_https() ? '<label><input type="checkbox" name="force_https" value="1" checked> HTTPS dauerhaft erzwingen (empfohlen)</label>' : '<p class="msg err">Diese Seite wird ohne HTTPS aufgerufen. Für einen Betrieb mit Kundendaten und Zahlungen ist HTTPS zwingend – bitte SSL einrichten und die Installation über https:// durchführen.</p>')
    . '<p class="muted">Die zentrale Datenbank (Mandanten, Tarife, Zahlungen) ist SQLite; jeder Mandant erhält zusätzlich eine eigene, getrennte Datenbankdatei.</p>'
    . '<button class="btn"' . (inst_blocking($req) ? ' disabled' : '') . '>Plattform installieren</button></form>');
