// Export und Import: JSON (vollständig), CSV, Markdown, Trello
const S = require('./store');
const { route, bad } = require('./router');
const { all, get, run, tx } = S;
const core = require('./core');
const { toCsv, num } = require('./stats');
const { str, now } = core;

const PRI = ['', 'Niedrig', 'Hoch', 'Dringend'];
const fmt = d => d ? d.split('-').reverse().join('.') : '';

function cardFull(c, files) {
  const lbl = all('SELECT l.name,l.color FROM card_labels cl JOIN labels l ON l.id=cl.label_id WHERE cl.card_id=?', c.id);
  return {
    ref: c.id, parent_ref: c.parent_id, title: c.title, description: c.description, priority: c.priority, start_date: c.start_date, due_date: c.due_date, due_time: c.due_time,
    assignee: c.assignee, customer: c.customer, crm_ref: c.crm_ref, est_minutes: c.est_minutes, recur: c.recur, recur_every: c.recur_every, done_at: c.done_at,
    created_at: c.created_at, archived: c.archived, labels: lbl,
    checklists: all('SELECT * FROM checklists WHERE card_id=? ORDER BY pos', c.id).map(l => ({ title: l.title, items: all('SELECT text,done FROM checklist_items WHERE checklist_id=? ORDER BY pos', l.id) })),
    comments: all('SELECT user,body,created_at,edited_at FROM comments WHERE card_id=? ORDER BY created_at', c.id),
    time: all('SELECT user,started_at,ended_at,seconds,note FROM time_entries WHERE card_id=? AND ended_at IS NOT NULL', c.id),
    links: all('SELECT type,to_card FROM card_links WHERE from_card=?', c.id),
    attachments: all('SELECT * FROM attachments WHERE card_id=?', c.id).map(a => ({ name: a.name, mime: a.mime, ...(files ? { data_b64: S.readFile(a).toString('base64') } : {}) })),
  };
}
function exportBoard(id, files) {
  const b = get('SELECT * FROM boards WHERE id=?', id);
  return { name: b.name, color: b.color, icon: b.icon, swimlane: b.swimlane, archived: b.archived,
    columns: all('SELECT * FROM columns WHERE board_id=? ORDER BY pos', id).map(k => ({ name: k.name, wip_limit: k.wip_limit, done: k.done, sort: k.sort, archived: k.archived,
      cards: all('SELECT * FROM cards WHERE column_id=? AND deleted_at IS NULL ORDER BY pos', k.id).map(c => cardFull(c, files)) })) };
}
const dl = (c, buf, type, name) => c.sendRaw(Buffer.from(buf), type, { 'Content-Disposition': `attachment; filename="${name}"` });
const stamp = () => core.localDate();

function markdown(b) {
  let out = `# ${b.name}\n\n`;
  for (const k of b.columns.filter(k => !k.archived)) {
    out += `## ${k.name}\n\n`;
    for (const c of k.cards.filter(c => !c.archived)) {
      const meta = [c.due_date && 'fällig ' + fmt(c.due_date), c.priority && 'Priorität ' + PRI[c.priority], c.assignee && '@' + c.assignee, c.customer, ...c.labels.map(l => '#' + l.name.replace(/\s+/g, '-'))].filter(Boolean);
      out += `- [${c.done_at ? 'x' : ' '}] **${c.title}**${meta.length ? ' — ' + meta.join(' · ') : ''}\n`;
      if (c.description) out += c.description.split('\n').map(l => '  ' + l).join('\n') + '\n';
      for (const l of c.checklists) { out += `  - ${l.title}\n`; for (const i of l.items) out += `    - [${i.done ? 'x' : ' '}] ${i.text}\n`; }
    }
    out += '\n';
  }
  return out;
}
function csvOfBoards(ids) {
  const head = ['Board', 'Spalte', 'Titel', 'Beschreibung', 'Priorität', 'Start', 'Fällig', 'Labels', 'Person', 'Kunde', 'CRM-Referenz', 'Schätzung (min)', 'Erfasst (min)', 'Checkliste', 'Erstellt', 'Erledigt'];
  const rows = [];
  for (const id of ids) {
    const b = get('SELECT name FROM boards WHERE id=?', id);
    for (const k of all('SELECT * FROM columns WHERE board_id=? ORDER BY pos', id))
      for (const c of all(core.CARD_SQL + ' WHERE c.column_id=? AND c.deleted_at IS NULL AND c.archived=0 ORDER BY c.pos', k.id)) {
        const l = all('SELECT l.name FROM card_labels cl JOIN labels l ON l.id=cl.label_id WHERE cl.card_id=?', c.id).map(x => x.name).join(', ');
        rows.push([b.name, k.name, c.title, c.description, PRI[c.priority], c.start_date, c.due_date, l, c.assignee, c.customer, c.crm_ref, c.est_minutes, Math.round(c.tracked / 60), c.cl_total ? `${c.cl_done}/${c.cl_total}` : '', c.created_at, c.done_at]);
      }
  }
  return toCsv(head, rows);
}

route('GET', '/api/boards/(\\d+)/export', c => {
  const f = c.q.get('format') || 'json', id = +c.m[1], b = exportBoard(id, c.q.get('files') === '1'), safe = b.name.replace(/[^\wäöüÄÖÜß-]+/g, '_');
  if (f === 'md') return dl(c, markdown(b), 'text/markdown; charset=utf-8', `${safe}.md`);
  if (f === 'csv') return dl(c, csvOfBoards([id]), 'text/csv; charset=utf-8', `${safe}.csv`);
  return dl(c, JSON.stringify({ format: 'lokales-kanban', version: 2, exported_at: now(), boards: [b] }, null, 2), 'application/json', `${safe}.json`);
});
route('GET', '/api/export/json', c => {
  const boards = all('SELECT id FROM boards ORDER BY pos').map(b => exportBoard(b.id, c.q.get('files') === '1'));
  dl(c, JSON.stringify({ format: 'lokales-kanban', version: 2, exported_at: now(), boards }, null, 2), 'application/json', `kanban-export-${stamp()}.json`);
});
route('GET', '/api/export/cards.csv', c => dl(c, csvOfBoards(all('SELECT id FROM boards WHERE archived=0 ORDER BY pos').map(b => b.id)), 'text/csv; charset=utf-8', `karten-${stamp()}.csv`));
route('GET', '/api/export/markdown', c => dl(c, all('SELECT id FROM boards WHERE archived=0 ORDER BY pos').map(b => markdown(exportBoard(b.id))).join('\n---\n\n'), 'text/markdown; charset=utf-8', `kanban-${stamp()}.md`));

// ---------- Import ----------
const labelId = (name, color) => {
  const l = get('SELECT id FROM labels WHERE lower(name)=lower(?)', name);
  return l ? l.id : Number(run('INSERT INTO labels (name,color) VALUES (?,?)', name, color || '#6b7285').lastInsertRowid);
};
function importBoards(boards, user) {
  const ids = [];
  tx(() => {
    for (const b of boards) {
      const bid = Number(run('INSERT INTO boards (name,color,icon,pos,swimlane,archived) VALUES (?,?,?,?,?,?)', str(b.name, 100) || 'Import', b.color || '#4f7cff', b.icon || '▦', (get('SELECT MAX(pos) m FROM boards').m || 0) + 1, b.swimlane || 'none', b.archived ? 1 : 0).lastInsertRowid);
      ids.push(bid);
      const map = new Map(), pending = [];
      (b.columns || []).forEach((k, i) => {
        const cid = Number(run('INSERT INTO columns (board_id,name,pos,wip_limit,done,sort,archived) VALUES (?,?,?,?,?,?,?)', bid, str(k.name, 100) || 'Spalte', i + 1, k.wip_limit || null, k.done ? 1 : 0, k.sort || 'manual', k.archived ? 1 : 0).lastInsertRowid);
        (k.cards || []).forEach((c, j) => {
          const id = Number(run(`INSERT INTO cards (column_id,title,description,priority,start_date,due_date,due_time,pos,assignee,customer,crm_ref,est_minutes,recur,recur_every,done_at,created_at,updated_at,archived,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`, cid, str(c.title, 500) || 'Karte', str(c.description, 200000), c.priority || 0, c.start_date, c.due_date, c.due_time, j + 1, c.assignee || '', c.customer || '', c.crm_ref || '', c.est_minutes,
            c.recur || '', c.recur_every || 1, c.done_at || (k.done ? now() : null), c.created_at || now(), now(), c.archived ? 1 : 0, user).lastInsertRowid);
          map.set(c.ref, id); pending.push([id, c]);
          run('INSERT INTO card_history (card_id,column_id,entered_at) VALUES (?,?,?)', id, cid, c.created_at || now());
          core.log(id, user, 'Importiert');
        });
      });
      for (const [id, c] of pending) {
        if (c.parent_ref && map.has(c.parent_ref)) run('UPDATE cards SET parent_id=? WHERE id=?', map.get(c.parent_ref), id);
        for (const l of c.labels || []) run('INSERT OR IGNORE INTO card_labels (card_id,label_id) VALUES (?,?)', id, labelId(l.name, l.color));
        (c.checklists || []).forEach((l, i) => {
          const lid = Number(run('INSERT INTO checklists (card_id,title,pos) VALUES (?,?,?)', id, str(l.title, 200) || 'Checkliste', i + 1).lastInsertRowid);
          (l.items || []).forEach((it, j) => run('INSERT INTO checklist_items (checklist_id,text,done,pos) VALUES (?,?,?,?)', lid, str(it.text, 500), it.done ? 1 : 0, j + 1));
        });
        for (const m of c.comments || []) run('INSERT INTO comments (card_id,user,body,created_at,edited_at) VALUES (?,?,?,?,?)', id, m.user || '', str(m.body, 20000), m.created_at || now(), m.edited_at || null);
        for (const t of c.time || []) run('INSERT INTO time_entries (card_id,user,started_at,ended_at,seconds,note) VALUES (?,?,?,?,?,?)', id, t.user || '', t.started_at, t.ended_at || t.started_at, t.seconds || 0, t.note || '');
        for (const l of c.links || []) if (map.has(l.to_card)) run('INSERT INTO card_links (from_card,to_card,type) VALUES (?,?,?)', id, map.get(l.to_card), l.type);
        for (const a of c.attachments || []) if (a.data_b64) { const st = S.saveFile(Buffer.from(a.data_b64, 'base64')); run('INSERT INTO attachments (card_id,name,mime,size,file,enc,created_at) VALUES (?,?,?,?,?,?,?)', id, a.name, a.mime, Buffer.byteLength(a.data_b64, 'base64'), st.file, st.enc, now()); }
      }
    }
  });
  return ids;
}
const TRELLO_COLORS = { green: '#2fa66a', yellow: '#e0b21e', orange: '#e08a1e', red: '#d93a3a', purple: '#8a5cf5', blue: '#4f7cff', sky: '#14a8b8', lime: '#7bc043', pink: '#e0509a', black: '#4a4f5c' };
function fromTrello(t) {
  const lbl = Object.fromEntries((t.labels || []).map(l => [l.id, { name: l.name || l.color || 'Label', color: TRELLO_COLORS[l.color] || '#6b7285' }]));
  const lists = (t.lists || []).filter(l => !l.closed);
  const cks = {}; for (const c of t.checklists || []) (cks[c.idCard] ||= []).push({ title: c.name, items: (c.checkItems || []).sort((a, b) => a.pos - b.pos).map(i => ({ text: i.name, done: i.state === 'complete' })) });
  const cms = {}; for (const a of t.actions || []) if (a.type === 'commentCard') (cms[a.data.card.id] ||= []).push({ user: a.memberCreator?.fullName || '', body: a.data.text, created_at: a.date });
  return { name: t.name, columns: lists.sort((a, b) => a.pos - b.pos).map(l => ({ name: l.name, done: /erledigt|fertig|done|complete/i.test(l.name),
    cards: (t.cards || []).filter(c => c.idList === l.id).sort((a, b) => a.pos - b.pos).map(c => ({ ref: c.id, title: c.name, description: c.desc, due_date: c.due ? c.due.slice(0, 10) : null, archived: c.closed ? 1 : 0,
      labels: (c.idLabels || []).map(i => lbl[i]).filter(Boolean), checklists: cks[c.id] || [], comments: (cms[c.id] || []).reverse() })) })) };
}
function parseCsv(text) {
  text = text.replace(/^﻿/, '');
  const delim = (text.split('\n')[0].match(/;/g) || []).length >= (text.split('\n')[0].match(/,/g) || []).length ? ';' : ',';
  const rows = []; let row = [], v = '', q = false;
  for (let i = 0; i < text.length; i++) {
    const ch = text[i];
    if (q) { if (ch === '"') { if (text[i + 1] === '"') { v += '"'; i++; } else q = false; } else v += ch; }
    else if (ch === '"') q = true;
    else if (ch === delim) { row.push(v); v = ''; }
    else if (ch === '\n' || ch === '\r') { if (ch === '\r' && text[i + 1] === '\n') i++; row.push(v); rows.push(row); row = []; v = ''; }
    else v += ch;
  }
  if (v || row.length) { row.push(v); rows.push(row); }
  return rows.filter(r => r.some(x => x.trim()));
}
const parseDate = s => { s = (s || '').trim(); let m = s.match(/^(\d{4})-(\d{2})-(\d{2})/); if (m) return m[0]; m = s.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})/); return m ? `${m[3]}-${m[2].padStart(2, '0')}-${m[1].padStart(2, '0')}` : null; };
function fromCsv(text, name) {
  const rows = parseCsv(text); if (rows.length < 2) bad('CSV enthält keine Zeilen');
  const h = rows[0].map(x => x.trim().toLowerCase());
  const idx = (...n) => h.findIndex(x => n.includes(x));
  const iT = idx('titel', 'title', 'name', 'karte', 'card name', 'summary'), iD = idx('beschreibung', 'description', 'desc'), iC = idx('spalte', 'list', 'liste', 'status', 'column', 'list name');
  const iDue = idx('fällig', 'faellig', 'due', 'due date', 'fälligkeit'), iP = idx('priorität', 'prioritaet', 'priority'), iL = idx('labels', 'label'), iA = idx('person', 'assignee', 'zugewiesen', 'members'), iK = idx('kunde', 'customer');
  if (iT < 0) bad('CSV braucht eine Spalte „Titel“');
  const cols = new Map();
  for (const r of rows.slice(1)) {
    const cn = (iC >= 0 && r[iC]?.trim()) || 'Importiert';
    if (!cols.has(cn)) cols.set(cn, { name: cn, done: /erledigt|fertig|done/i.test(cn), cards: [] });
    const pr = (r[iP] || '').toLowerCase();
    cols.get(cn).cards.push({ title: r[iT], description: iD >= 0 ? r[iD] : '', due_date: iDue >= 0 ? parseDate(r[iDue]) : null,
      priority: /dring|urgent/.test(pr) ? 3 : /hoch|high/.test(pr) ? 2 : /nied|low/.test(pr) ? 1 : 0,
      labels: iL >= 0 ? (r[iL] || '').split(/[,;]/).map(s => s.trim()).filter(Boolean).map(n => ({ name: n })) : [], assignee: iA >= 0 ? r[iA] : '', customer: iK >= 0 ? r[iK] : '' });
  }
  return { name, columns: [...cols.values()] };
}
route('POST', '/api/import', c => {
  const { kind, text, name } = c.body; let data;
  try {
    if (kind === 'csv') { return { boards: importBoards([fromCsv(String(text), str(name, 100) || 'CSV-Import')], c.user) }; }
    data = JSON.parse(text);
  } catch (e) { if (e.status || e.code) throw e; bad('Datei nicht lesbar: ' + e.message); }
  if (data.format === 'lokales-kanban' && Array.isArray(data.boards)) return { boards: importBoards(data.boards, c.user) };
  if (Array.isArray(data.lists) && Array.isArray(data.cards)) return { boards: importBoards([fromTrello(data)], c.user) };
  bad('Unbekanntes Format (erwartet: Lokales-Kanban-JSON, Trello-JSON oder CSV)');
});
