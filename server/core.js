// Boards, Spalten, Karten und alles, was direkt an Karten hängt
const S = require('./store');
const { route, bad } = require('./router');
const { all, get, run, tx } = S;

const now = () => new Date().toISOString();
const pad = n => String(n).padStart(2, '0');
const localDate = (d = new Date()) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const DATE_RE = /^\d{4}-\d{2}-\d{2}$/, TIME_RE = /^\d{2}:\d{2}$/;

const BUILTIN = {
  leer: { name: 'Leer', columns: [['Zu erledigen'], ['In Arbeit'], ['Erledigt', 1]] },
  webprojekt: { name: 'Webprojekt', columns: [['Briefing'], ['Design'], ['Umsetzung'], ['Test'], ['Fertig', 1]] },
  kunden: { name: 'Kunden-Pipeline', columns: [['Anfrage'], ['Angebot'], ['Beauftragt'], ['In Arbeit'], ['Abgerechnet', 1]] },
  persoenlich: { name: 'Persönlich', columns: [['Heute'], ['Diese Woche'], ['Später'], ['Erledigt', 1]] },
  redaktion: { name: 'Redaktionsplan', columns: [['Idee'], ['Entwurf'], ['Review'], ['Veröffentlicht', 1]] },
};
function templates() {
  const custom = all('SELECT * FROM templates ORDER BY name').map(t => ({ key: 't' + t.id, id: t.id, name: t.name, builtin: false, ...JSON.parse(t.data) }));
  return [...Object.entries(BUILTIN).map(([key, t]) => ({ key, name: t.name, builtin: true, columns: t.columns.map(([name, done]) => ({ name, done: done ? 1 : 0 })) })), ...custom];
}
function createBoard(b) {
  const pos = (get('SELECT MAX(pos) m FROM boards').m || 0) + 1;
  const tpl = templates().find(t => t.key === b.template) || templates()[0];
  const id = Number(run('INSERT INTO boards (name,color,icon,pos,swimlane) VALUES (?,?,?,?,?)', String(b.name || 'Neues Board').slice(0, 100), b.color || '#4f7cff', b.icon || '▦', pos, tpl.swimlane || 'none').lastInsertRowid);
  tpl.columns.forEach((c, i) => run('INSERT INTO columns (board_id,name,pos,wip_limit,done,sort) VALUES (?,?,?,?,?,?)', id, c.name, i + 1, c.wip_limit || null, c.done ? 1 : 0, c.sort || 'manual'));
  return id;
}

function seed() {
  if (S.db && !get('SELECT 1 x FROM boards') && !S.getSetting('seeded', false)) { createBoard({ name: 'Mein Board', template: 'leer' }); S.setSetting('seeded', true); }
}

// ----- Kartenauswahl mit Zählern -----
const CARD_SQL = `SELECT c.*, k.board_id, k.name AS column_name,
 (SELECT group_concat(label_id) FROM card_labels WHERE card_id=c.id) AS labels,
 (SELECT COUNT(*) FROM checklist_items i JOIN checklists l ON l.id=i.checklist_id WHERE l.card_id=c.id) AS cl_total,
 (SELECT COUNT(*) FROM checklist_items i JOIN checklists l ON l.id=i.checklist_id WHERE l.card_id=c.id AND i.done=1) AS cl_done,
 (SELECT COUNT(*) FROM comments WHERE card_id=c.id) AS n_comments,
 (SELECT COUNT(*) FROM attachments WHERE card_id=c.id) AS n_att,
 (SELECT COUNT(*) FROM cards s WHERE s.parent_id=c.id AND s.deleted_at IS NULL) AS sub_total,
 (SELECT COUNT(*) FROM cards s WHERE s.parent_id=c.id AND s.deleted_at IS NULL AND s.done_at IS NOT NULL) AS sub_done,
 (SELECT COALESCE(SUM(seconds),0) FROM time_entries WHERE card_id=c.id AND ended_at IS NOT NULL) AS tracked,
 (SELECT started_at FROM time_entries WHERE card_id=c.id AND ended_at IS NULL LIMIT 1) AS running_since,
 (SELECT COUNT(*) FROM card_links WHERE from_card=c.id OR to_card=c.id) AS n_links,
 (SELECT COUNT(*) FROM card_links x WHERE x.to_card=c.id AND x.type='blocks' AND (SELECT done_at FROM cards WHERE id=x.from_card) IS NULL) AS blocked_by,
 (SELECT title FROM cards p WHERE p.id=c.parent_id) AS parent_title
 FROM cards c JOIN columns k ON k.id=c.column_id`;
const cardById = id => get(CARD_SQL + ' WHERE c.id=?', id);
const LIVE = ' c.archived=0 AND c.deleted_at IS NULL AND k.archived=0 ';

function boardData(id) {
  const board = get('SELECT * FROM boards WHERE id=?', id);
  if (!board) return null;
  return {
    board,
    columns: all('SELECT * FROM columns WHERE board_id=? AND archived=0 ORDER BY pos', id),
    cards: all(CARD_SQL + ` WHERE k.board_id=? AND ${LIVE} ORDER BY c.pos`, id),
    labels: all('SELECT * FROM labels ORDER BY name'),
    people: people(), customers: all("SELECT DISTINCT customer v FROM cards WHERE customer<>'' ORDER BY customer").map(r => r.v),
    views: all('SELECT * FROM views WHERE board_id=? ORDER BY name', id).map(v => ({ ...v, filter: JSON.parse(v.filter || '{}') })),
  };
}
const people = () => [...new Set([...all("SELECT DISTINCT assignee v FROM cards WHERE assignee<>''").map(r => r.v), ...all('SELECT name v FROM users').map(r => r.v)])].sort();

function log(cardId, user, text) { run('INSERT INTO activity (card_id,user,text,at) VALUES (?,?,?,?)', cardId, user, text, now()); }
function renumber(table, ids) { ids.forEach((id, i) => run(`UPDATE ${table} SET pos=? WHERE id=?`, i + 1, id)); }

// ----- Spaltenwechsel: Verlauf, erledigt, Wiederholung -----
function addInterval(dateStr, kind, n) {
  const [y, m, d] = dateStr.split('-').map(Number), dt = new Date(y, m - 1, d);
  if (kind === 'daily') dt.setDate(dt.getDate() + n);
  else if (kind === 'weekly') dt.setDate(dt.getDate() + 7 * n);
  else { const day = dt.getDate(); dt.setDate(1); dt.setMonth(dt.getMonth() + n); dt.setDate(Math.min(day, new Date(dt.getFullYear(), dt.getMonth() + 1, 0).getDate())); }
  return localDate(dt);
}
function spawnRecurring(card, user) {
  if (!card.recur || card.recur_spawned) return;
  const col = get('SELECT * FROM columns WHERE id=?', card.column_id);
  const first = get('SELECT id FROM columns WHERE board_id=? AND archived=0 AND done=0 ORDER BY pos LIMIT 1', col.board_id) || { id: col.id };
  const step = Math.max(1, card.recur_every || 1), today = localDate();
  let base = card.due_date || card.start_date || today, next = addInterval(base, card.recur, step), guard = 0;
  while (next < today && guard++ < 1000) next = addInterval(next, card.recur, step);
  const shift = (d) => d && card.due_date ? addInterval(d, 'daily', Math.round((new Date(next) - new Date(card.due_date)) / 864e5)) : d;
  const pos = (get('SELECT MAX(pos) m FROM cards WHERE column_id=?', first.id).m || 0) + 1;
  const nid = Number(run(`INSERT INTO cards (column_id,title,description,priority,due_date,due_time,start_date,pos,assignee,customer,crm_ref,est_minutes,recur,recur_every,created_at,updated_at,created_by,parent_id)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`, first.id, card.title, card.description, card.priority, next, card.due_time, card.start_date ? shift(card.start_date) : null, pos,
    card.assignee, card.customer, card.crm_ref, card.est_minutes, card.recur, card.recur_every, now(), now(), user, card.parent_id).lastInsertRowid);
  run('INSERT INTO card_history (card_id,column_id,entered_at) VALUES (?,?,?)', nid, first.id, now());
  run('INSERT INTO card_labels (card_id,label_id) SELECT ?,label_id FROM card_labels WHERE card_id=?', nid, card.id);
  for (const l of all('SELECT * FROM checklists WHERE card_id=? ORDER BY pos', card.id)) {
    const lid = Number(run('INSERT INTO checklists (card_id,title,pos) VALUES (?,?,?)', nid, l.title, l.pos).lastInsertRowid);
    run('INSERT INTO checklist_items (checklist_id,text,done,pos) SELECT ?,text,0,pos FROM checklist_items WHERE checklist_id=?', lid, l.id);
  }
  log(nid, user, 'Automatisch aus wiederkehrender Karte erstellt'); log(card.id, user, `Wiederkehrende Karte: Nachfolger #${nid} fällig am ${next}`);
  run('UPDATE cards SET recur_spawned=1 WHERE id=?', card.id);
}
function columnChanged(cardId, colId, user) {
  const card = get('SELECT * FROM cards WHERE id=?', cardId), col = get('SELECT * FROM columns WHERE id=?', colId), t = now();
  run('UPDATE card_history SET left_at=? WHERE card_id=? AND left_at IS NULL', t, cardId);
  run('INSERT INTO card_history (card_id,column_id,entered_at) VALUES (?,?,?)', cardId, colId, t);
  if (col.done && !card.done_at) { run('UPDATE cards SET done_at=? WHERE id=?', t, cardId); spawnRecurring({ ...card, column_id: colId }, user); }
  else if (!col.done && card.done_at) run('UPDATE cards SET done_at=NULL WHERE id=?', cardId);
}

// ----- Eingabe prüfen -----
const str = (v, max = 20000) => String(v ?? '').slice(0, max);
const date = v => (v && DATE_RE.test(v) ? v : null);
const CARD_FIELDS = {
  title: v => str(v, 500).trim() || null, description: v => str(v, 200000), priority: v => Math.max(0, Math.min(3, parseInt(v, 10) || 0)),
  start_date: date, due_date: date, due_time: v => (v && TIME_RE.test(v) ? v : null), assignee: v => str(v, 100).trim(), customer: v => str(v, 100).trim(),
  crm_ref: v => str(v, 200).trim(), est_minutes: v => (parseInt(v, 10) > 0 ? parseInt(v, 10) : null), recur: v => (['daily', 'weekly', 'monthly'].includes(v) ? v : ''),
  recur_every: v => Math.max(1, parseInt(v, 10) || 1), archived: v => (v ? 1 : 0), parent_id: v => (v ? Number(v) : null),
};
const LABELS = { title: 'Titel', description: 'Beschreibung', priority: 'Priorität', start_date: 'Startdatum', due_date: 'Fälligkeit', due_time: 'Uhrzeit', assignee: 'Person', customer: 'Kunde', crm_ref: 'CRM-Referenz', est_minutes: 'Zeitschätzung', recur: 'Wiederholung', recur_every: 'Intervall', archived: 'Archiv', parent_id: 'Oberkarte' };
const PRI = ['Keine', 'Niedrig', 'Hoch', 'Dringend'];

function patchCard(id, body, user) {
  const old = get('SELECT * FROM cards WHERE id=?', id); if (!old) bad('Karte nicht gefunden', 404);
  const f = {};
  for (const k of Object.keys(CARD_FIELDS)) if (k in body) { const v = CARD_FIELDS[k](body[k]); if (k === 'title' && v === null) continue; f[k] = v; }
  if ('recur' in f || 'due_date' in f) f.recur_spawned = 0;
  f.updated_at = now();
  const keys = Object.keys(f);
  run(`UPDATE cards SET ${keys.map(k => k + '=?').join(',')} WHERE id=?`, ...keys.map(k => f[k]), id);
  for (const k of keys) {
    if (!LABELS[k] || String(old[k] ?? '') === String(f[k] ?? '')) continue;
    if (k === 'description') log(id, user, 'Beschreibung geändert');
    else if (k === 'archived') log(id, user, f[k] ? 'Archiviert' : 'Aus dem Archiv geholt');
    else if (k === 'priority') log(id, user, `Priorität: ${PRI[old.priority]} → ${PRI[f.priority]}`);
    else log(id, user, `${LABELS[k]}: ${old[k] || '–'} → ${f[k] || '–'}`);
  }
}
function newCard(colId, title, user, extra = {}) {
  const col = get('SELECT * FROM columns WHERE id=?', colId); if (!col) bad('Spalte nicht gefunden', 404);
  const pos = (get('SELECT MAX(pos) m FROM cards WHERE column_id=?', colId).m || 0) + 1;
  const id = Number(run('INSERT INTO cards (column_id,title,pos,created_at,updated_at,created_by,done_at,parent_id) VALUES (?,?,?,?,?,?,?,?)', colId, str(title, 500).trim() || 'Neue Karte', pos, now(), now(), user, col.done ? now() : null, extra.parent_id || null).lastInsertRowid);
  run('INSERT INTO card_history (card_id,column_id,entered_at) VALUES (?,?,?)', id, colId, now());
  log(id, user, 'Erstellt');
  const rest = { ...extra }; delete rest.parent_id;
  if (Object.keys(rest).length) patchCard(id, rest, user);
  return id;
}
function copyCard(id, user, targetCol) {
  const c = get('SELECT * FROM cards WHERE id=?', id);
  const nid = newCard(targetCol || c.column_id, c.title + ' (Kopie)', user, { description: c.description, priority: c.priority, start_date: c.start_date, due_date: c.due_date, due_time: c.due_time, assignee: c.assignee, customer: c.customer, crm_ref: c.crm_ref, est_minutes: c.est_minutes, recur: c.recur, recur_every: c.recur_every, parent_id: c.parent_id });
  run('INSERT INTO card_labels (card_id,label_id) SELECT ?,label_id FROM card_labels WHERE card_id=?', nid, id);
  for (const l of all('SELECT * FROM checklists WHERE card_id=? ORDER BY pos', id)) {
    const lid = Number(run('INSERT INTO checklists (card_id,title,pos) VALUES (?,?,?)', nid, l.title, l.pos).lastInsertRowid);
    run('INSERT INTO checklist_items (checklist_id,text,done,pos) SELECT ?,text,done,pos FROM checklist_items WHERE checklist_id=?', lid, l.id);
  }
  if (!targetCol) { // direkt hinter das Original setzen
    const ids = all('SELECT id FROM cards WHERE column_id=? AND archived=0 AND deleted_at IS NULL AND id<>? ORDER BY pos', c.column_id, nid).map(r => r.id);
    ids.splice(ids.indexOf(id) + 1, 0, nid); renumber('cards', ids);
  }
  log(nid, user, `Kopie von #${id}`);
  return nid;
}
function moveCard(id, b, user) {
  const card = get('SELECT * FROM cards WHERE id=?', id); if (!card) bad('Karte nicht gefunden', 404);
  const target = get('SELECT * FROM columns WHERE id=?', b.column_id); if (!target) bad('Spalte nicht gefunden', 404);
  const ids = all('SELECT id FROM cards WHERE column_id=? AND archived=0 AND deleted_at IS NULL AND id<>? ORDER BY pos', target.id, id).map(r => r.id);
  let idx = ids.length;
  if (b.before_id != null && ids.includes(+b.before_id)) idx = ids.indexOf(+b.before_id);
  else if (b.after_id != null && ids.includes(+b.after_id)) idx = ids.indexOf(+b.after_id) + 1;
  else if (Number.isInteger(b.index)) idx = Math.max(0, Math.min(ids.length, b.index));
  ids.splice(idx, 0, id);
  run('UPDATE cards SET column_id=?, updated_at=? WHERE id=?', target.id, now(), id);
  renumber('cards', ids);
  if (card.column_id !== target.id) {
    columnChanged(id, target.id, user);
    const from = get('SELECT name FROM columns WHERE id=?', card.column_id);
    log(id, user, `Verschoben: ${from?.name} → ${target.name}`);
  }
  if (b.set) { const f = {}; for (const k of ['priority', 'assignee', 'customer']) if (k in b.set) f[k] = b.set[k]; if (Object.keys(f).length) patchCard(id, f, user); }
}

// ===== Boards =====
route('GET', '/api/boards', () => all('SELECT * FROM boards WHERE archived=0 ORDER BY pos'));
route('POST', '/api/boards', c => ({ id: tx(() => createBoard(c.body)) }));
route('GET', '/api/boards/(\\d+)', c => boardData(+c.m[1]) || bad('Board nicht gefunden', 404));
route('PATCH', '/api/boards/(\\d+)', c => {
  const f = {}, b = c.body;
  if ('name' in b) f.name = str(b.name, 100).trim() || 'Board';
  if ('color' in b) f.color = str(b.color, 20); if ('icon' in b) f.icon = str(b.icon, 4);
  if ('archived' in b) f.archived = b.archived ? 1 : 0;
  if ('swimlane' in b && ['none', 'assignee', 'priority', 'customer'].includes(b.swimlane)) f.swimlane = b.swimlane;
  const keys = Object.keys(f);
  if (keys.length) run(`UPDATE boards SET ${keys.map(k => k + '=?').join(',')} WHERE id=?`, ...keys.map(k => f[k]), c.m[1]);
  return {};
});
route('DELETE', '/api/boards/(\\d+)', c => {
  const files = all('SELECT a.* FROM attachments a JOIN cards x ON x.id=a.card_id JOIN columns k ON k.id=x.column_id WHERE k.board_id=?', c.m[1]);
  run('DELETE FROM boards WHERE id=? AND archived=1', c.m[1]); files.forEach(S.removeFile); return {};
});
route('POST', '/api/boards/(\\d+)/duplicate', c => tx(() => {
  const src = boardData(+c.m[1]);
  const id = Number(run('INSERT INTO boards (name,color,icon,pos,swimlane) VALUES (?,?,?,?,?)', src.board.name + ' (Kopie)', src.board.color, src.board.icon, (get('SELECT MAX(pos) m FROM boards').m || 0) + 1, src.board.swimlane).lastInsertRowid);
  for (const col of src.columns) {
    const cid = Number(run('INSERT INTO columns (board_id,name,pos,wip_limit,done,sort) VALUES (?,?,?,?,?,?)', id, col.name, col.pos, col.wip_limit, col.done, col.sort).lastInsertRowid);
    for (const k of src.cards.filter(k => k.column_id === col.id)) { const nid = copyCard(k.id, c.user, cid); run('UPDATE cards SET title=? WHERE id=?', k.title, nid); }
  }
  return { id };
}));
route('POST', '/api/boards/(\\d+)/save-template', c => {
  const d = boardData(+c.m[1]);
  const data = { swimlane: d.board.swimlane, columns: d.columns.map(k => ({ name: k.name, wip_limit: k.wip_limit, done: k.done, sort: k.sort })) };
  return { id: Number(run('INSERT INTO templates (name,data) VALUES (?,?)', str(c.body.name, 100).trim() || d.board.name, JSON.stringify(data)).lastInsertRowid) };
});
route('GET', '/api/templates', () => templates());
route('DELETE', '/api/templates/(\\d+)', c => { run('DELETE FROM templates WHERE id=?', c.m[1]); return {}; });

// ===== Spalten =====
route('POST', '/api/columns', c => ({ id: Number(run('INSERT INTO columns (board_id,name,pos) VALUES (?,?,?)', c.body.board_id, str(c.body.name, 100).trim() || 'Neue Spalte', (get('SELECT MAX(pos) m FROM columns WHERE board_id=?', c.body.board_id).m || 0) + 1).lastInsertRowid) }));
route('PATCH', '/api/columns/(\\d+)', c => {
  const b = c.body, f = {};
  if ('name' in b) f.name = str(b.name, 100).trim() || 'Spalte';
  if ('wip_limit' in b) f.wip_limit = parseInt(b.wip_limit, 10) > 0 ? parseInt(b.wip_limit, 10) : null;
  for (const k of ['collapsed', 'archived', 'done']) if (k in b) f[k] = b[k] ? 1 : 0;
  if ('sort' in b && ['manual', 'date', 'priority'].includes(b.sort)) f.sort = b.sort;
  const keys = Object.keys(f);
  if (keys.length) run(`UPDATE columns SET ${keys.map(k => k + '=?').join(',')} WHERE id=?`, ...keys.map(k => f[k]), c.m[1]);
  return {};
});
route('DELETE', '/api/columns/(\\d+)', c => { run('DELETE FROM columns WHERE id=? AND archived=1', c.m[1]); return {}; });
route('POST', '/api/columns/(\\d+)/move', c => {
  const col = get('SELECT * FROM columns WHERE id=?', c.m[1]);
  const ids = all('SELECT id FROM columns WHERE board_id=? AND archived=0 AND id<>? ORDER BY pos', col.board_id, col.id).map(r => r.id);
  let idx = ids.length;
  if (c.body.before_id != null && ids.includes(+c.body.before_id)) idx = ids.indexOf(+c.body.before_id);
  else if (Number.isInteger(c.body.index)) idx = Math.max(0, Math.min(ids.length, c.body.index));
  ids.splice(idx, 0, col.id); renumber('columns', ids); return {};
});

// ===== Karten =====
route('POST', '/api/cards', c => ({ id: tx(() => newCard(c.body.column_id, c.body.title, c.user, c.body.fields || {})) }));
route('GET', '/api/cards/(\\d+)', c => cardDetail(+c.m[1]));
route('PATCH', '/api/cards/(\\d+)', c => { tx(() => patchCard(+c.m[1], c.body, c.user)); return {}; });
route('POST', '/api/cards/(\\d+)/move', c => { tx(() => moveCard(+c.m[1], c.body, c.user)); return {}; });
route('POST', '/api/cards/(\\d+)/copy', c => ({ id: tx(() => copyCard(+c.m[1], c.user)) }));
route('POST', '/api/cards/(\\d+)/done', c => tx(() => {
  const card = get('SELECT c.*, k.board_id FROM cards c JOIN columns k ON k.id=c.column_id WHERE c.id=?', c.m[1]);
  const target = c.body.done ? get('SELECT id FROM columns WHERE board_id=? AND archived=0 AND done=1 ORDER BY pos LIMIT 1', card.board_id)
    : get('SELECT id FROM columns WHERE board_id=? AND archived=0 AND done=0 ORDER BY pos LIMIT 1', card.board_id);
  if (target) moveCard(card.id, { column_id: target.id }, c.user);
  else { run('UPDATE cards SET done_at=? WHERE id=?', c.body.done ? now() : null, card.id); if (c.body.done) spawnRecurring(card, c.user); }
  log(card.id, c.user, c.body.done ? 'Als erledigt markiert' : 'Wieder geöffnet');
  return {};
}));
route('DELETE', '/api/cards/(\\d+)', c => { run('UPDATE cards SET deleted_at=? WHERE id=?', now(), c.m[1]); log(+c.m[1], c.user, 'In den Papierkorb gelegt'); return {}; });
route('POST', '/api/cards/(\\d+)/restore', c => { run('UPDATE cards SET deleted_at=NULL WHERE id=?', c.m[1]); log(+c.m[1], c.user, 'Wiederhergestellt'); return {}; });
route('POST', '/api/cards/(\\d+)/purge', c => { all('SELECT * FROM attachments WHERE card_id=?', c.m[1]).forEach(S.removeFile); run('DELETE FROM cards WHERE id=? AND deleted_at IS NOT NULL', c.m[1]); return {}; });
route('POST', '/api/cards/bulk', c => tx(() => {
  const { ids = [], op, value } = c.body;
  for (const id of ids.map(Number)) {
    if (op === 'move') moveCard(id, { column_id: value }, c.user);
    else if (op === 'archive') patchCard(id, { archived: 1 }, c.user);
    else if (op === 'delete') { run('UPDATE cards SET deleted_at=? WHERE id=?', now(), id); log(id, c.user, 'In den Papierkorb gelegt'); }
    else if (op === 'priority') patchCard(id, { priority: value }, c.user);
    else if (op === 'assignee') patchCard(id, { assignee: value }, c.user);
    else if (op === 'label_add') run('INSERT OR IGNORE INTO card_labels (card_id,label_id) VALUES (?,?)', id, value);
    else if (op === 'label_remove') run('DELETE FROM card_labels WHERE card_id=? AND label_id=?', id, value);
  }
  return {};
}));
route('GET', '/api/trash', () => all(`SELECT c.id,c.title,c.deleted_at,k.board_id,b.name board_name FROM cards c JOIN columns k ON k.id=c.column_id JOIN boards b ON b.id=k.board_id WHERE c.deleted_at IS NOT NULL ORDER BY c.deleted_at DESC`));
route('POST', '/api/trash/empty', () => {
  for (const r of all('SELECT id FROM cards WHERE deleted_at IS NOT NULL')) { all('SELECT * FROM attachments WHERE card_id=?', r.id).forEach(S.removeFile); run('DELETE FROM cards WHERE id=?', r.id); }
  return {};
});
route('GET', '/api/archive', () => ({
  boards: all('SELECT id,name FROM boards WHERE archived=1'),
  columns: all('SELECT k.id,k.name,b.name board_name FROM columns k JOIN boards b ON b.id=k.board_id WHERE k.archived=1 AND b.archived=0'),
  cards: all(`SELECT c.id,c.title,b.name board_name FROM cards c JOIN columns k ON k.id=c.column_id JOIN boards b ON b.id=k.board_id WHERE c.archived=1 AND c.deleted_at IS NULL ORDER BY c.updated_at DESC`),
}));

function cardDetail(id) {
  const card = cardById(id); if (!card) bad('Karte nicht gefunden', 404);
  const checklists = all('SELECT * FROM checklists WHERE card_id=? ORDER BY pos', id).map(l => ({ ...l, items: all('SELECT * FROM checklist_items WHERE checklist_id=? ORDER BY pos', l.id) }));
  const links = [
    ...all('SELECT l.id,l.type,l.to_card other FROM card_links l WHERE l.from_card=?', id).map(l => ({ ...l, dir: 'out' })),
    ...all('SELECT l.id,l.type,l.from_card other FROM card_links l WHERE l.to_card=?', id).map(l => ({ ...l, dir: 'in' })),
  ].map(l => ({ ...l, card: get('SELECT c.id,c.title,c.done_at,b.name board_name FROM cards c JOIN columns k ON k.id=c.column_id JOIN boards b ON b.id=k.board_id WHERE c.id=?', l.other) })).filter(l => l.card);
  return {
    card, labels: all('SELECT label_id FROM card_labels WHERE card_id=?', id).map(r => r.label_id), checklists,
    comments: all('SELECT * FROM comments WHERE card_id=? ORDER BY created_at', id),
    attachments: all('SELECT id,name,mime,size,created_at FROM attachments WHERE card_id=? ORDER BY id', id), links,
    subtasks: all('SELECT c.id,c.title,c.done_at,k.name column_name FROM cards c JOIN columns k ON k.id=c.column_id WHERE c.parent_id=? AND c.deleted_at IS NULL ORDER BY c.pos', id),
    time: all('SELECT * FROM time_entries WHERE card_id=? ORDER BY started_at DESC', id),
    activity: all('SELECT * FROM activity WHERE card_id=? ORDER BY id DESC LIMIT 200', id),
    board: get('SELECT b.id,b.name FROM boards b JOIN columns k ON k.board_id=b.id WHERE k.id=?', card.column_id),
  };
}

// ===== Labels =====
route('GET', '/api/labels', () => all('SELECT * FROM labels ORDER BY name'));
route('POST', '/api/labels', c => ({ id: Number(run('INSERT INTO labels (name,color) VALUES (?,?)', str(c.body.name, 50).trim() || 'Label', str(c.body.color || '#6b7285', 20)).lastInsertRowid) }));
route('PATCH', '/api/labels/(\\d+)', c => { if ('name' in c.body) run('UPDATE labels SET name=? WHERE id=?', str(c.body.name, 50), c.m[1]); if ('color' in c.body) run('UPDATE labels SET color=? WHERE id=?', str(c.body.color, 20), c.m[1]); return {}; });
route('DELETE', '/api/labels/(\\d+)', c => { run('DELETE FROM labels WHERE id=?', c.m[1]); return {}; });
route('PUT', '/api/cards/(\\d+)/labels', c => tx(() => {
  const id = +c.m[1], want = (c.body.ids || []).map(Number), have = all('SELECT label_id FROM card_labels WHERE card_id=?', id).map(r => r.label_id);
  for (const l of want.filter(x => !have.includes(x))) { run('INSERT OR IGNORE INTO card_labels (card_id,label_id) VALUES (?,?)', id, l); log(id, c.user, `Label hinzugefügt: ${get('SELECT name FROM labels WHERE id=?', l)?.name}`); }
  for (const l of have.filter(x => !want.includes(x))) { run('DELETE FROM card_labels WHERE card_id=? AND label_id=?', id, l); log(id, c.user, `Label entfernt: ${get('SELECT name FROM labels WHERE id=?', l)?.name}`); }
  run('UPDATE cards SET updated_at=? WHERE id=?', now(), id); return {};
}));

// ===== Checklisten =====
route('POST', '/api/cards/(\\d+)/checklists', c => {
  const pos = (get('SELECT MAX(pos) m FROM checklists WHERE card_id=?', c.m[1]).m || 0) + 1;
  return { id: Number(run('INSERT INTO checklists (card_id,title,pos) VALUES (?,?,?)', c.m[1], str(c.body.title, 200).trim() || 'Checkliste', pos).lastInsertRowid) };
});
route('PATCH', '/api/checklists/(\\d+)', c => { run('UPDATE checklists SET title=? WHERE id=?', str(c.body.title, 200), c.m[1]); return {}; });
route('DELETE', '/api/checklists/(\\d+)', c => { run('DELETE FROM checklists WHERE id=?', c.m[1]); return {}; });
route('POST', '/api/checklists/(\\d+)/items', c => {
  const pos = (get('SELECT MAX(pos) m FROM checklist_items WHERE checklist_id=?', c.m[1]).m || 0) + 1;
  return { id: Number(run('INSERT INTO checklist_items (checklist_id,text,pos) VALUES (?,?,?)', c.m[1], str(c.body.text, 500).trim() || 'Punkt', pos).lastInsertRowid) };
});
route('PATCH', '/api/items/(\\d+)', c => {
  if ('text' in c.body) run('UPDATE checklist_items SET text=? WHERE id=?', str(c.body.text, 500), c.m[1]);
  if ('done' in c.body) run('UPDATE checklist_items SET done=? WHERE id=?', c.body.done ? 1 : 0, c.m[1]);
  return {};
});
route('DELETE', '/api/items/(\\d+)', c => { run('DELETE FROM checklist_items WHERE id=?', c.m[1]); return {}; });
route('POST', '/api/items/(\\d+)/move', c => tx(() => {
  const it = get('SELECT * FROM checklist_items WHERE id=?', c.m[1]), target = +c.body.checklist_id || it.checklist_id;
  const ids = all('SELECT id FROM checklist_items WHERE checklist_id=? AND id<>? ORDER BY pos', target, it.id).map(r => r.id);
  let idx = ids.length; if (c.body.before_id != null && ids.includes(+c.body.before_id)) idx = ids.indexOf(+c.body.before_id);
  ids.splice(idx, 0, it.id); run('UPDATE checklist_items SET checklist_id=? WHERE id=?', target, it.id); renumber('checklist_items', ids); return {};
}));

// ===== Kommentare =====
route('POST', '/api/cards/(\\d+)/comments', c => {
  const id = Number(run('INSERT INTO comments (card_id,user,body,created_at) VALUES (?,?,?,?)', c.m[1], c.user, str(c.body.body, 20000), now()).lastInsertRowid);
  log(+c.m[1], c.user, 'Kommentar hinzugefügt'); return { id };
});
route('PATCH', '/api/comments/(\\d+)', c => { run('UPDATE comments SET body=?, edited_at=? WHERE id=?', str(c.body.body, 20000), now(), c.m[1]); return {}; });
route('DELETE', '/api/comments/(\\d+)', c => { run('DELETE FROM comments WHERE id=?', c.m[1]); return {}; });

// ===== Verknüpfungen =====
route('POST', '/api/cards/(\\d+)/links', c => {
  const type = ['blocks', 'part_of', 'duplicate'].includes(c.body.type) ? c.body.type : 'part_of', to = +c.body.to_card, from = +c.m[1];
  if (!get('SELECT 1 x FROM cards WHERE id=?', to) || to === from) bad('Ungültige Zielkarte');
  const id = Number(run('INSERT INTO card_links (from_card,to_card,type) VALUES (?,?,?)', from, to, type).lastInsertRowid);
  log(from, c.user, `Verknüpfung: ${type} #${to}`); return { id };
});
route('DELETE', '/api/links/(\\d+)', c => { run('DELETE FROM card_links WHERE id=?', c.m[1]); return {}; });

// ===== Anhänge =====
route('POST', '/api/cards/(\\d+)/attachments', c => {
  const buf = c.raw; if (!buf.length) bad('Leere Datei');
  const name = decodeURIComponent(c.req.headers['x-filename'] || 'datei').replace(/[\\/]/g, '_').slice(0, 200);
  const st = S.saveFile(buf);
  const id = Number(run('INSERT INTO attachments (card_id,name,mime,size,file,enc,created_at) VALUES (?,?,?,?,?,?,?)', c.m[1], name, c.req.headers['content-type'] || 'application/octet-stream', buf.length, st.file, st.enc, now()).lastInsertRowid);
  log(+c.m[1], c.user, `Anhang hinzugefügt: ${name}`); return { id };
}, { raw: true });
route('GET', '/api/attachments/(\\d+)', c => {
  const a = get('SELECT * FROM attachments WHERE id=?', c.m[1]); if (!a) bad('Nicht gefunden', 404);
  const safe = /^(image\/(png|jpe?g|gif|webp|bmp)|application\/pdf|text\/plain|audio\/|video\/)/.test(a.mime);
  c.sendRaw(S.readFile(a), safe ? a.mime : 'application/octet-stream', { 'Content-Disposition': `${c.q.get('dl') || !safe ? 'attachment' : 'inline'}; filename*=UTF-8''${encodeURIComponent(a.name)}` });
});
route('DELETE', '/api/attachments/(\\d+)', c => { const a = get('SELECT * FROM attachments WHERE id=?', c.m[1]); if (a) { S.removeFile(a); run('DELETE FROM attachments WHERE id=?', a.id); log(a.card_id, c.user, `Anhang entfernt: ${a.name}`); } return {}; });

// ===== Zeiterfassung =====
function stopTimer(user) {
  for (const e of all('SELECT * FROM time_entries WHERE ended_at IS NULL AND user=?', user)) {
    const t = now(); run('UPDATE time_entries SET ended_at=?, seconds=? WHERE id=?', t, Math.max(0, Math.round((new Date(t) - new Date(e.started_at)) / 1000)), e.id);
  }
}
route('POST', '/api/cards/(\\d+)/timer/start', c => tx(() => { stopTimer(c.user); run('INSERT INTO time_entries (card_id,user,started_at) VALUES (?,?,?)', c.m[1], c.user, now()); log(+c.m[1], c.user, 'Zeitnehmer gestartet'); return {}; }));
route('POST', '/api/timer/stop', c => { stopTimer(c.user); return {}; });
route('GET', '/api/timer', c => get('SELECT e.*, c.title FROM time_entries e JOIN cards c ON c.id=e.card_id WHERE e.ended_at IS NULL AND e.user=?', c.user) || null);
route('POST', '/api/cards/(\\d+)/time', c => {
  const min = parseFloat(String(c.body.minutes).replace(',', '.')); if (!(min > 0)) bad('Minuten angeben');
  const d = date(c.body.date) || localDate(), start = new Date(d + 'T12:00:00').toISOString();
  run('INSERT INTO time_entries (card_id,user,started_at,ended_at,seconds,note) VALUES (?,?,?,?,?,?)', c.m[1], c.user, start, start, Math.round(min * 60), str(c.body.note, 300));
  log(+c.m[1], c.user, `Zeit nachgetragen: ${min} min`); return {};
});
route('PATCH', '/api/time/(\\d+)', c => { if ('note' in c.body) run('UPDATE time_entries SET note=? WHERE id=?', str(c.body.note, 300), c.m[1]); return {}; });
route('DELETE', '/api/time/(\\d+)', c => { run('DELETE FROM time_entries WHERE id=?', c.m[1]); return {}; });

// ===== Gespeicherte Ansichten =====
route('POST', '/api/boards/(\\d+)/views', c => ({ id: Number(run('INSERT INTO views (board_id,name,view,filter) VALUES (?,?,?,?)', c.m[1], str(c.body.name, 100) || 'Ansicht', str(c.body.view, 20) || 'board', JSON.stringify(c.body.filter || {})).lastInsertRowid) }));
route('DELETE', '/api/views/(\\d+)', c => { run('DELETE FROM views WHERE id=?', c.m[1]); return {}; });

module.exports = { seed, createBoard, boardData, newCard, patchCard, moveCard, copyCard, columnChanged, log, now, localDate, CARD_SQL, LIVE, cardById, str, date, renumber, stopTimer, templates };
