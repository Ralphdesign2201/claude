<?php

declare(strict_types=1);

/**
 * Installationsassistent: prüft den Server, legt Ordner, Zugangsdaten und Datenbank an und erstellt den Administrator.
 * Aufruf im Browser: https://deine-domain.de/install.php   – danach diese Datei löschen (der Assistent bietet das an).
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Controllers\CatalogController;
use App\Services\InvoiceService;
use App\Support\Dates;
use App\Support\Db;
use App\Support\Env;
use App\Support\Migrator;

ini_set('display_errors', '0');
session_name('crm_install');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => installIsHttps()]);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
$nonce = base64_encode(random_bytes(12));
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-$nonce'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

/* ---------- Hilfsfunktionen ---------- */

function installIsHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function detectUrl(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir = rtrim(str_replace('\\', '/', dirname((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH))), '/');
    return (installIsHttps() ? 'https' : 'http') . '://' . $host . $dir;
}

function inSubfolder(): bool
{
    return rtrim(str_replace('\\', '/', dirname((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH))), '/') !== '';
}

/** Ist die Anwendung schon eingerichtet? (Sperre, damit niemand den Assistenten erneut benutzen kann.) */
function alreadyInstalled(): bool
{
    if (is_file(APP_ROOT . '/database/installed.lock')) {
        return true;
    }
    try {
        if (Db::isMysql()) {
            $has = Db::config()['name'] !== '';
        } else {
            $has = is_file(Db::path()) && filesize(Db::path()) > 0;
        }
        return $has && (int) Db::value('SELECT COUNT(*) FROM "User"') > 0;
    } catch (Throwable) {
        return false;
    }
}

/** @return list<array{label:string,status:string,detail:string}> status: ok | warn | fail */
function systemChecks(): array
{
    $c = [];
    $add = static function (string $label, string $status, string $detail = '') use (&$c): void {
        $c[] = ['label' => $label, 'status' => $status, 'detail' => $detail];
    };
    $add('PHP-Version ' . PHP_VERSION, PHP_VERSION_ID >= 80100 ? (PHP_VERSION_ID >= 80300 ? 'ok' : 'warn') : 'fail', PHP_VERSION_ID >= 80100 ? (PHP_VERSION_ID >= 80300 ? '' : 'Getestet ist PHP 8.3; ab 8.1 sollte es laufen. Im Hosting-Panel lässt sich die Version meist umstellen.') : 'Benötigt wird PHP 8.1 oder neuer (im Hosting-Panel umstellbar).');
    $add('Erweiterung PDO', extension_loaded('pdo') ? 'ok' : 'fail');
    $sqlite = extension_loaded('pdo_sqlite');
    $mysql = extension_loaded('pdo_mysql');
    $add('Datenbank-Treiber', $sqlite || $mysql ? 'ok' : 'fail', ($sqlite ? 'SQLite ' : '') . ($mysql ? 'MySQL' : '') ?: 'Weder pdo_sqlite noch pdo_mysql ist aktiv.');
    $add('Erweiterung mbstring', extension_loaded('mbstring') ? 'ok' : 'fail');
    $add('Erweiterung json', extension_loaded('json') ? 'ok' : 'fail');
    $add('Erweiterung sodium (Lizenzen)', extension_loaded('sodium') ? 'ok' : 'warn', extension_loaded('sodium') ? '' : 'Ohne sodium funktionieren die Domain-Lizenzen nicht. Im Hosting-Panel unter „PHP-Erweiterungen“ aktivieren.');
    $add('Erweiterung zip (Backups)', extension_loaded('zip') ? 'ok' : 'warn', extension_loaded('zip') ? '' : 'Ohne zip gibt es keine Datensicherung. Bitte aktivieren.');
    $add('Erweiterung intl (Umlaut-Domains)', extension_loaded('intl') ? 'ok' : 'warn', extension_loaded('intl') ? '' : 'Optional; nur für Lizenzen mit Umlaut-Domains.');
    $add('Erweiterung openssl (verschlüsselter Mailversand)', extension_loaded('openssl') ? 'ok' : 'warn', extension_loaded('openssl') ? '' : 'Ohne openssl ist nur unverschlüsselter SMTP möglich.');
    foreach (['database', 'uploads', 'backups'] as $dir) {
        $path = APP_ROOT . '/' . $dir;
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }
        $add("Ordner $dir/ beschreibbar", is_dir($path) && is_writable($path) ? 'ok' : 'fail', is_writable($path) ? '' : 'Bitte die Schreibrechte auf 755/775 setzen (Dateimanager → Rechte).');
    }
    $envOk = is_file(APP_ROOT . '/.env') ? is_writable(APP_ROOT . '/.env') : is_writable(APP_ROOT);
    $add('Konfigurationsdatei .env anlegbar', $envOk ? 'ok' : 'warn', $envOk ? '' : 'Das Projektverzeichnis ist nicht beschreibbar. Die Geheimschlüssel werden dann in database/settings.json abgelegt – das funktioniert ebenso.');
    $add('Installation in der Hauptdomain', inSubfolder() ? 'warn' : 'ok', inSubfolder() ? 'Die Anwendung erwartet, direkt unter einer Domain oder Subdomain zu laufen (nicht in einem Unterordner wie /crm/). Lege am besten eine Subdomain an, z. B. crm.deine-domain.de.' : '');
    $add('HTTPS', installIsHttps() ? 'ok' : 'warn', installIsHttps() ? '' : 'Du rufst diese Seite unverschlüsselt auf. Passwörter wandern dann im Klartext durchs Netz. Aktiviere im Hosting-Panel das kostenlose SSL-Zertifikat und rufe die Seite mit https:// auf.');
    $exposed = exposureCheck();
    $add('Datenordner von außen geschützt', $exposed === false ? 'ok' : ($exposed === true ? 'fail' : 'warn'), $exposed === true ? 'ACHTUNG: Dateien aus database/ sind über das Internet abrufbar! Die Domain muss auf den Ordner public/ zeigen (oder die mitgelieferte .htaccess muss greifen). Bitte erst das beheben.' : ($exposed === null ? 'Konnte nicht automatisch geprüft werden. Prüfe selbst, dass https://deine-domain.de/database/ nicht erreichbar ist.' : ''));
    return $c;
}

/** @return bool|null true = erreichbar (schlecht), false = geschützt, null = nicht prüfbar */
function exposureCheck(): ?bool
{
    $dir = APP_ROOT . '/database';
    if (!is_writable($dir) || PHP_SAPI === 'cli-server') { // der Entwicklungsserver kann sich nicht selbst abfragen
        return null;
    }
    $name = '.probe-' . bin2hex(random_bytes(6)) . '.txt';
    $marker = 'crm-probe-' . bin2hex(random_bytes(6));
    file_put_contents($dir . '/' . $name, $marker);
    try {
        $ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true, 'follow_location' => 0], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $body = @file_get_contents(detectUrl() . '/database/' . $name, false, $ctx);
        if ($body === false) {
            return null;
        }
        return str_contains($body, $marker);
    } finally {
        @unlink($dir . '/' . $name);
    }
}

/** @param array<string,mixed> $in @return array<string,string> Feldfehler */
function validateInput(array $in, bool $needAdmin = true): array
{
    $e = [];
    if (trim((string) ($in['company'] ?? '')) === '') {
        $e['company'] = 'Bitte den Firmennamen eingeben.';
    }
    if ($needAdmin) {
        if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', (string) ($in['username'] ?? ''))) {
            $e['username'] = 'Benutzername: 3–40 Zeichen (Buchstaben, Ziffern, . _ -).';
        }
        if (!filter_var((string) ($in['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            $e['email'] = 'Bitte eine gültige E-Mail-Adresse eingeben (für Benachrichtigungen und „Passwort vergessen“).';
        }
        $pw = (string) ($in['password'] ?? '');
        if (strlen($pw) < 10) {
            $e['password'] = 'Das Passwort braucht mindestens 10 Zeichen.';
        } elseif (strlen($pw) > 72) {
            $e['password'] = 'Das Passwort darf höchstens 72 Zeichen haben.';
        } elseif ($pw !== (string) ($in['password2'] ?? '')) {
            $e['password2'] = 'Die beiden Passwörter sind nicht gleich.';
        }
        if (!preg_match('#^https?://[^\s/]+(/[^\s]*)?$#i', (string) ($in['url'] ?? ''))) {
            $e['url'] = 'Bitte die Adresse mit https:// eingeben.';
        }
        if (!empty($in['demo'])) {
            if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', (string) ($in['demo_user'] ?? '')) || strcasecmp((string) $in['demo_user'], (string) $in['username']) === 0) {
                $e['demo_user'] = 'Demo-Benutzername: 3–40 Zeichen und nicht wie der Admin.';
            }
            if (strlen((string) ($in['demo_pass'] ?? '')) < 10) {
                $e['demo_pass'] = 'Das Demo-Passwort braucht mindestens 10 Zeichen.';
            }
        }
    }
    if (($in['db'] ?? 'sqlite') === 'mysql') {
        foreach (['my_host' => 'Server', 'my_name' => 'Datenbankname', 'my_user' => 'Benutzer'] as $k => $label) {
            if (trim((string) ($in[$k] ?? '')) === '') {
                $e[$k] = "Bitte $label angeben.";
            }
        }
    } elseif (!extension_loaded('pdo_sqlite')) {
        $e['db'] = 'SQLite ist auf diesem Server nicht verfügbar – bitte MySQL wählen.';
    }
    if (trim((string) ($in['smtp_host'] ?? '')) !== '' && !filter_var((string) ($in['smtp_from'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        $e['smtp_from'] = 'Für den Mailversand bitte eine Absenderadresse angeben.';
    }
    return $e;
}

/** @param array<string,mixed> $in */
function mysqlConfig(array $in): array
{
    return ['driver' => 'mysql', 'host' => trim((string) $in['my_host']), 'port' => (int) ($in['my_port'] ?: 3306), 'name' => trim((string) $in['my_name']), 'user' => trim((string) $in['my_user']), 'password' => (string) ($in['my_pass'] ?? '')];
}

/** Verbindet zu MySQL und liefert eine verständliche Fehlermeldung statt einer technischen. */
function connectMysql(array $cfg): PDO
{
    try {
        return Db::connect($cfg);
    } catch (PDOException $e) {
        $code = (int) ($e->errorInfo[1] ?? $e->getCode());
        throw new RuntimeException(match ($code) {
            1045 => 'Benutzer oder Passwort der Datenbank stimmen nicht.',
            1049 => 'Die Datenbank „' . $cfg['name'] . '“ gibt es nicht. Bitte zuerst im Hosting-Panel unter „Datenbanken“ anlegen.',
            1044 => 'Der Datenbank-Benutzer hat keinen Zugriff auf diese Datenbank.',
            2002, 2006 => 'Der Datenbank-Server ist unter dieser Adresse nicht erreichbar (bei Hostinger meist „localhost“).',
            default => 'Verbindung fehlgeschlagen: ' . mb_substr($e->getMessage(), 0, 200),
        });
    } catch (RuntimeException $e) {
        throw $e;
    }
}

function writeEnv(string $url): string
{
    $path = APP_ROOT . '/.env';
    $existing = Env::get('JWT_SECRET', '');
    if (is_file($path) && strlen((string) $existing) >= 16 && !str_starts_with((string) $existing, 'change-me')) {
        return 'Vorhandene .env mit gültigem Geheimschlüssel wurde übernommen.';
    }
    $jwt = bin2hex(random_bytes(32));
    $cron = bin2hex(random_bytes(20));
    $content = "# Von install.php erzeugt – weitere Einstellungen findest du in der Anwendung unter „Einstellungen“.\n"
        . "JWT_SECRET=\"$jwt\"\nCRON_TOKEN=\"$cron\"\nAPP_URL=\"$url\"\nALLOW_REGISTRATION=false\nCORS_ORIGIN=\"$url\"\n";
    if (is_file($path)) {
        @copy($path, $path . '.bak-' . gmdate('Ymd-His'));
    }
    if (@file_put_contents($path, $content) !== false) {
        @chmod($path, 0600);
        putenv('JWT_SECRET=' . $jwt);
        return '.env angelegt (Geheimschlüssel zufällig erzeugt).';
    }
    // Ersatz: Schlüssel in der Einstellungsdatei ablegen (wird genauso gelesen)
    Env::saveSettings(['JWT_SECRET' => $jwt, 'CRON_TOKEN' => $cron, 'APP_URL' => $url, 'ALLOW_REGISTRATION' => 'false', 'CORS_ORIGIN' => $url]);
    return 'Das Projektverzeichnis ist nicht beschreibbar – Geheimschlüssel liegen in database/settings.json.';
}

/**
 * Führt die Installation durch.
 *
 * @param array<string,mixed> $in
 * @return array{log:list<string>,cronToken:string,adminUser:string,demoUser:?string}
 */
function runInstall(array $in): array
{
    $log = [];
    foreach (['database', 'uploads', 'backups'] as $dir) {
        $path = APP_ROOT . '/' . $dir;
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException("Ordner $dir/ konnte nicht angelegt werden.");
        }
        $deny = $path . '/.htaccess';
        if (!is_file($deny)) {
            @file_put_contents($deny, "Require all denied\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
        }
    }
    $log[] = 'Ordner database/, uploads/ und backups/ bereit (mit Zugriffssperre).';

    $url = rtrim((string) $in['url'], '/');
    $log[] = writeEnv($url);
    Env::load(APP_ROOT . '/.env');

    // Datenbank festlegen
    if ($in['db'] === 'mysql') {
        $cfg = mysqlConfig($in);
        $pdo = connectMysql($cfg);
        if (DatabaseHasData($pdo, 'mysql')) {
            throw new RuntimeException('Die MySQL-Datenbank enthält bereits Daten dieser Anwendung. Bitte eine leere Datenbank anlegen.');
        }
        Env::saveSettings(['DB_DRIVER' => 'mysql', 'DB_HOST' => $cfg['host'], 'DB_PORT' => (string) $cfg['port'], 'DB_NAME' => $cfg['name'], 'DB_USER' => $cfg['user'], 'DB_PASSWORD' => $cfg['password']]);
        $log[] = 'Datenbank: MySQL/MariaDB „' . $cfg['name'] . '“.';
    } else {
        Env::saveSettings(['DB_DRIVER' => 'sqlite']);
        $log[] = 'Datenbank: SQLite (Datei database/app.db).';
    }
    Db::reset();
    if (Db::driver() !== $in['db']) {
        throw new RuntimeException('Die Datenbankeinstellung wird von der Server-Umgebung (DB_DRIVER) überschrieben. Bitte diese Variable entfernen oder anpassen.');
    }

    $applied = Migrator::run(Db::pdo(), Db::driver());
    $log[] = count($applied) . ' Datenbank-Migration(en) angewendet.';
    if ((int) Db::value('SELECT COUNT(*) FROM "User"') > 0) {
        throw new RuntimeException('Die Datenbank enthält bereits Benutzer – die Installation wurde sicherheitshalber abgebrochen.');
    }

    $settings = ['COMPANY_NAME' => trim((string) $in['company']), 'COMPANY_EMAIL' => (string) $in['email']];
    if (trim((string) ($in['smtp_host'] ?? '')) !== '') {
        $settings += [
            'SMTP_HOST' => trim((string) $in['smtp_host']), 'SMTP_PORT' => (string) ((int) ($in['smtp_port'] ?: 587)),
            'SMTP_ENCRYPTION' => in_array($in['smtp_enc'] ?? 'tls', ['tls', 'ssl', 'none'], true) ? $in['smtp_enc'] : 'tls',
            'SMTP_USER' => trim((string) ($in['smtp_user'] ?? '')), 'MAIL_FROM' => trim((string) $in['smtp_from']),
        ];
        if ((string) ($in['smtp_pass'] ?? '') !== '') {
            $settings['SMTP_PASSWORD'] = (string) $in['smtp_pass'];
        }
    }

    $demoUser = null;
    Db::transaction(static function () use ($in, &$log, &$demoUser): void {
        $userId = Db::insert('User', [
            'name' => trim((string) $in['owner']) ?: (string) $in['username'], 'email' => strtolower(trim((string) $in['email'])),
            'username' => strtolower((string) $in['username']), 'passwordHash' => password_hash((string) $in['password'], PASSWORD_BCRYPT), 'role' => 'ADMIN',
        ]);
        $log[] = 'Administrator „' . $in['username'] . '“ angelegt.';

        if (!empty($in['demo'])) {
            $clientId = Db::insert('Client', [
                'name' => 'Demo Kunde', 'company' => 'Demo GmbH', 'email' => 'demo@demo.invalid', 'status' => 'ACTIVE',
                'source' => 'Installation (Demo)', 'notesText' => 'Demo-Konto zum Ausprobieren des Kundenportals – bitte vor dem Echtbetrieb löschen.', 'ownerId' => $userId,
            ]);
            Db::insert('PortalAccount', [
                'clientId' => $clientId, 'email' => strtolower((string) $in['demo_user']) . '@demo.invalid', 'username' => strtolower((string) $in['demo_user']),
                'name' => 'Demo Kunde', 'company' => 'Demo GmbH', 'passwordHash' => password_hash((string) $in['demo_pass'], PASSWORD_BCRYPT),
                'verifiedAt' => Dates::now(), 'termsAcceptedAt' => Dates::now(),
            ]);
            $demoUser = strtolower((string) $in['demo_user']);
            $log[] = 'Demo-Kunde mit Portal-Zugang „' . $demoUser . '“ angelegt.';
            if (!empty($in['demo_invoice'])) {
                InvoiceService::create(
                    ['clientId' => $clientId, 'status' => 'SENT', 'dueDate' => (new DateTimeImmutable('+14 days'))->format(Dates::FORMAT), 'taxRate' => 19, 'notes' => 'Beispielrechnung zum Ausprobieren'],
                    [['description' => 'Beispiel: Webseite Startpaket', 'quantity' => 1, 'unitPrice' => 490], ['description' => 'Beispiel: Hosting (12 Monate)', 'quantity' => 12, 'unitPrice' => 5.9]],
                );
                $log[] = 'Beispielrechnung für den Demo-Kunden erstellt.';
            }
        }

        if (!empty($in['support_seed'])) {
            foreach ([
                ['Danke für die Nachricht', "Guten Tag {kunde},\n\nvielen Dank für Ihre Nachricht zu {ticket}. Wir kümmern uns darum und melden uns schnellstmöglich.\n\nFreundliche Grüße\n{mitarbeiter}"],
                ['Rückfrage', "Guten Tag {kunde},\n\num Ihnen weiterzuhelfen, benötigen wir noch eine Angabe: \n\nFreundliche Grüße\n{mitarbeiter}"],
                ['Erledigt', "Guten Tag {kunde},\n\nwir haben Ihr Anliegen ({betreff}) erledigt. Falls noch etwas offen ist, antworten Sie einfach hier.\n\nFreundliche Grüße\n{mitarbeiter}"],
            ] as [$title, $body]) {
                Db::insert('CannedResponse', ['title' => $title, 'body' => $body]);
            }
            foreach ([
                ['Wie sehe ich meine Rechnungen?', "Im Kundenportal finden Sie unter „Rechnungen“ alle Rechnungen mit den Zahlungsdaten und können sie als PDF herunterladen.", 'Rechnung'],
                ['Wie ändere ich mein Passwort?', "Im Kundenportal oben rechts auf „Passwort ändern“ klicken. Haben Sie es vergessen, nutzen Sie auf der Anmeldeseite „Passwort vergessen“.", 'Konto'],
                ['Wie erreiche ich den Support?', "Im Kundenportal im Reiter „Support“ auf „Neue Anfrage“ klicken. Wir antworten so schnell wie möglich – Sie erhalten jede Antwort auch per E-Mail.", 'Allgemein'],
            ] as $i => [$title, $body, $cat]) {
                Db::insert('FaqArticle', ['title' => $title, 'body' => $body, 'category' => $cat, 'published' => 1, 'sortOrder' => $i]);
            }
            $log[] = 'Textbausteine und Hilfe-Artikel für den Support angelegt (anpassbar).';
        }
    });

    Env::saveSettings($settings);
    $log[] = 'Firmendaten' . (isset($settings['SMTP_HOST']) ? ' und E-Mail-Versand' : '') . ' gespeichert.';
    if (!empty($in['catalog'])) {
        $r = CatalogController::createExamples();
        $log[] = "Beispielkatalog angelegt ({$r['products']} Produkte, zunächst inaktiv).";
    }
    @file_put_contents(APP_ROOT . '/database/installed.lock', "Installiert am " . gmdate('Y-m-d H:i:s') . " UTC\n");
    $log[] = 'Installation abgeschlossen und gesperrt (database/installed.lock).';

    return ['log' => $log, 'cronToken' => (string) Env::get('CRON_TOKEN', ''), 'adminUser' => strtolower((string) $in['username']), 'demoUser' => $demoUser];
}

function DatabaseHasData(PDO $pdo, string $driver): bool
{
    return \App\Support\DatabaseTransfer::hasTables($pdo, $driver) && \App\Support\DatabaseTransfer::hasData($pdo);
}

/* ---------- Seite ---------- */

$errors = [];
$notice = null;
$result = null;
$fail = null;
$in = [
    'company' => 'Ralph Design', 'owner' => 'Ralph', 'username' => 'Ralphdesign', 'email' => '', 'password' => '', 'password2' => '', 'url' => detectUrl(),
    'db' => extension_loaded('pdo_sqlite') ? 'sqlite' : 'mysql', 'my_host' => 'localhost', 'my_port' => '3306', 'my_name' => '', 'my_user' => '', 'my_pass' => '',
    'demo' => '1', 'demo_user' => 'DEMO123456', 'demo_pass' => 'login123456', 'demo_invoice' => '1', 'catalog' => '1', 'support_seed' => '1',
    'smtp_host' => '', 'smtp_port' => '587', 'smtp_enc' => 'tls', 'smtp_user' => '', 'smtp_pass' => '', 'smtp_from' => '',
];
$installed = alreadyInstalled();
$checks = systemChecks();
$blocked = $installed || count(array_filter($checks, static fn ($c) => $c['status'] === 'fail')) > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $fail = 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden und noch einmal versuchen.';
    } elseif ($action === 'delete' && $installed) {
        $deleted = @unlink(__FILE__);
        $notice = $deleted ? 'install.php wurde gelöscht. Du kannst dich jetzt anmelden.' : 'Die Datei konnte nicht automatisch gelöscht werden. Bitte lösche public/install.php im Dateimanager.';
    } elseif (!$installed && in_array($action, ['test', 'install'], true)) {
        foreach (array_keys($in) as $k) {
            if (isset($_POST[$k]) && is_string($_POST[$k])) {
                $in[$k] = trim($_POST[$k]) === $_POST[$k] || str_contains($k, 'pass') ? $_POST[$k] : trim($_POST[$k]);
            }
        }
        foreach (['demo', 'demo_invoice', 'catalog', 'support_seed'] as $k) {
            $in[$k] = isset($_POST[$k]) ? '1' : '';
        }
        if ($action === 'test') {
            try {
                if ($in['db'] !== 'mysql') {
                    throw new RuntimeException('Für den Verbindungstest bitte „MySQL“ wählen.');
                }
                $pdo = connectMysql(mysqlConfig($in + ['my_port' => (int) $in['my_port']]));
                $ver = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
                $notice = 'Verbindung steht (Version ' . $ver . ')' . (DatabaseHasData($pdo, 'mysql') ? ' – ACHTUNG: In dieser Datenbank liegen schon Daten dieser Anwendung.' : '. Die Datenbank ist bereit.');
            } catch (Throwable $e) {
                $fail = $e->getMessage();
            }
        } elseif (!$blocked) {
            $errors = validateInput($in);
            if ($errors === []) {
                try {
                    $result = runInstall($in);
                    $installed = true;
                    $_SESSION['csrf'] = bin2hex(random_bytes(16));
                } catch (Throwable $e) {
                    $fail = 'Die Installation ist fehlgeschlagen: ' . $e->getMessage() . ' – nichts wurde freigeschaltet, du kannst es nach der Korrektur erneut versuchen.';
                    error_log('install.php: ' . $e);
                }
            } else {
                $fail = 'Bitte die markierten Felder prüfen.';
            }
        }
    }
}

$csrf = $_SESSION['csrf'];
$field = static function (string $name, string $label, string $type = 'text', string $hint = '', string $extra = '') use (&$in, &$errors): string {
    $val = in_array($type, ['password'], true) ? '' : (string) ($in[$name] ?? '');
    return '<label' . (isset($errors[$name]) ? ' class="bad"' : '') . '>' . h($label) . '<input name="' . h($name) . '" type="' . $type . '" value="' . h($val) . '" ' . $extra . '>'
        . (isset($errors[$name]) ? '<span class="err">' . h($errors[$name]) . '</span>' : ($hint !== '' ? '<span class="hint">' . h($hint) . '</span>' : '')) . '</label>';
};
$check = static fn (string $name, string $label, string $hint = ''): string => '<label class="cb"><input type="checkbox" name="' . $name . '" value="1"' . (($in[$name] ?? '') !== '' ? ' checked' : '') . '> <span>' . h($label) . ($hint !== '' ? '<small>' . h($hint) . '</small>' : '') . '</span></label>';
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Installation – Webdesigner CRM</title>
<style>
:root { --bg: #f2f4f9; --card: #fff; --fg: #131a29; --muted: #5b6577; --line: #dde2ec; --accent: #2748e8; --good: #146c43; --warn: #8a5a00; --bad: #b3261e; }
@media (prefers-color-scheme: dark) { :root { --bg: #0f1420; --card: #181f2f; --fg: #e8ecf5; --muted: #9aa5ba; --line: #2b3550; --accent: #7c93ff; --good: #5fd49b; --warn: #f0b84a; --bad: #ff8a80; } }
* { box-sizing: border-box; }
body { margin: 0; font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--bg); color: var(--fg); }
main { max-width: 760px; margin: 0 auto; padding: 24px 16px 60px; display: flex; flex-direction: column; gap: 16px; }
h1 { margin: 8px 0 0; font-size: 26px; } h2 { margin: 0 0 8px; font-size: 17px; }
.card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 18px; }
.sub, .hint, small { color: var(--muted); font-size: 13px; font-weight: 400; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; } .full { grid-column: 1 / -1; }
label { display: flex; flex-direction: column; gap: 4px; font-weight: 600; font-size: 13px; color: var(--muted); }
label input, label select { font: inherit; font-weight: 400; color: var(--fg); background: var(--bg); border: 1px solid var(--line); border-radius: 9px; padding: 9px 11px; width: 100%; }
label.bad input { border-color: var(--bad); } .err { color: var(--bad); font-weight: 400; }
label.cb { flex-direction: row; gap: 9px; align-items: flex-start; font-weight: 500; color: var(--fg); } label.cb input { width: auto; margin-top: 4px; } label.cb small { display: block; }
button { font: inherit; font-weight: 600; border-radius: 10px; border: 1px solid var(--line); background: var(--card); color: var(--fg); padding: 10px 18px; cursor: pointer; }
button.primary { background: var(--accent); color: #fff; border-color: var(--accent); } button:disabled { opacity: .5; cursor: not-allowed; }
ul.checks { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
ul.checks li { display: flex; gap: 10px; align-items: baseline; } .dot { font-weight: 700; width: 1.2em; flex: none; }
.ok .dot { color: var(--good); } .warn .dot { color: var(--warn); } .fail .dot { color: var(--bad); }
.note { border-radius: 10px; padding: 11px 14px; } .note.ok { background: color-mix(in srgb, var(--good) 15%, transparent); } .note.bad { background: color-mix(in srgb, var(--bad) 15%, transparent); color: var(--bad); }
code, .mono { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 13px; word-break: break-all; }
details summary { cursor: pointer; font-weight: 600; } details[open] summary { margin-bottom: 10px; }
.row { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
@media (max-width: 640px) { .grid { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<main>
<h1>Webdesigner CRM einrichten</h1>
<p class="sub" style="margin:0">Der Assistent prüft den Server, legt Datenbank und Zugänge an und erstellt dein Admin-Konto. Das dauert etwa eine Minute.</p>

<?php if ($fail !== null) : ?><div class="note bad" role="alert"><?= h($fail) ?></div><?php endif; ?>
<?php if ($notice !== null) : ?><div class="note ok" role="status"><?= h($notice) ?></div><?php endif; ?>

<?php if ($result !== null) : ?>
    <div class="card"><h2>✓ Fertig – dein CRM ist eingerichtet</h2>
    <ul class="checks"><?php foreach ($result['log'] as $line) : ?><li class="ok"><span class="dot">✓</span><span><?= h($line) ?></span></li><?php endforeach; ?></ul></div>
    <div class="card"><h2>So geht es weiter</h2>
    <ol style="margin:0;padding-left:20px;display:flex;flex-direction:column;gap:8px">
        <li><b>Installer löschen:</b> Klicke unten auf „install.php löschen“ (oder lösche <span class="mono">public/install.php</span> im Dateimanager).</li>
        <li><b>Anmelden:</b> <a href="<?= h(rtrim((string) $in['url'], '/')) ?>/">Admin-Bereich</a> mit Benutzername <b><?= h($result['adminUser']) ?></b> und deinem Passwort.</li>
        <?php if ($result['demoUser'] !== null) : ?><li><b>Kundenportal ausprobieren:</b> <a href="<?= h(rtrim((string) $in['url'], '/')) ?>/portal">/portal</a> mit Benutzername <b><?= h($result['demoUser']) ?></b> und dem Demo-Passwort. Den Demo-Kunden später unter „Kunden“ löschen.</li><?php endif; ?>
        <li><b>Täglichen Cron-Job anlegen</b> (Hosting-Panel → Erweitert → Cron-Jobs, einmal täglich, z. B. 6 Uhr): <br><code>php <?= h(APP_ROOT) ?>/bin/cron.php</code><br><span class="sub">Er erstellt fällige Abo-Rechnungen, schließt alte Tickets und sichert deine Daten.</span></li>
        <li><b>E-Mail einrichten:</b> unter <i>Einstellungen → E-Mail (SMTP)</i> (Zugangsdaten bekommst du vom Mail-Anbieter). Ohne E-Mail funktionieren Registrierung und Rechnungsversand nicht.</li>
        <li><b>Firmendaten ergänzen:</b> Anschrift, IBAN, Steuernummer unter <i>Einstellungen → Firmendaten</i>, damit die Rechnungen vollständig sind.</li>
        <li><b>Backups:</b> unter <i>Team</i> ein Backup-Passwort setzen (Einstellungen → Datensicherung) und die Sicherungen regelmäßig herunterladen.</li>
    </ol></div>
    <form method="post" class="card row"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete"><button class="primary" type="submit">install.php löschen</button><span class="sub">Empfohlen. Der Assistent ist ohnehin gesperrt, solange <span class="mono">database/installed.lock</span> existiert.</span></form>
<?php elseif ($installed) : ?>
    <div class="card"><h2>Bereits installiert</h2><p style="margin:0">Dieses CRM ist schon eingerichtet. Aus Sicherheitsgründen lässt sich der Assistent nicht noch einmal ausführen.</p>
    <p class="sub">Neu installieren? Dann sichere deine Daten, lösche <span class="mono">database/installed.lock</span> und die Datenbank selbst.</p>
    <form method="post" class="row"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete"><a href="/"><button type="button" class="primary">Zur Anmeldung</button></a> <button type="submit">install.php löschen</button></form></div>
<?php else : ?>
    <div class="card"><h2>1. Systemprüfung</h2>
    <ul class="checks"><?php foreach ($checks as $c) : ?><li class="<?= $c['status'] ?>"><span class="dot"><?= $c['status'] === 'ok' ? '✓' : ($c['status'] === 'warn' ? '!' : '✗') ?></span><span><?= h($c['label']) ?><?php if ($c['detail'] !== '') : ?><br><small><?= h($c['detail']) ?></small><?php endif; ?></span></li><?php endforeach; ?></ul>
    <?php if ($blocked) : ?><p class="note bad" style="margin-bottom:0">Bitte zuerst die roten Punkte beheben und die Seite neu laden.</p><?php endif; ?></div>

    <form method="post" autocomplete="off" novalidate class="card" style="display:flex;flex-direction:column;gap:18px">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <div><h2>2. Dein Unternehmen und Admin-Zugang</h2><div class="grid">
            <?= $field('company', 'Firmenname', 'text', 'Erscheint auf Rechnungen und in E-Mails.', 'maxlength="120"') ?>
            <?= $field('owner', 'Dein Name', 'text', '', 'maxlength="100"') ?>
            <?= $field('username', 'Admin-Benutzername', 'text', 'Damit meldest du dich an.', 'autocapitalize="off" spellcheck="false"') ?>
            <?= $field('email', 'Deine E-Mail-Adresse', 'email', 'Für Benachrichtigungen und „Passwort vergessen“.') ?>
            <?= $field('password', 'Admin-Passwort', 'password', 'Mindestens 10 Zeichen.', 'autocomplete="new-password"') ?>
            <?= $field('password2', 'Passwort wiederholen', 'password', '', 'autocomplete="new-password"') ?>
            <div class="full"><?= $field('url', 'Adresse deines CRM', 'text', 'Wird für Links in E-Mails verwendet – automatisch erkannt.') ?></div>
        </div></div>

        <div><h2>3. Datenbank</h2>
            <label style="margin-bottom:10px">Art<select name="db" id="db"><option value="sqlite"<?= $in['db'] === 'sqlite' ? ' selected' : '' ?><?= extension_loaded('pdo_sqlite') ? '' : ' disabled' ?>>SQLite – eine Datei, keine Einrichtung (empfohlen für den Start)</option><option value="mysql"<?= $in['db'] === 'mysql' ? ' selected' : '' ?><?= extension_loaded('pdo_mysql') ? '' : ' disabled' ?>>MySQL/MariaDB – die Datenbank aus dem Hosting-Panel</option></select><?php if (isset($errors['db'])) : ?><span class="err"><?= h($errors['db']) ?></span><?php endif; ?></label>
            <div id="mysql" class="grid" style="<?= $in['db'] === 'mysql' ? '' : 'display:none' ?>">
                <?= $field('my_host', 'Server', 'text', 'Bei Hostinger meist „localhost“.') ?>
                <?= $field('my_port', 'Port', 'number') ?>
                <?= $field('my_name', 'Datenbankname', 'text', 'Im Hosting-Panel unter „Datenbanken“ vorher anlegen.') ?>
                <?= $field('my_user', 'Datenbank-Benutzer') ?>
                <div class="full"><?= $field('my_pass', 'Datenbank-Passwort', 'password', '', 'autocomplete="new-password"') ?></div>
                <div class="full row"><button type="submit" name="action" value="test" formnovalidate>Verbindung testen</button><span class="sub">Zum Wechseln zwischen SQLite und MySQL genügt später ein Klick unter Einstellungen → Datenbank.</span></div>
            </div>
        </div>

        <div><h2>4. Zum Ausprobieren (alles später änderbar oder löschbar)</h2><div style="display:flex;flex-direction:column;gap:10px">
            <?= $check('demo', 'Demo-Kunden mit Portal-Zugang anlegen', 'Zum Testen des Kundenportals – vor dem Echtbetrieb löschen.') ?>
            <div class="grid" style="margin-left:26px"><?= $field('demo_user', 'Demo-Benutzername', 'text', '', 'autocapitalize="off"') ?><?= $field('demo_pass', 'Demo-Passwort') ?></div>
            <div style="margin-left:26px"><?= $check('demo_invoice', 'Beispielrechnung für den Demo-Kunden erstellen') ?></div>
            <?= $check('catalog', 'Beispielkatalog anlegen', 'Webseite, Skripte, Hosting, Domain, Wartung, SEO … – zunächst alles inaktiv, damit nichts versehentlich bestellbar ist.') ?>
            <?= $check('support_seed', 'Support vorbereiten', 'Drei Textbausteine und drei Hilfe-Artikel als Startpunkt.') ?>
        </div></div>

        <details<?= $in['smtp_host'] !== '' || isset($errors['smtp_from']) ? ' open' : '' ?>><summary>5. E-Mail-Versand jetzt einrichten (optional – geht auch später)</summary><div class="grid">
            <?= $field('smtp_host', 'SMTP-Server', 'text', 'z. B. smtp.hostinger.com') ?>
            <?= $field('smtp_port', 'Port', 'number') ?>
            <label>Verschlüsselung<select name="smtp_enc"><option value="tls"<?= $in['smtp_enc'] === 'tls' ? ' selected' : '' ?>>STARTTLS (Port 587)</option><option value="ssl"<?= $in['smtp_enc'] === 'ssl' ? ' selected' : '' ?>>SSL/TLS (Port 465)</option><option value="none"<?= $in['smtp_enc'] === 'none' ? ' selected' : '' ?>>keine</option></select></label>
            <?= $field('smtp_from', 'Absenderadresse', 'email', 'Muss zum Mail-Konto passen.') ?>
            <?= $field('smtp_user', 'Benutzername', 'text', 'Meist die E-Mail-Adresse.') ?>
            <?= $field('smtp_pass', 'Passwort', 'password', '', 'autocomplete="new-password"') ?>
        </div></details>

        <div class="row"><button class="primary" type="submit" name="action" value="install"<?= $blocked ? ' disabled' : '' ?>>Jetzt installieren</button><span class="sub">Es wird nichts außerhalb dieses Projektordners verändert.</span></div>
    </form>
<?php endif; ?>
</main>
<script nonce="<?= h($nonce) ?>">
document.getElementById('db') && document.getElementById('db').addEventListener('change', function (e) {
  document.getElementById('mysql').style.display = e.target.value === 'mysql' ? '' : 'none';
});
</script>
</body>
</html>
