// Lokales Kanban – Server ohne Abhängigkeiten (Node 22+, eingebautes SQLite)
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const { DatabaseSync } = require('node:sqlite');

const PORT = Number(process.env.PORT || 4545);
const DATA = path.resolve(process.env.KANBAN_DATA || path.join(__dirname, 'daten'));
const BACKUPS = path.join(DATA, 'sicherungen');
const KEEP = Number(process.env.KANBAN_BACKUPS || 7);
fs.mkdirSync(BACKUPS, { recursive: true });

const db = new DatabaseSync(path.join(DATA, 'kanban.db'));
db.exec(`
PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;
CREATE TABLE IF NOT EXISTS boards (
  id INTEGER PRIMARY KEY, name TEXT NOT NULL, color TEXT DEFAULT '#4f7cff',
  icon TEXT DEFAULT '▦', pos REAL DEFAULT 0, archived INTEGER DEFAULT 0);
CREATE TABLE IF NOT EXISTS columns (
  id INTEGER PRIMARY KEY, board_id INTEGER NOT NULL REFERENCES boards(id) ON DELETE CASCADE,
  name TEXT NOT NULL, pos REAL DEFAULT 0, wip_limit INTEGER, collapsed INTEGER DEFAULT 0,
  archived INTEGER DEFAULT 0);
CREATE TABLE IF NOT EXISTS cards (
  id INTEGER PRIMARY KEY, column_id INTEGER NOT NULL REFERENCES columns(id) ON DELETE CASCADE,
  title TEXT NOT NULL, description TEXT DEFAULT '', priority INTEGER DEFAULT 0,
  due_date TEXT, pos REAL DEFAULT 0, archived INTEGER DEFAULT 0, deleted_at TEXT,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP);
`);

const now = () => new Date().toISOString();
const all = (sql, ...p) => db.prepare(sql).all(...p);
const get = (sql, ...p) => db.prepare(sql).get(...p);
const run = (sql, ...p) => db.prepare(sql).run(...p);

const TEMPLATES = {
  leer: ['Zu erledigen', 'In Arbeit', 'Erledigt'],
  webprojekt: ['Briefing', 'Design', 'Umsetzung', 'Test', 'Fertig'],
};

function createBoard(b) {
  const pos = (get('SELECT MAX(pos) m FROM boards').m || 0) + 1;
  const id = Number(run('INSERT INTO boards (name,color,icon,pos) VALUES (?,?,?,?)',
    String(b.name || 'Neues Board'), b.color || '#4f7cff', b.icon || '▦', pos).lastInsertRowid);
  (TEMPLATES[b.template] || TEMPLATES.leer).forEach((n, i) =>
    run('INSERT INTO columns (board_id,name,pos) VALUES (?,?,?)', id, n, i + 1));
  return id;
}
if (!get('SELECT 1 x FROM boards')) createBoard({ name: 'Mein Board' });

function boardData(id) {
  const board = get('SELECT * FROM boards WHERE id=?', id);
  if (!board) return null;
  const columns = all('SELECT * FROM columns WHERE board_id=? AND archived=0 ORDER BY pos', id);
  const cards = all(`SELECT c.* FROM cards c JOIN columns k ON k.id=c.column_id
    WHERE k.board_id=? AND c.archived=0 AND c.deleted_at IS NULL ORDER BY c.pos`, id);
  return { board, columns, cards };
}

// Neu nummerieren: ids in Reihenfolge -> pos 1..n
function renumber(table, ids, extra = '') {
  ids.forEach((id, i) => run(`UPDATE ${table} SET pos=? ${extra} WHERE id=?`, i + 1, id));
}
function pick(obj, allowed) {
  const out = {};
  for (const k of allowed) if (k in obj) out[k] = obj[k];
  return out;
}
function update(table, id, fields) {
  const keys = Object.keys(fields);
  if (!keys.length) return;
  run(`UPDATE ${table} SET ${keys.map(k => k + '=?').join(',')} WHERE id=?`, ...keys.map(k => fields[k]), id);
}

function backup(label = '') {
  const d = new Date().toISOString().replace(/[:T]/g, '-').slice(0, 19);
  const file = path.join(BACKUPS, `kanban-${d}${label}.db`);
  db.exec(`VACUUM INTO '${file.replace(/'/g, "''")}'`);
  const files = fs.readdirSync(BACKUPS).filter(f => f.endsWith('.db')).sort();
  files.slice(0, Math.max(0, files.length - KEEP)).forEach(f => fs.unlinkSync(path.join(BACKUPS, f)));
  return file;
}
function dailyBackup() {
  const today = new Date().toISOString().slice(0, 10);
  if (!fs.readdirSync(BACKUPS).some(f => f.startsWith('kanban-' + today))) backup();
}
dailyBackup();
setInterval(dailyBackup, 3600e3).unref();

const routes = [];
const route = (m, re, fn) => routes.push([m, new RegExp('^' + re + '$'), fn]);

route('GET', '/api/boards', () => all('SELECT * FROM boards WHERE archived=0 ORDER BY pos'));
route('POST', '/api/boards', (_, b) => ({ id: createBoard(b) }));
route('GET', '/api/boards/(\\d+)', (m) => boardData(m[1]));
route('PATCH', '/api/boards/(\\d+)', (m, b) => { update('boards', m[1], pick(b, ['name', 'color', 'icon', 'archived'])); return {}; });
route('POST', '/api/boards/(\\d+)/duplicate', (m) => {
  const src = boardData(m[1]);
  const id = createBoard({ name: src.board.name + ' (Kopie)', color: src.board.color, icon: src.board.icon, template: '' });
  run('DELETE FROM columns WHERE board_id=?', id);
  for (const c of src.columns) {
    const cid = Number(run('INSERT INTO columns (board_id,name,pos,wip_limit) VALUES (?,?,?,?)', id, c.name, c.pos, c.wip_limit).lastInsertRowid);
    for (const k of src.cards.filter(k => k.column_id === c.id))
      run('INSERT INTO cards (column_id,title,description,priority,due_date,pos) VALUES (?,?,?,?,?,?)', cid, k.title, k.description, k.priority, k.due_date, k.pos);
  }
  return { id };
});
route('POST', '/api/columns', (_, b) => {
  const pos = (get('SELECT MAX(pos) m FROM columns WHERE board_id=?', b.board_id).m || 0) + 1;
  return { id: Number(run('INSERT INTO columns (board_id,name,pos) VALUES (?,?,?)', b.board_id, String(b.name || 'Neue Spalte'), pos).lastInsertRowid) };
});
route('PATCH', '/api/columns/(\\d+)', (m, b) => { update('columns', m[1], pick(b, ['name', 'wip_limit', 'collapsed', 'archived'])); return {}; });
route('POST', '/api/columns/(\\d+)/move', (m, b) => {
  const col = get('SELECT * FROM columns WHERE id=?', m[1]);
  const ids = all('SELECT id FROM columns WHERE board_id=? AND archived=0 AND id<>? ORDER BY pos', col.board_id, col.id).map(r => r.id);
  ids.splice(Math.max(0, Math.min(ids.length, b.index)), 0, col.id);
  renumber('columns', ids);
  return {};
});
route('POST', '/api/cards', (_, b) => {
  const pos = (get('SELECT MAX(pos) m FROM cards WHERE column_id=?', b.column_id).m || 0) + 1;
  return { id: Number(run('INSERT INTO cards (column_id,title,pos) VALUES (?,?,?)', b.column_id, String(b.title || 'Neue Karte'), pos).lastInsertRowid) };
});
route('PATCH', '/api/cards/(\\d+)', (m, b) => {
  const f = pick(b, ['title', 'description', 'priority', 'due_date', 'archived']);
  f.updated_at = now();
  update('cards', m[1], f);
  return {};
});
route('POST', '/api/cards/(\\d+)/move', (m, b) => {
  const ids = all('SELECT id FROM cards WHERE column_id=? AND archived=0 AND deleted_at IS NULL AND id<>? ORDER BY pos', b.column_id, m[1]).map(r => r.id);
  ids.splice(Math.max(0, Math.min(ids.length, b.index)), 0, Number(m[1]));
  run('UPDATE cards SET column_id=?, updated_at=? WHERE id=?', b.column_id, now(), m[1]);
  renumber('cards', ids);
  return {};
});
route('DELETE', '/api/cards/(\\d+)', (m) => { run('UPDATE cards SET deleted_at=? WHERE id=?', now(), m[1]); return {}; });
route('POST', '/api/cards/(\\d+)/restore', (m) => { run('UPDATE cards SET deleted_at=NULL WHERE id=?', m[1]); return {}; });
route('GET', '/api/search', (_, __, q) => {
  const term = (q.get('q') || '').trim();
  if (!term) return [];
  const like = '%' + term.replace(/[\\%_]/g, '\\$&') + '%';
  return all(`SELECT c.id, c.title, c.column_id, k.name AS column_name, k.board_id, b.name AS board_name
    FROM cards c JOIN columns k ON k.id=c.column_id JOIN boards b ON b.id=k.board_id
    WHERE c.deleted_at IS NULL AND (c.title LIKE ? ESCAPE '\\' OR c.description LIKE ? ESCAPE '\\')
    ORDER BY c.updated_at DESC LIMIT 50`, like, like);
});
route('GET', '/api/trash', () => all(`SELECT c.id, c.title, c.deleted_at, k.board_id FROM cards c JOIN columns k ON k.id=c.column_id WHERE c.deleted_at IS NOT NULL ORDER BY c.deleted_at DESC`));
route('POST', '/api/backup', () => ({ file: backup('-manuell') }));
route('GET', '/api/export', () => ({
  version: 1, exported_at: now(),
  boards: all('SELECT * FROM boards'), columns: all('SELECT * FROM columns'), cards: all('SELECT * FROM cards'),
}));

const TYPES = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.svg': 'image/svg+xml' };
const PUBLIC = path.join(__dirname, 'public');

http.createServer((req, res) => {
  const url = new URL(req.url, 'http://localhost');
  const send = (code, body, type = 'application/json; charset=utf-8') => {
    res.writeHead(code, { 'Content-Type': type, 'Cache-Control': 'no-store' });
    res.end(typeof body === 'string' || Buffer.isBuffer(body) ? body : JSON.stringify(body));
  };
  if (url.pathname.startsWith('/api/')) {
    // Nur Anfragen von der eigenen Seite zulassen (Schutz vor fremden Webseiten)
    const host = req.headers.host || '';
    if (!/^(localhost|127\.0\.0\.1)(:\d+)?$/.test(host)) return send(403, { error: 'verboten' });
    let raw = '';
    req.on('data', d => { raw += d; if (raw.length > 5e6) req.destroy(); });
    req.on('end', () => {
      for (const [method, re, fn] of routes) {
        const m = req.method === method && url.pathname.match(re);
        if (!m) continue;
        try {
          const out = fn(m, raw ? JSON.parse(raw) : {}, url.searchParams);
          return out === null ? send(404, { error: 'nicht gefunden' }) : send(200, out);
        } catch (e) { return send(500, { error: String(e.message || e) }); }
      }
      send(404, { error: 'nicht gefunden' });
    });
    return;
  }
  const file = path.join(PUBLIC, url.pathname === '/' ? 'index.html' : url.pathname);
  if (!file.startsWith(PUBLIC) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) return send(404, 'Nicht gefunden', 'text/plain; charset=utf-8');
  send(200, fs.readFileSync(file), TYPES[path.extname(file)] || 'application/octet-stream');
}).listen(PORT, '127.0.0.1', () => console.log(`Lokales Kanban läuft: http://localhost:${PORT}\nDaten: ${DATA}`));
