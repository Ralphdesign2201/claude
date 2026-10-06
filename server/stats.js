// Suche, Dashboard, Auswertungen, Zeitberichte
const S = require('./store');
const { route, bad } = require('./router');
const { all, get } = S;
const { CARD_SQL, LIVE, localDate, str } = require('./core');

const toDate = s => new Date(!s ? NaN : /T|Z/.test(s) ? s : s.replace(' ', 'T') + 'Z');
const mondayOf = d => { const x = new Date(d); x.setHours(0, 0, 0, 0); x.setDate(x.getDate() - ((x.getDay() + 6) % 7)); return x; };
const cell = v => { v = String(v ?? ''); return /[";\n\r]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
const toCsv = (head, rows) => '﻿' + [head, ...rows].map(r => r.map(cell).join(';')).join('\r\n') + '\r\n';
const num = n => String(Math.round(n * 100) / 100).replace('.', ',');

route('GET', '/api/search', c => {
  const term = (c.q.get('q') || '').trim(); if (!term) return [];
  const like = '%' + term.replace(/[\\%_]/g, '\\$&') + '%', out = new Map();
  const base = `SELECT c.id,c.title,c.archived,k.board_id,k.name column_name,b.name board_name`;
  const from = `FROM cards c JOIN columns k ON k.id=c.column_id JOIN boards b ON b.id=k.board_id`;
  const add = (rows, where) => rows.forEach(r => { if (!out.has(r.id)) out.set(r.id, { ...r, where }); });
  add(all(`${base} ${from} WHERE c.deleted_at IS NULL AND (c.title LIKE ?1 ESCAPE '\\' OR c.customer LIKE ?1 ESCAPE '\\' OR c.assignee LIKE ?1 ESCAPE '\\') ORDER BY c.updated_at DESC LIMIT 50`, like), 'Titel');
  add(all(`${base} ${from} WHERE c.deleted_at IS NULL AND c.description LIKE ? ESCAPE '\\' LIMIT 50`, like), 'Beschreibung');
  add(all(`${base} ${from} JOIN comments m ON m.card_id=c.id WHERE c.deleted_at IS NULL AND m.body LIKE ? ESCAPE '\\' LIMIT 50`, like), 'Kommentar');
  add(all(`${base} ${from} JOIN checklists l ON l.card_id=c.id JOIN checklist_items i ON i.checklist_id=l.id WHERE c.deleted_at IS NULL AND i.text LIKE ? ESCAPE '\\' LIMIT 50`, like), 'Checkliste');
  return [...out.values()].slice(0, 60);
});

route('GET', '/api/dashboard', () => {
  const today = localDate(), week = mondayOf(new Date()).toISOString();
  const boards = Object.fromEntries(all('SELECT * FROM boards WHERE archived=0').map(b => [b.id, b]));
  const rows = all(CARD_SQL + ` JOIN boards b ON b.id=k.board_id WHERE ${LIVE} AND b.archived=0`).filter(r => boards[r.board_id]);
  const firstCol = {};
  for (const k of all('SELECT id,board_id FROM columns WHERE archived=0 ORDER BY pos')) firstCol[k.board_id] ??= k.id;
  const colDone = Object.fromEntries(all('SELECT id,done FROM columns').map(k => [k.id, k.done]));
  const tag = r => ({ ...r, board_name: boards[r.board_id].name, board_color: boards[r.board_id].color });
  const open = rows.filter(r => !r.done_at);
  return {
    today: open.filter(r => r.due_date === today).map(tag),
    overdue: open.filter(r => r.due_date && r.due_date < today).map(tag),
    doing: open.filter(r => !colDone[r.column_id] && firstCol[r.board_id] !== r.column_id).map(tag),
    done_week: rows.filter(r => r.done_at && r.done_at >= week).map(tag),
    soon: open.filter(r => r.due_date && r.due_date > today).sort((a, b) => a.due_date.localeCompare(b.due_date)).slice(0, 10).map(tag),
    counts: { open: open.length, total: rows.length },
  };
});

route('GET', '/api/boards/(\\d+)/analytics', c => {
  const id = +c.m[1], now = Date.now();
  const cols = all('SELECT * FROM columns WHERE board_id=? AND archived=0 ORDER BY pos', id);
  const hist = all(`SELECT h.* FROM card_history h JOIN cards c ON c.id=h.card_id JOIN columns k ON k.id=c.column_id WHERE k.board_id=? AND c.deleted_at IS NULL`, id);
  const cycle = cols.map(k => {
    const hs = hist.filter(h => h.column_id === k.id), secs = hs.map(h => ((h.left_at ? toDate(h.left_at) : now) - toDate(h.entered_at)) / 1000).filter(s => s >= 0);
    return { column: k.name, avg_seconds: secs.length ? secs.reduce((a, b) => a + b, 0) / secs.length : 0, cards: secs.length };
  });
  const cards = all(`SELECT c.created_at,c.done_at FROM cards c JOIN columns k ON k.id=c.column_id WHERE k.board_id=? AND c.deleted_at IS NULL`, id);
  const monday = mondayOf(new Date()), throughput = [];
  for (let i = 11; i >= 0; i--) {
    const from = new Date(monday); from.setDate(from.getDate() - 7 * i); const to = new Date(from); to.setDate(to.getDate() + 7);
    throughput.push({ week: localDate(from), done: cards.filter(r => r.done_at && toDate(r.done_at) >= from && toDate(r.done_at) < to).length });
  }
  const burndown = [];
  for (let i = 29; i >= 0; i--) {
    const end = new Date(); end.setHours(23, 59, 59, 999); end.setDate(end.getDate() - i);
    burndown.push({ date: localDate(end), open: cards.filter(r => toDate(r.created_at) <= end && (!r.done_at || toDate(r.done_at) > end)).length,
      scope: cards.filter(r => toDate(r.created_at) <= end).length });
  }
  const sum = (g) => { const m = {}; for (const r of timeRows({ board_id: id })) for (const k of g(r)) m[k] = (m[k] || 0) + r.seconds; return Object.entries(m).map(([key, seconds]) => ({ key, seconds })).sort((a, b) => b.seconds - a.seconds); };
  return { cycle, throughput, burndown, time_by_label: sum(r => r.labels.length ? r.labels : ['(ohne Label)']), time_by_customer: sum(r => [r.customer || '(ohne Kunde)']) };
});

function timeRows(q) {
  const labels = {};
  for (const r of all('SELECT cl.card_id, l.name FROM card_labels cl JOIN labels l ON l.id=cl.label_id')) (labels[r.card_id] ||= []).push(r.name);
  const rows = all(`SELECT e.*, c.title, c.customer, c.crm_ref, k.board_id, b.name board_name FROM time_entries e JOIN cards c ON c.id=e.card_id JOIN columns k ON k.id=c.column_id JOIN boards b ON b.id=k.board_id ORDER BY e.started_at DESC`);
  const from = q.from || '0000-00-00', to = q.to || '9999-99-99';
  return rows.map(e => ({ ...e, date: localDate(toDate(e.started_at)), seconds: e.ended_at ? e.seconds : Math.round((Date.now() - toDate(e.started_at)) / 1000), labels: labels[e.card_id] || [] }))
    .filter(e => e.date >= from && e.date <= to && (!q.board_id || e.board_id === +q.board_id) && (!q.user || e.user === q.user) && (!q.customer || e.customer === q.customer));
}
route('GET', '/api/time/report', c => {
  const q = Object.fromEntries(c.q), rows = timeRows(q), group = q.group || 'entry';
  const keyOf = { day: r => [r.date], card: r => [`#${r.card_id} ${r.title}`], label: r => r.labels.length ? r.labels : ['(ohne Label)'], customer: r => [r.customer || '(ohne Kunde)'], board: r => [r.board_name], user: r => [r.user || ''] }[group];
  let head, out;
  if (!keyOf) {
    head = ['Datum', 'Board', 'Karte', 'Kunde', 'CRM-Referenz', 'Labels', 'Person', 'Minuten', 'Stunden', 'Notiz'];
    out = rows.map(r => [r.date, r.board_name, r.title, r.customer, r.crm_ref, r.labels.join(', '), r.user, Math.round(r.seconds / 60), num(r.seconds / 3600), r.note]);
  } else {
    const m = {}; for (const r of rows) for (const k of keyOf(r)) { m[k] ||= { seconds: 0, n: 0 }; m[k].seconds += r.seconds; m[k].n++; }
    head = ['Gruppe', 'Einträge', 'Minuten', 'Stunden'];
    out = Object.entries(m).sort((a, b) => group === 'day' ? b[0].localeCompare(a[0]) : b[1].seconds - a[1].seconds).map(([k, v]) => [k, v.n, Math.round(v.seconds / 60), num(v.seconds / 3600)]);
  }
  const total = rows.reduce((a, r) => a + r.seconds, 0);
  if (q.format === 'csv') return c.sendRaw(Buffer.from(toCsv(head, out)), 'text/csv; charset=utf-8', { 'Content-Disposition': 'attachment; filename="zeitbericht.csv"' });
  return { head, rows: out, total_seconds: total, users: [...new Set(all('SELECT DISTINCT user FROM time_entries').map(r => r.user))] };
});
module.exports = { toCsv, num, toDate };

route('GET', '/api/reminders', () => {
  const lo = localDate(new Date(Date.now() - 864e5)), hi = localDate(new Date(Date.now() + 3 * 864e5));
  return all(CARD_SQL.replace(/SELECT c\.\*,.*? FROM cards c/s, 'SELECT c.id,c.title,c.due_date,c.due_time FROM cards c') + ` WHERE ${LIVE} AND c.done_at IS NULL AND c.due_date BETWEEN ? AND ?`, lo, hi);
});
