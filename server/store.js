// Datenschicht: SQLite-Datei, optionale Verschlüsselung, Sicherungen, Anhänge, Sperrdatei
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const { DatabaseSync } = require('node:sqlite');
const C = require('./crypto');

const DATA = path.resolve(process.env.KANBAN_DATA || path.join(__dirname, '..', 'daten'));
const FILES = path.join(DATA, 'anhaenge');
const BACKUPS = path.join(DATA, 'sicherungen');
const PLAIN = path.join(DATA, 'kanban.db');
const ENC = PLAIN + '.enc';
const SEC = path.join(DATA, 'sicherheit.json');
const LOCK = path.join(DATA, 'kanban.lock');
for (const d of [DATA, FILES, BACKUPS]) fs.mkdirSync(d, { recursive: true });

const readJson = (f, d) => { try { return JSON.parse(fs.readFileSync(f, 'utf8')); } catch { return d; } };
let sec = readJson(SEC, { encrypted: false });
let db = null, key = null, workDir = null, dirty = false, flushTimer = null;

const SCHEMA = `
CREATE TABLE IF NOT EXISTS boards (id INTEGER PRIMARY KEY, name TEXT NOT NULL, color TEXT DEFAULT '#4f7cff', icon TEXT DEFAULT '▦', pos REAL DEFAULT 0, archived INTEGER DEFAULT 0);
CREATE TABLE IF NOT EXISTS columns (id INTEGER PRIMARY KEY, board_id INTEGER NOT NULL REFERENCES boards(id) ON DELETE CASCADE, name TEXT NOT NULL, pos REAL DEFAULT 0, wip_limit INTEGER, collapsed INTEGER DEFAULT 0, archived INTEGER DEFAULT 0);
CREATE TABLE IF NOT EXISTS cards (id INTEGER PRIMARY KEY, column_id INTEGER NOT NULL REFERENCES columns(id) ON DELETE CASCADE, title TEXT NOT NULL, description TEXT DEFAULT '', priority INTEGER DEFAULT 0, due_date TEXT, pos REAL DEFAULT 0, archived INTEGER DEFAULT 0, deleted_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS labels (id INTEGER PRIMARY KEY, name TEXT NOT NULL, color TEXT DEFAULT '#6b7285');
CREATE TABLE IF NOT EXISTS card_labels (card_id INTEGER NOT NULL REFERENCES cards(id) ON DELETE CASCADE, label_id INTEGER NOT NULL REFERENCES labels(id) ON DELETE CASCADE, PRIMARY KEY (card_id,label_id));
CREATE TABLE IF NOT EXISTS checklists (id INTEGER PRIMARY KEY, card_id INTEGER NOT NULL REFERENCES cards(id) ON DELETE CASCADE, title TEXT NOT NULL DEFAULT 'Checkliste', pos REAL DEFAULT 0);
CREATE TABLE IF NOT EXISTS checklist_items (id INTEGER PRIMARY KEY, checklist_id INTEGER NOT NULL REFERENCES checklists(id) ON DELETE CASCADE, text TEXT NOT NULL, done INTEGER DEFAULT 0, pos REAL DEFAULT 0);
CREATE TABLE IF NOT EXISTS comments (id INTEGER PRIMARY KEY, card_id INTEGER NOT NULL REFERENCES cards(id) ON DELETE CASCADE, user TEXT, body TEXT NOT NULL, created_at TEXT, edited_at TEXT);
CREATE TABLE IF NOT EXISTS attachments (id INTEGER PRIMARY KEY, card_id INTEGER NOT NULL REFERENCES cards(id) ON DELETE CASCADE, name TEXT, mime TEXT, size INTEGER, file TEXT, enc INTEGER DEFAULT 0, created_at TEXT);
CREATE TABLE IF NOT EXISTS card_links (id INTEGER PRIMARY KEY, from_card INTEGER NOT NULL REFERENCES cards(id) ON DELETE CASCADE, to_card INTEGER NOT NULL REFERENCES cards(id) ON DELETE CASCADE, type TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS time_entries (id INTEGER PRIMARY KEY, card_id INTEGER NOT NULL REFERENCES cards(id) ON DELETE CASCADE, user TEXT, started_at TEXT NOT NULL, ended_at TEXT, seconds INTEGER DEFAULT 0, note TEXT DEFAULT '');
CREATE TABLE IF NOT EXISTS activity (id INTEGER PRIMARY KEY, card_id INTEGER NOT NULL REFERENCES cards(id) ON DELETE CASCADE, user TEXT, text TEXT, at TEXT);
CREATE TABLE IF NOT EXISTS card_history (id INTEGER PRIMARY KEY, card_id INTEGER NOT NULL REFERENCES cards(id) ON DELETE CASCADE, column_id INTEGER, entered_at TEXT NOT NULL, left_at TEXT);
CREATE TABLE IF NOT EXISTS views (id INTEGER PRIMARY KEY, board_id INTEGER NOT NULL REFERENCES boards(id) ON DELETE CASCADE, name TEXT NOT NULL, view TEXT DEFAULT 'board', filter TEXT DEFAULT '{}');
CREATE TABLE IF NOT EXISTS templates (id INTEGER PRIMARY KEY, name TEXT NOT NULL, data TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT);
CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE, pass TEXT NOT NULL, role TEXT DEFAULT 'user');
CREATE INDEX IF NOT EXISTS i_cards_col ON cards(column_id);
CREATE INDEX IF NOT EXISTS i_hist_card ON card_history(card_id);
CREATE INDEX IF NOT EXISTS i_time_card ON time_entries(card_id);
`;
const ADD = {
  boards: { swimlane: "TEXT DEFAULT 'none'" },
  columns: { done: 'INTEGER DEFAULT 0', sort: "TEXT DEFAULT 'manual'" },
  cards: { start_date: 'TEXT', due_time: 'TEXT', assignee: "TEXT DEFAULT ''", customer: "TEXT DEFAULT ''", crm_ref: "TEXT DEFAULT ''",
    est_minutes: 'INTEGER', parent_id: 'INTEGER', recur: "TEXT DEFAULT ''", recur_every: 'INTEGER DEFAULT 1', recur_spawned: 'INTEGER DEFAULT 0', done_at: 'TEXT', created_by: "TEXT DEFAULT ''" },
};
function migrate() {
  db.exec(SCHEMA);
  for (const [t, cols] of Object.entries(ADD)) {
    const have = db.prepare(`PRAGMA table_info(${t})`).all().map(c => c.name);
    for (const [c, def] of Object.entries(cols)) if (!have.includes(c)) db.exec(`ALTER TABLE ${t} ADD COLUMN ${c} ${def}`);
  }
  if (!db.prepare("SELECT 1 x FROM settings WHERE key='migrated2'").get()) {
    db.exec(`UPDATE columns SET done=1 WHERE lower(name) IN ('erledigt','fertig','done')`);
    db.exec(`UPDATE cards SET done_at=COALESCE(updated_at,created_at) WHERE column_id IN (SELECT id FROM columns WHERE done=1) AND done_at IS NULL`);
    db.exec(`INSERT INTO card_history (card_id,column_id,entered_at) SELECT id,column_id,COALESCE(created_at,datetime('now')) FROM cards WHERE id NOT IN (SELECT card_id FROM card_history)`);
    db.exec(`INSERT INTO settings (key,value) VALUES ('migrated2','1')`);
  }
}
function openDb(file) {
  db = new DatabaseSync(file);
  db.exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 3000;');
  migrate();
}

const san = p => p.map(v => v === undefined ? null : typeof v === 'boolean' ? +v : v);
const all = (sql, ...p) => db.prepare(sql).all(...san(p));
const get = (sql, ...p) => db.prepare(sql).get(...san(p));
const run = (sql, ...p) => db.prepare(sql).run(...san(p));
function tx(fn) {
  db.exec('BEGIN');
  try { const r = fn(); db.exec('COMMIT'); return r; } catch (e) { try { db.exec('ROLLBACK'); } catch {} throw e; }
}
const getSetting = (k, d) => { const r = get('SELECT value FROM settings WHERE key=?', k); if (!r) return d; try { return JSON.parse(r.value); } catch { return d; } };
const setSetting = (k, v) => run('INSERT INTO settings (key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value', k, JSON.stringify(v));

// ---------- Start / Entsperren ----------
const isLocked = () => sec.encrypted && !db;
const isEncrypted = () => !!sec.encrypted;
const workFile = () => path.join(workDir, 'kanban.db');
function makeWorkDir() { workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'kanban-')); }
function start() { if (!sec.encrypted) openDb(PLAIN); }
function unlock(pw) {
  if (!sec.encrypted) return true;
  if (db) return true;
  const k = C.deriveKey(pw, Buffer.from(sec.salt, 'hex'));
  try { if (C.decrypt(Buffer.from(sec.check, 'base64'), k).toString() !== 'kanban') return false; } catch { return false; }
  key = k; makeWorkDir();
  if (fs.existsSync(ENC)) fs.writeFileSync(workFile(), C.decrypt(fs.readFileSync(ENC), key));
  openDb(workFile());
  return true;
}
function markDirty() {
  if (!sec.encrypted) return;
  dirty = true; clearTimeout(flushTimer);
  flushTimer = setTimeout(flush, 3000); flushTimer.unref?.();
}
function flush() {
  if (!sec.encrypted || !db || !dirty) return;
  db.exec('PRAGMA wal_checkpoint(TRUNCATE)');
  fs.writeFileSync(ENC + '.tmp', C.encrypt(fs.readFileSync(workFile()), key));
  fs.renameSync(ENC + '.tmp', ENC); dirty = false;
}
function closeAll() {
  try { flush(); } catch (e) { console.error('Speichern fehlgeschlagen:', e.message); }
  try { db?.close(); } catch {}
  db = null;
  if (workDir) { fs.rmSync(workDir, { recursive: true, force: true }); workDir = null; }
}

// ---------- Anhänge ----------
function saveFile(buf) {
  const name = crypto.randomBytes(12).toString('hex');
  fs.writeFileSync(path.join(FILES, name), key ? C.encrypt(buf, key) : buf);
  return { file: name, enc: key ? 1 : 0 };
}
function readFile(row) {
  const b = fs.readFileSync(path.join(FILES, row.file));
  return row.enc ? C.decrypt(b, key) : b;
}
const removeFile = row => { try { fs.unlinkSync(path.join(FILES, row.file)); } catch {} };

// ---------- Sicherungen ----------
const esc = s => s.replace(/'/g, "''");
function listBackups() {
  return fs.readdirSync(BACKUPS).filter(f => /^kanban-.*\.db(\.enc)?$/.test(f)).sort().reverse()
    .map(f => ({ name: f, size: fs.statSync(path.join(BACKUPS, f)).size, encrypted: f.endsWith('.enc') }));
}
function backup(label = '') {
  const stamp = new Date().toISOString().replace(/[:T]/g, '-').slice(0, 19);
  const tmp = path.join(workDir || BACKUPS, `.tmp-${crypto.randomBytes(4).toString('hex')}.db`);
  db.exec(`VACUUM INTO '${esc(tmp)}'`);
  let name = `kanban-${stamp}${label}.db`;
  const dest = () => path.join(BACKUPS, name);
  if (key) { name += '.enc'; fs.writeFileSync(dest(), C.encrypt(fs.readFileSync(tmp), key)); fs.unlinkSync(tmp); }
  else fs.renameSync(tmp, dest());
  const dir2 = getSetting('backup_dir2', '');
  let second = null;
  if (dir2) { try { fs.mkdirSync(dir2, { recursive: true }); fs.copyFileSync(dest(), path.join(dir2, name)); second = dir2; } catch (e) { second = 'FEHLER: ' + e.message; } }
  const keep = Math.max(1, Number(getSetting('backup_keep', 7)));
  const auto = listBackups().filter(b => !/-(manuell|vor-)/.test(b.name)).map(b => b.name).sort();
  auto.slice(0, Math.max(0, auto.length - keep)).forEach(f => fs.unlinkSync(path.join(BACKUPS, f)));
  if (dir2 && second === dir2) {
    try { const l = fs.readdirSync(dir2).filter(f => /^kanban-\d.*\.db(\.enc)?$/.test(f) && !/-(manuell|vor-)/.test(f)).sort(); l.slice(0, Math.max(0, l.length - keep)).forEach(f => fs.unlinkSync(path.join(dir2, f))); } catch {}
  }
  return { file: name, second };
}
function dailyBackup() {
  if (!db) return;
  const today = new Date().toISOString().slice(0, 10);
  if (!listBackups().some(b => b.name.startsWith('kanban-' + today) && !/-(manuell|vor-)/.test(b.name))) backup();
}
function restore(name) {
  const f = path.join(BACKUPS, path.basename(name));
  if (!fs.existsSync(f)) throw new Error('Sicherung nicht gefunden');
  let buf = fs.readFileSync(f);
  if (f.endsWith('.enc')) { if (!key) throw new Error('Diese Sicherung ist verschlüsselt. Aktiviere zuerst die Verschlüsselung mit dem alten Passwort.'); buf = C.decrypt(buf, key); }
  backup('-vor-wiederherstellung');
  const target = sec.encrypted ? workFile() : PLAIN;
  db.close(); db = null;
  for (const s of ['', '-wal', '-shm']) fs.rmSync(target + s, { force: true });
  fs.writeFileSync(target, buf);
  openDb(target); markDirty(); flush();
}

// ---------- Verschlüsselung ein/aus ----------
function enableEncryption(pw) {
  if (sec.encrypted) throw new Error('Schon verschlüsselt');
  if (String(pw).length < 6) throw new Error('Passwort mindestens 6 Zeichen');
  const salt = crypto.randomBytes(16), k = C.deriveKey(pw, salt);
  db.exec('PRAGMA wal_checkpoint(TRUNCATE)');
  // Anhänge und Sicherungen verschlüsseln
  for (const a of all('SELECT * FROM attachments WHERE enc=0')) {
    const p = path.join(FILES, a.file); fs.writeFileSync(p, C.encrypt(fs.readFileSync(p), k)); run('UPDATE attachments SET enc=1 WHERE id=?', a.id);
  }
  db.exec('PRAGMA wal_checkpoint(TRUNCATE)');
  const plainBuf = fs.readFileSync(PLAIN);
  fs.writeFileSync(ENC, C.encrypt(plainBuf, k));
  for (const b of listBackups().filter(b => !b.encrypted)) {
    const p = path.join(BACKUPS, b.name); fs.writeFileSync(p + '.enc', C.encrypt(fs.readFileSync(p), k)); fs.unlinkSync(p);
  }
  sec = { encrypted: true, salt: salt.toString('hex'), check: C.encrypt(Buffer.from('kanban'), k).toString('base64') };
  fs.writeFileSync(SEC, JSON.stringify(sec));
  key = k; db.close(); makeWorkDir();
  fs.writeFileSync(workFile(), plainBuf);
  for (const s of ['', '-wal', '-shm']) fs.rmSync(PLAIN + s, { force: true });
  openDb(workFile());
}
function disableEncryption(pw) {
  if (!sec.encrypted) throw new Error('Nicht verschlüsselt');
  if (!C.deriveKey(pw, Buffer.from(sec.salt, 'hex')).equals(key)) throw new Error('Passwort falsch');
  for (const a of all('SELECT * FROM attachments WHERE enc=1')) {
    const p = path.join(FILES, a.file); fs.writeFileSync(p, C.decrypt(fs.readFileSync(p), key)); run('UPDATE attachments SET enc=0 WHERE id=?', a.id);
  }
  db.exec('PRAGMA wal_checkpoint(TRUNCATE)');
  const buf = fs.readFileSync(workFile());
  for (const b of listBackups().filter(b => b.encrypted)) {
    const p = path.join(BACKUPS, b.name); fs.writeFileSync(p.slice(0, -4), C.decrypt(fs.readFileSync(p), key)); fs.unlinkSync(p);
  }
  fs.writeFileSync(PLAIN, buf);
  db.close(); fs.rmSync(workDir, { recursive: true, force: true }); workDir = null;
  fs.rmSync(ENC, { force: true }); fs.rmSync(SEC, { force: true });
  sec = { encrypted: false }; key = null; dirty = false;
  openDb(PLAIN);
}

// ---------- Sperrdatei (nur ein Rechner schreibt) ----------
const STALE_MS = 20000;
let lockTimer = null;
const alive = pid => { try { process.kill(pid, 0); return true; } catch (e) { return e.code === 'EPERM'; } };
function acquireLock(port) {
  const cur = readJson(LOCK, null);
  if (cur && Date.now() - cur.ts < STALE_MS && !process.env.KANBAN_FORCE) {
    const sameHost = cur.host === os.hostname();
    if (!sameHost || alive(cur.pid)) {
      const err = new Error(`Der Datenordner wird gerade von „${cur.host}“ benutzt (Prozess ${cur.pid}). Es darf immer nur ein Rechner gleichzeitig schreiben.\n` +
        `Beende dort das Programm oder warte 20 Sekunden. Notfalls mit KANBAN_FORCE=1 starten.`);
      err.code = 'LOCKED'; throw err;
    }
  }
  const write = () => { try { fs.writeFileSync(LOCK, JSON.stringify({ host: os.hostname(), pid: process.pid, port, ts: Date.now() })); } catch {} };
  write(); lockTimer = setInterval(write, 5000); lockTimer.unref?.();
}
function releaseLock() {
  clearInterval(lockTimer);
  const cur = readJson(LOCK, null);
  if (cur && cur.pid === process.pid && cur.host === os.hostname()) fs.rmSync(LOCK, { force: true });
}

module.exports = {
  DATA, FILES, BACKUPS, PLAIN, all, get, run, tx, getSetting, setSetting, start, unlock, isLocked, isEncrypted, markDirty, flush, closeAll,
  saveFile, readFile, removeFile, listBackups, backup, dailyBackup, restore, enableEncryption, disableEncryption, acquireLock, releaseLock,
  hasKey: () => !!key, get db() { return db; },
};
