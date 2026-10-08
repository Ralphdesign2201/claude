<?php
/**
 * HandwerkRechnung – Installation (Einzelinstallation, ohne Superadmin).
 * Aufruf im Browser: https://ihre-domain.de/install.php   –   nach der Installation bitte löschen.
 */
declare(strict_types=1);
define('APP_ROOT', __DIR__ . '/app');
define('APP_STORAGE', __DIR__ . '/storage');
if (!is_dir(APP_STORAGE)) @mkdir(APP_STORAGE, 0775, true);
require APP_ROOT . '/installer_lib.php';

if (is_file(APP_STORAGE . '/installed.lock')) {
    if (($_POST['do'] ?? '') === 'delete') { inst_selfdelete(__FILE__); header('Location: index.php'); exit; }
    inst_page('Bereits installiert', '<h1>Bereits installiert</h1><div class="card"><p>Dieses Programm ist schon eingerichtet. Aus Sicherheitsgründen sollte <code>install.php</code> jetzt gelöscht werden.</p><form method="post"><input type="hidden" name="do" value="delete"><button class="btn">install.php löschen</button> <a class="btn alt" href="index.php">Zum Programm</a></form></div>');
    exit;
}

require APP_ROOT . '/init.php';
start_session();
$req = inst_requirements(false);
$err = ''; $v = ['company' => '', 'username' => 'admin', 'display_name' => '', 'email' => '', 'driver' => 'sqlite', 'host' => 'localhost', 'port' => '3306', 'name' => '', 'user' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    inst_check();
    foreach ($v as $k => $_) $v[$k] = trim((string)($_POST[$k] ?? $v[$k]));
    $pw = (string)($_POST['password'] ?? ''); $dbpass = (string)($_POST['dbpass'] ?? '');
    if (inst_blocking($req)) $err = 'Bitte zuerst die erforderlichen Voraussetzungen erfüllen.';
    elseif ($v['company'] === '') $err = 'Bitte den Firmennamen angeben.';
    elseif (!inst_valid_user($v['username'])) $err = 'Der Benutzername darf 3–60 Zeichen lang sein (Buchstaben, Ziffern, . _ @ -).';
    elseif ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $err = 'Die E-Mail-Adresse ist ungültig.';
    elseif ($e = (function () use ($pw, $v) { require_once APP_ROOT . '/security.php'; return password_error($pw, $v['username']); })()) $err = $e;
    elseif ($pw !== (string)($_POST['password2'] ?? '')) $err = 'Die Passwörter stimmen nicht überein.';
    elseif ($v['driver'] === 'mysql' && ($v['name'] === '' || $v['user'] === '')) $err = 'Für MySQL bitte Datenbankname und Benutzer angeben.';
    else {
        try {
            $cfg = ['mode' => 'single', 'driver' => $v['driver'] === 'mysql' ? 'mysql' : 'sqlite', 'mysql' => ['host' => $v['host'], 'port' => (int)$v['port'], 'name' => $v['name'], 'user' => $v['user'], 'pass' => $dbpass], 'base_url' => inst_base_url(), 'force_https' => inst_is_https() && isset($_POST['force_https'])];
            if ($cfg['driver'] === 'mysql') { $pdo = db_connect($cfg); if (db_has_data($pdo)) throw new RuntimeException('Die MySQL-Datenbank enthält bereits Programmdaten. Bitte eine leere Datenbank verwenden.'); }
            $cfg['mysql']['pass'] = secret_encrypt($dbpass);
            save_config($cfg);
            $pdo = db();
            $pdo->beginTransaction();
            $roleId = seed_roles($pdo);
            $pdo->prepare('INSERT INTO users(username, display_name, email, password_hash, role_id, active) VALUES (?,?,?,?,?,1)')->execute([$v['username'], $v['display_name'], $v['email'], password_hash($pw, PASSWORD_DEFAULT), $roleId]);
            $pdo->commit();
            foreach (['company' => $v['company'], 'email' => $v['email'], 'payment_days' => '14', 'invoice_prefix' => 'RE-', 'cron_token' => bin2hex(random_bytes(16)), 'app_version' => APP_VERSION] as $k => $val) set_setting($k, $val);
            $probe = inst_probe(__DIR__);
            audit('installed', 'Installation (Einzelinstallation) abgeschlossen', $v['username']);
            file_put_contents(APP_STORAGE . '/installed.lock', date('c') . "\nmode=single\nversion=" . APP_VERSION . "\n");
            inst_page('Installation abgeschlossen', '<h1>Installation abgeschlossen ✓</h1><div class="card"><p>' . inst_h(APP_NAME) . ' Version ' . inst_h(APP_VERSION) . ' ist eingerichtet. Melden Sie sich mit dem Benutzer <b>' . inst_h($v['username']) . '</b> an.</p>'
                . '<p>Aus Sicherheitsgründen jetzt <code>install.php</code> löschen:</p><form method="post"><input type="hidden" name="do" value="delete"><button class="btn">install.php löschen &amp; zum Login</button></form>'
                . '<div class="msg ' . ($probe['ok'] === false ? 'err' : ($probe['ok'] ? 'ok' : '')) . '">' . inst_h($probe['msg']) . '</div><h2>Nächste Schritte</h2><ul><li>Verwaltung → Einstellungen: Firmendaten, IBAN, Logo eintragen</li><li>Verwaltung → Datenbank &amp; Backups: automatisches Backup und Cronjob einrichten</li></ul></div>');
            exit;
        } catch (Throwable $e) {
            @unlink(APP_STORAGE . '/config.php');
            $err = 'Installation fehlgeschlagen: ' . $e->getMessage();
        }
    }
}

$f = fn(string $k) => inst_h($v[$k]);
inst_page('Installation', '<h1>Installation – ' . inst_h('HandwerkRechnung') . ' 1.0</h1><p class="muted">Einzelinstallation in wenigen Schritten.</p>'
    . '<div class="card"><h2 style="margin-top:0">1. Voraussetzungen</h2>' . inst_req_html($req) . '</div>'
    . ($err ? '<div class="msg err">' . inst_h($err) . '</div>' : '')
    . '<form method="post" class="card"><input type="hidden" name="t" value="' . inst_token() . '">'
    . '<h2 style="margin-top:0">2. Firma &amp; Administrator</h2>'
    . '<label>Firmenname<input name="company" value="' . $f('company') . '" required></label>'
    . '<div class="row"><label>Benutzername<input name="username" value="' . $f('username') . '" required></label><label>Anzeigename (optional)<input name="display_name" value="' . $f('display_name') . '"></label></div>'
    . '<label>E-Mail (optional)<input type="email" name="email" value="' . $f('email') . '"></label>'
    . '<div class="row"><label>Passwort (mind. 8 Zeichen)<input type="password" name="password" required minlength="8" autocomplete="new-password"></label><label>Wiederholen<input type="password" name="password2" required minlength="8" autocomplete="new-password"></label></div>'
    . '<h2>3. Datenbank</h2>'
    . '<label><input type="radio" name="driver" value="sqlite"' . ($v['driver'] !== 'mysql' ? ' checked' : '') . '> <b>SQLite</b> – keine Einrichtung nötig (empfohlen für den Start)</label>'
    . '<label><input type="radio" name="driver" value="mysql"' . ($v['driver'] === 'mysql' ? ' checked' : '') . '> <b>MySQL / MariaDB</b> – leere Datenbank vom Hoster:</label>'
    . '<div class="row"><label>Server<input name="host" value="' . $f('host') . '"></label><label>Port<input name="port" value="' . $f('port') . '"></label>'
    . '<label>Datenbankname<input name="name" value="' . $f('name') . '"></label><label>Benutzer<input name="user" value="' . $f('user') . '" autocomplete="off"></label></div>'
    . '<label>Datenbank-Passwort<input type="password" name="dbpass" autocomplete="new-password"></label>'
    . '<p class="muted">Die Datenbank lässt sich später jederzeit in beide Richtungen umstellen.</p>'
    . (inst_is_https() ? '<label><input type="checkbox" name="force_https" value="1" checked> HTTPS dauerhaft erzwingen (empfohlen)</label>' : '<p class="msg">Diese Seite wird gerade ohne HTTPS aufgerufen. Für echte Kundendaten bitte unbedingt ein SSL-Zertifikat einrichten und die Installation über https:// durchführen.</p>')
    . '<button class="btn"' . (inst_blocking($req) ? ' disabled' : '') . '>Jetzt installieren</button></form>');
