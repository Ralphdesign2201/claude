<?php
declare(strict_types=1);

const SCHEMA_VERSION = '5';

/**
 * Statement, das nach fetch()/fetchColumn() den Cursor schließt. Verhindert, dass ein halb gelesenes SELECT unter SQLite
 * eine Lese-Transaktion offen hält und spätere Schreibzugriffe mit „database is locked“ scheitern.
 */
class RgStatement extends PDOStatement {
    protected function __construct() {}
    #[\ReturnTypeWillChange]
    public function fetch($mode = \PDO::FETCH_ASSOC, $cursorOrientation = \PDO::FETCH_ORI_NEXT, $cursorOffset = 0) {
        $r = parent::fetch($mode, $cursorOrientation, $cursorOffset); $this->closeCursor(); return $r;
    }
    #[\ReturnTypeWillChange]
    public function fetchColumn($column = 0) { $r = parent::fetchColumn($column); $this->closeCursor(); return $r; }
}

// ---------------------------------------------------------------------------------------------
// Konfiguration (storage/config.php): Treiber sqlite|mysql
// ---------------------------------------------------------------------------------------------
function app_config(bool $reload = false): array {
    static $cfg = null;
    if ($cfg === null || $reload) {
        $cfg = ['driver' => 'sqlite', 'mysql' => ['host' => 'localhost', 'port' => 3306, 'name' => '', 'user' => '', 'pass' => '']];
        $f = APP_STORAGE . '/config.php';
        if (is_file($f)) { $c = include $f; if (is_array($c)) $cfg = array_replace_recursive($cfg, $c); }
    }
    return $cfg;
}
function save_config(array $cfg): void {
    $f = APP_STORAGE . '/config.php';
    $code = "<?php\n// automatisch erzeugt – nicht von Hand ändern\nreturn " . var_export($cfg, true) . ";\n";
    $tmp = $f . '.tmp' . bin2hex(random_bytes(3));
    if (file_put_contents($tmp, $code, LOCK_EX) === false || !rename($tmp, $f)) throw new RuntimeException('config.php konnte nicht geschrieben werden.');
    @chmod($f, 0600);
    if (function_exists('opcache_invalidate')) @opcache_invalidate($f, true);
    app_config(true);
}

function db_driver(?PDO $pdo = null): string { return ($pdo ?? db())->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite'; }

/** Öffnet eine Verbindung (ohne Schema-Prüfung). $cfg: ['driver' => ..., 'mysql' => [...]] */
function db_connect(array $cfg): PDO {
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_STATEMENT_CLASS => ['RgStatement']];
    if (($cfg['driver'] ?? 'sqlite') === 'mysql') {
        if (!extension_loaded('pdo_mysql')) throw new RuntimeException('PHP-Erweiterung pdo_mysql fehlt. Bitte beim Hoster aktivieren.');
        $m = $cfg['mysql'];
        $pdo = new PDO('mysql:host=' . $m['host'] . ';port=' . (int)$m['port'] . ';dbname=' . $m['name'] . ';charset=utf8mb4', $m['user'], secret_decrypt((string)$m['pass']), $opts + [PDO::ATTR_TIMEOUT => 8]);
        $pdo->exec("SET NAMES utf8mb4");
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        return $pdo;
    }
    if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('PHP-Erweiterung pdo_sqlite fehlt. Bitte beim Hoster aktivieren.');
    $dir = data_dir();
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_writable($dir)) throw new RuntimeException('Der Ordner storage/ ist nicht beschreibbar. Bitte Schreibrechte (chmod 775) setzen.');
    $pdo = new PDO('sqlite:' . $dir . '/rechnung.sqlite', null, null, $opts);
    $pdo->exec('PRAGMA busy_timeout = 10000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    @$pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    return $pdo;
}

function db(): PDO {
    static $pool = [];
    if (app_mode() === 'saas' && tenant_slug() === null) throw new LogicException('Kein Mandant ausgewählt.');
    $key = (app_config()['driver'] === 'mysql' && app_mode() === 'single') ? 'mysql' : data_dir();
    if (isset($pool[$key])) return $pool[$key];
    try {
        $cfg = app_config();
        if (app_mode() === 'saas') $cfg['driver'] = 'sqlite'; // Mandanten: je eine SQLite-Datei
        $pdo = db_connect($cfg);
        $ready = false;
        try { $st = $pdo->query("SELECT value FROM settings WHERE name = 'schema_version'"); $ready = $st && $st->fetchColumn() === SCHEMA_VERSION; }
        catch (PDOException $e) { if (!preg_match('/no such table|doesn.t exist|42S02/i', $e->getMessage())) throw $e; } // nur „Tabelle fehlt“ bedeutet: noch nicht angelegt
        if (!$ready) migrate($pdo);
        $pool[$key] = $pdo;
    } catch (Throwable $e) {
        error_log('DB: ' . $e->getMessage());
        http_response_code(500);
        exit('Datenbankfehler: ' . htmlspecialchars($e->getMessage()) . (app_config()['driver'] === 'mysql' ? '<br>Zugangsdaten stehen in storage/config.php (zurück auf SQLite: Zeile „driver“ auf sqlite setzen).' : ''));
    }
    return $pdo;
}

// ---------------------------------------------------------------------------------------------
// Schema (für beide Treiber). Typen: pk, int, real, ts, date, str:N (mit Default ''), sdate (nullable date)
// ---------------------------------------------------------------------------------------------
function db_schema(): array {
    $addr = 'str:600'; $txt = 'str:2000';
    return [
        'settings' => ['pk' => 'name', 'cols' => ['name' => 'str:64', 'value' => 'str:4000']],
        'roles' => ['cols' => ['id' => 'pk', 'name' => 'str:60!', 'permissions' => 'str:2000', 'is_system' => 'int']],
        'users' => ['cols' => ['id' => 'pk', 'username' => 'str:60!', 'display_name' => 'str:120', 'email' => 'str:190', 'password_hash' => 'str:255',
            'role_id' => 'int>roles:RESTRICT', 'active' => 'int', 'created_at' => 'ts', 'last_login' => 'ts?',
            'totp_secret' => 'str:255', 'totp_enabled' => 'int', 'totp_last' => 'int', 'recovery_codes' => 'str:1000', 'failed_logins' => 'int', 'locked_until' => 'ts?', 'ui_layout' => 'str:8']],
        'catalog_items' => ['cols' => ['id' => 'pk', 'kind' => 'str:10=service', 'number' => 'str:60', 'name' => 'str:190', 'description' => 'str:1000', 'unit' => 'str:30', 'price_cents' => 'int', 'cost_cents' => 'int', 'vat_rate' => 'real', 'active' => 'int', 'created_at' => 'ts'], 'index' => [['kind', 'name']]],
        'customers' => ['cols' => ['id' => 'pk', 'company' => 'str:190', 'contact_person' => 'str:190', 'firstname' => 'str:120', 'lastname' => 'str:120', 'street' => 'str:190',
            'zip' => 'str:20', 'city' => 'str:120', 'phone' => 'str:60', 'email' => 'str:190', 'leitweg_id' => 'str:60', 'notes' => $txt, 'created_at' => 'ts']],
        'invoices' => ['cols' => ['id' => 'pk', 'invoice_number' => 'str:50!', 'customer_id' => 'int>customers:RESTRICT', 'customer_address' => $addr,
            'invoice_date' => 'date', 'due_date' => 'date', 'service_date' => 'str:100', 'subject' => 'str:255', 'intro' => $txt, 'notes' => $txt,
            'status' => 'str:12', 'paid_date' => 'date?', 'cancelled_at' => 'date?', 'cancel_reason' => 'str:255',
            'net_amount' => 'int', 'vat_amount' => 'int', 'gross_amount' => 'int', 'small_business' => 'int', 'created_at' => 'ts'],
            'index' => [['customer_id'], ['status', 'due_date']]],
        'invoice_items' => ['cols' => ['id' => 'pk', 'invoice_id' => 'int>invoices:CASCADE', 'position' => 'int', 'description' => $txt, 'quantity' => 'real', 'unit' => 'str:30',
            'unit_price' => 'int', 'vat_rate' => 'real', 'total' => 'int']],
        'reminders' => ['cols' => ['id' => 'pk', 'invoice_id' => 'int>invoices:CASCADE', 'level' => 'int', 'reminder_date' => 'date', 'new_due_date' => 'date',
            'open_amount' => 'int', 'fee' => 'int', 'interest' => 'int', 'text' => $txt, 'created_at' => 'ts']],
        'offers' => ['cols' => ['id' => 'pk', 'offer_number' => 'str:50!', 'customer_id' => 'int>customers:RESTRICT', 'customer_address' => $addr, 'offer_date' => 'date', 'valid_until' => 'date',
            'subject' => 'str:255', 'intro' => $txt, 'notes' => $txt, 'status' => 'str:12', 'invoice_id' => 'int?>invoices:SET NULL',
            'net_amount' => 'int', 'vat_amount' => 'int', 'gross_amount' => 'int', 'small_business' => 'int', 'created_at' => 'ts']],
        'offer_items' => ['cols' => ['id' => 'pk', 'offer_id' => 'int>offers:CASCADE', 'position' => 'int', 'description' => $txt, 'quantity' => 'real', 'unit' => 'str:30',
            'unit_price' => 'int', 'vat_rate' => 'real', 'total' => 'int']],
        'delivery_notes' => ['cols' => ['id' => 'pk', 'note_number' => 'str:50!', 'customer_id' => 'int>customers:RESTRICT', 'customer_address' => $addr, 'note_date' => 'date',
            'subject' => 'str:255', 'intro' => $txt, 'notes' => $txt, 'invoice_id' => 'int?>invoices:SET NULL', 'created_at' => 'ts']],
        'delivery_items' => ['cols' => ['id' => 'pk', 'note_id' => 'int>delivery_notes:CASCADE', 'position' => 'int', 'description' => $txt, 'quantity' => 'real', 'unit' => 'str:30']],
        'audit_log' => ['cols' => ['id' => 'pk', 'ts' => 'ts', 'username' => 'str:120', 'action' => 'str:60', 'detail' => 'str:500', 'ip' => 'str:45'], 'index' => [['ts']]],
        'password_resets' => ['cols' => ['id' => 'pk', 'user_id' => 'int>users:CASCADE', 'token_hash' => 'str:64!', 'expires' => 'ts', 'used' => 'int', 'created_at' => 'ts']],
        'mail_log' => ['cols' => ['id' => 'pk', 'doc_type' => 'str:20', 'doc_id' => 'int', 'recipient' => 'str:190', 'subject' => 'str:255', 'ok' => 'int', 'error' => 'str:500', 'sent_at' => 'ts'],
            'index' => [['doc_type', 'doc_id']]],
    ];
}
/** Tabellen in einer Reihenfolge, in der Eltern vor Kindern stehen. */
function db_tables(): array { return array_keys(db_schema()); }

/** Spaltendefinition für CREATE/ALTER aus der Kurzschreibweise des Schemas. @return array [sql, foreignKeyClauseOrNull, isPk, isUnique] */
function schema_col_sql(string $table, array $def, string $name, bool $my): array {
    $type = $def['cols'][$name]; $null = false; $ref = null;
    if (str_contains($type, '>')) { [$type, $ref] = explode('>', $type, 2); }
    if (str_ends_with($type, '?')) { $null = true; $type = rtrim($type, '?'); }
    $unique = false; if (str_ends_with($type, '!')) { $unique = true; $type = rtrim($type, '!'); }
    $isPk = ($type === 'pk') || (($def['pk'] ?? '') === $name);
    $fk = null;
    if ($type === 'pk') return [$my ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT', null, true, false];
    if (str_starts_with($type, 'str:')) { $def0 = ''; if (str_contains($type, '=')) { [$type, $def0] = explode('=', $type, 2); } $n = (int)substr($type, 4); $t = $my ? "VARCHAR($n)" : 'TEXT'; $d = " NOT NULL DEFAULT '" . $def0 . "'"; }
    elseif ($type === 'int') { $t = $my ? 'BIGINT' : 'INTEGER'; $d = ' NOT NULL DEFAULT 0'; }
    elseif ($type === 'real') { $t = $my ? 'DOUBLE' : 'REAL'; $d = ' NOT NULL DEFAULT 0'; }
    elseif ($type === 'date') { $t = $my ? 'VARCHAR(10)' : 'TEXT'; $d = ' NOT NULL'; }
    elseif ($type === 'ts') { $t = $my ? 'DATETIME' : 'TEXT'; $d = ' NOT NULL DEFAULT CURRENT_TIMESTAMP'; }
    else throw new LogicException("Typ $type");
    if ($name === 'status' && !str_contains($def['cols']['status'], '=')) $d = " NOT NULL DEFAULT 'open'";
    if ($null) $d = ' NULL';
    if ($ref !== null && $my && $type === 'int') $t = 'INT'; // gleicher Typ wie Primärschlüssel
    $sql = $t . $d . ($unique ? ' UNIQUE' : '') . ($isPk ? ' PRIMARY KEY' : '');
    if ($ref !== null) {
        [$rt, $act] = explode(':', $ref);
        if ($my) $fk = "FOREIGN KEY ($name) REFERENCES $rt(id) ON DELETE $act"; else $sql .= " REFERENCES $rt(id) ON DELETE $act";
    }
    return [$sql, $fk, $isPk, $unique];
}

function db_existing_columns(PDO $pdo, string $table): array {
    if (db_driver($pdo) === 'mysql') { $st = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?'); $st->execute([$table]); return array_map('strtolower', $st->fetchAll(PDO::FETCH_COLUMN)); }
    return array_map(fn($r) => strtolower($r['name']), $pdo->query("PRAGMA table_info($table)")->fetchAll());
}

function migrate(PDO $pdo, ?array $schema = null, ?string $version = null): void {
    $my = db_driver($pdo) === 'mysql';
    foreach ($schema ?? db_schema() as $table => $def) {
        $cols = []; $fks = []; $idx = [];
        foreach (array_keys($def['cols']) as $name) {
            [$sql, $fk] = schema_col_sql($table, $def, $name, $my);
            $cols[] = "$name $sql"; if ($fk) $fks[] = $fk;
        }
        if ($table === 'plans') $cols[] = "CHECK (interval_unit IN ('month','year'))";
        if ($table === 'invoices') $cols[] = "CHECK (status IN ('open','paid','cancelled'))";
        if ($table === 'offers') $cols[] = "CHECK (status IN ('open','accepted','declined'))";
        if ($table === 'reminders') $cols[] = 'CHECK (level BETWEEN 1 AND 3)';
        if ($table === 'catalog_items') $cols[] = "CHECK (kind IN ('service','article'))";
        foreach ($def['index'] ?? [] as $i) $idx[] = $i;
        foreach (['customer_id', 'invoice_id', 'offer_id', 'note_id', 'role_id', 'user_id', 'sa_id', 'tenant_id'] as $fk) if (isset($def['cols'][$fk]) && !in_array([$fk], $idx, true)) $idx[] = [$fk];
        $ddl = "CREATE TABLE IF NOT EXISTS $table (\n  " . implode(",\n  ", array_merge($cols, $my ? $fks : [])) . "\n)" . ($my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '');
        $pdo->exec($ddl);
        // Neue Spalten in bereits vorhandenen Tabellen nachziehen (Updates)
        $have = db_existing_columns($pdo, $table);
        foreach (array_keys($def['cols']) as $name) {
            if (in_array(strtolower($name), $have, true)) continue;
            [$sql, $fk, $isPk, $uniq] = schema_col_sql($table, $def, $name, $my);
            if ($isPk || $uniq || str_contains($sql, 'CURRENT_TIMESTAMP') || str_contains($sql, 'REFERENCES')) throw new LogicException("Spalte $table.$name kann nicht nachträglich ergänzt werden – bitte eine Migration schreiben.");
            $pdo->exec("ALTER TABLE $table ADD COLUMN $name $sql");
        }
        if (!$my) foreach ($idx as $i) $pdo->exec('CREATE INDEX IF NOT EXISTS idx_' . $table . '_' . implode('_', $i) . " ON $table(" . implode(', ', $i) . ')');
    }
    if ($my) { // Zusatzindizes (MySQL hat kein IF NOT EXISTS für Indizes)
        foreach ($schema ?? db_schema() as $t => $def) foreach ($def['index'] ?? [] as $i) {
            $name = 'idx_' . $t . '_' . implode('_', $i);
            $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
            $st->execute([$t, $name]);
            if (!(int)$st->fetchColumn()) $pdo->exec("CREATE INDEX $name ON $t(" . implode(', ', $i) . ')');
        }
    }
    db_set($pdo, 'schema_version', $version ?? SCHEMA_VERSION);
}

/** Transaktionen: SQLite mit BEGIN IMMEDIATE (Schreiber reihen sich sauber ein statt mit „database is locked“ zu scheitern). */
function db_begin(PDO $pdo): void { if (db_driver($pdo) === 'sqlite') $pdo->exec('BEGIN IMMEDIATE'); else $pdo->beginTransaction(); }
function db_commit(PDO $pdo): void { if (db_driver($pdo) === 'sqlite') $pdo->exec('COMMIT'); else $pdo->commit(); }
function db_rollback(PDO $pdo): void { try { if (db_driver($pdo) === 'sqlite') $pdo->exec('ROLLBACK'); elseif ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $e) { /* keine offene Transaktion */ } }
/** Maximale Länge einer Textspalte laut Schema (oder null). */
function schema_len(string $table, string $col): ?int {
    $t = db_schema()[$table]['cols'][$col] ?? ''; $t = explode('>', $t)[0];
    return preg_match('/^str:(\d+)/', $t, $m) ? (int)$m[1] : null;
}
/** Prüft Textfelder gegen die Spaltenlängen; liefert Fehlertext oder null. */
function field_too_long(string $table, array $data): ?string {
    foreach ($data as $col => $v) { if (!is_string($v)) continue; $max = schema_len($table, (string)$col); if ($max !== null && mb_strlen($v) > $max) return 'Das Feld „' . $col . '“ ist zu lang (höchstens ' . $max . ' Zeichen).'; }
    return null;
}
/** Menge/Preis plausibel? (verhindert Überläufe und Unsinn) */
function amount_error(float $qty, int $priceCents): ?string {
    if (abs($qty) > 1000000) return 'Die Menge ist zu groß (höchstens 1.000.000).';
    if (abs($priceCents) > 100000000000) return 'Der Einzelpreis ist zu groß (höchstens 1.000.000.000 €).';
    return null;
}
/** Einstellung schreiben (portabler Upsert). */
function db_set(PDO $pdo, string $key, string $value): void {
    if (db_driver($pdo) === 'mysql') $pdo->prepare('INSERT INTO settings(name, value) VALUES(?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')->execute([$key, $value]);
    else $pdo->prepare('INSERT INTO settings(name, value) VALUES(?, ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value')->execute([$key, $value]);
}
function db_get(PDO $pdo, string $key): string { $st = $pdo->prepare('SELECT value FROM settings WHERE name = ?'); $st->execute([$key]); return (string)($st->fetchColumn() ?: ''); }
/** Sortierausdruck „Firma, sonst Nachname“ ohne Groß-/Kleinschreibung (portabel). */
const CUSTOMER_ORDER = "LOWER(COALESCE(NULLIF(company, ''), lastname))";

function customer_name(array $c): string {
    if ($c['company'] !== '') return $c['company'];
    return trim($c['firstname'] . ' ' . $c['lastname']);
}
/** Mehrzeilige Anschrift für Rechnung (wird beim Erstellen eingefroren). */
function customer_address(array $c): string {
    $l = [];
    if ($c['company'] !== '') {
        $l[] = $c['company'];
        $p = trim($c['contact_person'] !== '' ? $c['contact_person'] : $c['firstname'] . ' ' . $c['lastname']);
        if ($p !== '') $l[] = $p;
    } else {
        $l[] = trim($c['firstname'] . ' ' . $c['lastname']);
    }
    if ($c['street'] !== '') $l[] = $c['street'];
    $z = trim($c['zip'] . ' ' . $c['city']);
    if ($z !== '') $l[] = $z;
    return implode("\n", $l);
}

function next_invoice_number(PDO $pdo, string $date): string {
    return next_number($pdo, 'invoices', 'invoice_number', setting('invoice_prefix', 'RE-'), $date);
}
function next_offer_number(PDO $pdo, string $date): string {
    return next_number($pdo, 'offers', 'offer_number', setting('offer_prefix', 'AN-'), $date);
}
function next_delivery_number(PDO $pdo, string $date): string {
    return next_number($pdo, 'delivery_notes', 'note_number', setting('delivery_prefix', 'LS-'), $date);
}
function next_number(PDO $pdo, string $table, string $col, string $prefix, string $date): string {
    // Vergabe serialisieren: Sperre bis zum Ende der Anfrage (dann ist die Transaktion committet) – verhindert doppelte/übersprungene Nummern bei gleichzeitigen Anfragen
    static $lock = null;
    // (SQLite: BEGIN IMMEDIATE in db_begin() serialisiert bereits; MySQL: Dateisperre auf dem Webserver)
    if ($lock === null && db_driver($pdo) === 'mysql') { $lock = @fopen(data_dir() . '/numbers.lock', 'c'); if ($lock) flock($lock, LOCK_EX); }
    $year = substr($date, 0, 4);
    $prefix .= $year . '-';
    $st = $pdo->prepare("SELECT $col FROM $table WHERE substr($col, 1, ?) = ? ORDER BY LENGTH($col) DESC, $col DESC LIMIT 1");
    $st->execute([strlen($prefix), $prefix]);
    $last = $st->fetchColumn();
    $n = $last ? (int)substr((string)$last, strlen($prefix)) + 1 : 1;
    return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}
