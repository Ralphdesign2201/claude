<?php
declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!extension_loaded('pdo_sqlite')) {
        http_response_code(500);
        exit('PHP-Erweiterung pdo_sqlite fehlt. Bitte beim Hoster aktivieren.');
    }
    if (!is_dir(APP_STORAGE)) @mkdir(APP_STORAGE, 0755, true);
    if (!is_writable(APP_STORAGE)) {
        http_response_code(500);
        exit('Der Ordner storage/ ist nicht beschreibbar. Bitte Schreibrechte (chmod 775) setzen.');
    }
    $pdo = new PDO('sqlite:' . APP_STORAGE . '/rechnung.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        company TEXT NOT NULL DEFAULT '',
        contact_person TEXT NOT NULL DEFAULT '',
        firstname TEXT NOT NULL DEFAULT '',
        lastname TEXT NOT NULL DEFAULT '',
        street TEXT NOT NULL DEFAULT '',
        zip TEXT NOT NULL DEFAULT '',
        city TEXT NOT NULL DEFAULT '',
        phone TEXT NOT NULL DEFAULT '',
        email TEXT NOT NULL DEFAULT '',
        notes TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS invoices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_number TEXT NOT NULL UNIQUE,
        customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE RESTRICT,
        customer_address TEXT NOT NULL DEFAULT '',
        invoice_date TEXT NOT NULL,
        due_date TEXT NOT NULL,
        service_date TEXT NOT NULL DEFAULT '',
        subject TEXT NOT NULL DEFAULT '',
        intro TEXT NOT NULL DEFAULT '',
        notes TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open','paid','cancelled')),
        paid_date TEXT,
        cancelled_at TEXT,
        cancel_reason TEXT NOT NULL DEFAULT '',
        net_amount INTEGER NOT NULL DEFAULT 0,
        vat_amount INTEGER NOT NULL DEFAULT 0,
        gross_amount INTEGER NOT NULL DEFAULT 0,
        small_business INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS invoice_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
        position INTEGER NOT NULL DEFAULT 0,
        description TEXT NOT NULL,
        quantity REAL NOT NULL DEFAULT 1,
        unit TEXT NOT NULL DEFAULT '',
        unit_price INTEGER NOT NULL DEFAULT 0,
        vat_rate REAL NOT NULL DEFAULT 19,
        total INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reminders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
        level INTEGER NOT NULL CHECK (level BETWEEN 1 AND 3),
        reminder_date TEXT NOT NULL,
        new_due_date TEXT NOT NULL,
        open_amount INTEGER NOT NULL,
        fee INTEGER NOT NULL DEFAULT 0,
        interest INTEGER NOT NULL DEFAULT 0,
        text TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS offers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        offer_number TEXT NOT NULL UNIQUE,
        customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE RESTRICT,
        customer_address TEXT NOT NULL DEFAULT '',
        offer_date TEXT NOT NULL,
        valid_until TEXT NOT NULL,
        subject TEXT NOT NULL DEFAULT '',
        intro TEXT NOT NULL DEFAULT '',
        notes TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open','accepted','declined')),
        invoice_id INTEGER REFERENCES invoices(id) ON DELETE SET NULL,
        net_amount INTEGER NOT NULL DEFAULT 0,
        vat_amount INTEGER NOT NULL DEFAULT 0,
        gross_amount INTEGER NOT NULL DEFAULT 0,
        small_business INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS offer_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        offer_id INTEGER NOT NULL REFERENCES offers(id) ON DELETE CASCADE,
        position INTEGER NOT NULL DEFAULT 0,
        description TEXT NOT NULL,
        quantity REAL NOT NULL DEFAULT 1,
        unit TEXT NOT NULL DEFAULT '',
        unit_price INTEGER NOT NULL DEFAULT 0,
        vat_rate REAL NOT NULL DEFAULT 19,
        total INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_offer_items ON offer_items(offer_id)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS mail_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        doc_type TEXT NOT NULL, doc_id INTEGER NOT NULL,
        recipient TEXT NOT NULL, subject TEXT NOT NULL,
        ok INTEGER NOT NULL, error TEXT NOT NULL DEFAULT '',
        sent_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mail_doc ON mail_log(doc_type, doc_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rem_inv ON reminders(invoice_id)');
    $cols = array_column($pdo->query('PRAGMA table_info(customers)')->fetchAll(), 'name');
    if (!in_array('leitweg_id', $cols, true)) $pdo->exec("ALTER TABLE customers ADD COLUMN leitweg_id TEXT NOT NULL DEFAULT ''");
    $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_notes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        note_number TEXT NOT NULL UNIQUE,
        customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE RESTRICT,
        customer_address TEXT NOT NULL DEFAULT '',
        note_date TEXT NOT NULL,
        subject TEXT NOT NULL DEFAULT '',
        intro TEXT NOT NULL DEFAULT '',
        notes TEXT NOT NULL DEFAULT '',
        invoice_id INTEGER REFERENCES invoices(id) ON DELETE SET NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        note_id INTEGER NOT NULL REFERENCES delivery_notes(id) ON DELETE CASCADE,
        position INTEGER NOT NULL DEFAULT 0,
        description TEXT NOT NULL,
        quantity REAL NOT NULL DEFAULT 1,
        unit TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_deliv_items ON delivery_items(note_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_inv_customer ON invoices(customer_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_inv_status ON invoices(status, due_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_items_inv ON invoice_items(invoice_id)');
}

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
    $year = substr($date, 0, 4);
    $prefix .= $year . '-';
    $st = $pdo->prepare("SELECT $col FROM $table WHERE substr($col, 1, ?) = ? ORDER BY LENGTH($col) DESC, $col DESC LIMIT 1");
    $st->execute([strlen($prefix), $prefix]);
    $last = $st->fetchColumn();
    $n = $last ? (int)substr((string)$last, strlen($prefix)) + 1 : 1;
    return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}
