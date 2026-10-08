<?php
declare(strict_types=1);

/** Export/Import aller Daten (treiberunabhängig), Backups und Datenbank-Umstellung. */

const BACKUP_FORMAT = 'rechnungsprogramm-backup';

function backup_dir(): string {
    $d = APP_STORAGE . '/backups';
    if (!is_dir($d)) { @mkdir($d, 0775, true); @file_put_contents($d . '/index.html', ''); }
    return $d;
}

function db_export(PDO $pdo): array {
    $data = [];
    foreach (db_schema() as $t => $def) {
        $order = isset($def['cols']['id']) ? 'id' : 'name';
        $data[$t] = $pdo->query("SELECT * FROM $t ORDER BY $order")->fetchAll();
    }
    return $data;
}

/** Ersetzt den gesamten Datenbestand von $pdo durch $data (legt das Schema bei Bedarf an). */
function db_import(PDO $pdo, array $data): void {
    migrate($pdo);
    $my = db_driver($pdo) === 'mysql';
    $pdo->exec($my ? 'SET FOREIGN_KEY_CHECKS = 0' : 'PRAGMA foreign_keys = OFF');
    $pdo->beginTransaction();
    try {
        foreach (array_reverse(db_tables()) as $t) $pdo->exec("DELETE FROM $t");
        foreach (db_schema() as $t => $def) {
            $rows = $data[$t] ?? [];
            if (!$rows) continue;
            $cols = array_keys($def['cols']);
            foreach ($rows as $r) {
                $use = array_values(array_filter($cols, fn($c) => array_key_exists($c, $r)));
                if (!$use) continue;
                $pdo->prepare("INSERT INTO $t (" . implode(', ', $use) . ') VALUES (' . implode(', ', array_fill(0, count($use), '?')) . ')')
                    ->execute(array_map(fn($c) => $r[$c], $use));
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    } finally {
        $pdo->exec($my ? 'SET FOREIGN_KEY_CHECKS = 1' : 'PRAGMA foreign_keys = ON');
    }
    // schema_version nach dem Löschen der Einstellungen wieder setzen
    db_set($pdo, 'schema_version', SCHEMA_VERSION);
    if ($my) foreach (db_schema() as $t => $def) if (isset($def['cols']['id'])) { $m = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM $t")->fetchColumn(); $pdo->exec("ALTER TABLE $t AUTO_INCREMENT = " . ($m + 1)); }
}

function db_counts(PDO $pdo): array {
    $c = [];
    foreach (db_tables() as $t) { try { $c[$t] = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(); } catch (Throwable $e) { $c[$t] = 0; } }
    return $c;
}
function db_has_data(PDO $pdo): bool {
    $c = db_counts($pdo);
    return ($c['customers'] ?? 0) + ($c['invoices'] ?? 0) + ($c['users'] ?? 0) > 0;
}

// ---------------------------------------------------------------------------------------------
// Backups
// ---------------------------------------------------------------------------------------------
function backup_payload(): array {
    $logo = APP_STORAGE . '/logo.jpg';
    return ['format' => BACKUP_FORMAT, 'version' => 1, 'created' => date('c'), 'driver' => db_driver(), 'schema' => SCHEMA_VERSION,
        'tables' => db_export(db()), 'files' => is_file($logo) ? ['logo.jpg' => base64_encode((string)file_get_contents($logo))] : []];
}
function backup_encode(array $payload): string {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) throw new RuntimeException('Backup konnte nicht kodiert werden.');
    return function_exists('gzencode') ? gzencode($json, 6) : $json;
}
function backup_decode(string $raw): array {
    if (strncmp($raw, "\x1f\x8b", 2) === 0) {
        if (!function_exists('gzdecode')) throw new RuntimeException('Auf diesem Server fehlt zlib zum Lesen der Datei.');
        $raw = @gzdecode($raw);
        if ($raw === false) throw new RuntimeException('Die Datei ist beschädigt.');
    }
    $p = json_decode($raw, true);
    if (!is_array($p) || ($p['format'] ?? '') !== BACKUP_FORMAT || !is_array($p['tables'] ?? null)) throw new RuntimeException('Das ist keine gültige Backup-Datei dieses Programms.');
    return $p;
}

/** @param string $kind manual|auto|safety @return string Dateiname */
function backup_create(string $kind = 'manual'): string {
    $name = 'backup_' . $kind . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(2)) . '.rgb';
    $path = backup_dir() . '/' . $name;
    if (file_put_contents($path, backup_encode(backup_payload()), LOCK_EX) === false) throw new RuntimeException('Backup konnte nicht gespeichert werden (storage/backups schreibbar?).');
    @chmod($path, 0600);
    return $name;
}
function backup_valid_name(string $n): bool { return (bool)preg_match('/^backup_(manual|auto|safety|upload)_\d{8}_\d{6}_[0-9a-f]{4}\.rgb$/', $n); }

function backup_list(): array {
    $out = [];
    foreach (glob(backup_dir() . '/backup_*.rgb') ?: [] as $f) {
        $n = basename($f);
        if (!backup_valid_name($n)) continue;
        preg_match('/^backup_(\w+?)_(\d{8})_(\d{6})_/', $n, $m);
        $out[] = ['name' => $n, 'kind' => $m[1], 'time' => filemtime($f), 'ts' => strtotime($m[2] . 'T' . $m[3]) ?: filemtime($f), 'size' => filesize($f)];
    }
    usort($out, fn($a, $b) => $b['ts'] <=> $a['ts'] ?: strcmp($b['name'], $a['name']));
    return $out;
}

/** Löscht alte automatische Backups über das Limit hinaus. */
function backup_prune(): int {
    $keep = max(1, (int)setting('backup_keep', '7'));
    $auto = array_values(array_filter(backup_list(), fn($b) => $b['kind'] === 'auto'));
    $n = 0;
    foreach (array_slice($auto, $keep) as $b) if (@unlink(backup_dir() . '/' . $b['name'])) $n++;
    $safety = array_values(array_filter(backup_list(), fn($b) => $b['kind'] === 'safety'));
    foreach (array_slice($safety, 5) as $b) @unlink(backup_dir() . '/' . $b['name']);
    return $n;
}

function backup_due(): bool {
    $iv = setting('backup_interval', 'off');
    if (!in_array($iv, ['daily', 'weekly', 'monthly'], true)) return false;
    $last = 0;
    foreach (backup_list() as $b) if ($b['kind'] === 'auto') { $last = $b['ts']; break; }
    if ($last === 0) return true;
    $next = ['daily' => $last + 86400, 'weekly' => $last + 7 * 86400, 'monthly' => strtotime('+1 month', $last)][$iv] - 3600;
    return time() >= $next;
}
/** @return ?string Dateiname, wenn ein Backup erstellt wurde */
function backup_run_if_due(): ?string {
    if (!backup_due()) return null;
    $lock = fopen(APP_STORAGE . '/backup.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return null;
    try {
        if (!backup_due()) return null;
        $n = backup_create('auto'); backup_prune();
        return $n;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

/** Spielt ein Backup in die aktuelle Datenbank ein (vorher Sicherheitskopie). */
function backup_restore(array $payload): void {
    backup_create('safety');
    db_import(db(), $payload['tables']);
    $logo = APP_STORAGE . '/logo.jpg';
    if (!empty($payload['files']['logo.jpg'])) file_put_contents($logo, base64_decode($payload['files']['logo.jpg']));
    else @unlink($logo);
}

// ---------------------------------------------------------------------------------------------
// Umstellung SQLite <-> MySQL
// ---------------------------------------------------------------------------------------------
/** Kopiert alle Daten in die Zielkonfiguration und schaltet erst danach um. @return array Zeilenanzahlen */
function db_switch(array $target, bool $overwrite): array {
    $cur = app_config();
    if ($target['driver'] === $cur['driver'] && $target['driver'] === 'sqlite') throw new RuntimeException('Es wird bereits SQLite verwendet.');
    $dst = db_connect($target);
    migrate($dst);
    if (db_has_data($dst) && !$overwrite) throw new RuntimeException('In der Ziel-Datenbank sind bereits Daten vorhanden. Zum Überschreiben bitte das Häkchen setzen.');
    $src = db(); $data = db_export($src);
    backup_create('safety'); // Sicherheitskopie vor der Umstellung
    db_import($dst, $data);
    $a = db_counts($src); $b = db_counts($dst);
    foreach ($a as $t => $n) if ($n !== $b[$t]) throw new RuntimeException("Prüfung fehlgeschlagen: Tabelle $t hat $b[$t] statt $n Zeilen. Die Umstellung wurde nicht aktiviert.");
    $cfg = $cur; $cfg['driver'] = $target['driver'];
    if ($target['driver'] === 'mysql') $cfg['mysql'] = $target['mysql'];
    save_config($cfg);
    return $b;
}
