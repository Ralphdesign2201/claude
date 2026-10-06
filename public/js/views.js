// Weitere Ansichten desselben Boards: Liste, Kalender, Zeitleiste, Wochenplan
import { $, el, S, api, fail, toast, fmtDate, fmtDateShort, fmtDur, todayStr, addDays, parseD, dstr, WD, MONTHS, PRI, undo, ask, textOn } from './util.js';
import { cardEl, passes, labelMap, labelIds, toggleSel } from './board.js';
import { startDrag } from './dnd.js';

const visible = () => S.data.cards.filter(passes);
const colName = id => S.data.columns.find(c => c.id === id)?.name || '';
const open = id => S.hooks.openCard(id);

// ----- Liste / Tabelle -----
let sortKey = 'column', sortDir = 1;
export function renderList(root) {
  const lm = labelMap(), colIdx = Object.fromEntries(S.data.columns.map((c, i) => [c.id, i]));
  const key = {
    title: c => c.title.toLowerCase(), column: c => colIdx[c.column_id] * 1e6 + c.pos, priority: c => -c.priority, assignee: c => c.assignee || '~', customer: c => c.customer || '~',
    start: c => c.start_date || '~', due: c => c.due_date || '~', time: c => -c.tracked, check: c => c.cl_total ? -c.cl_done / c.cl_total : 1,
  }[sortKey];
  const rows = visible().sort((a, b) => { const x = key(a), y = key(b); return (x < y ? -1 : x > y ? 1 : 0) * sortDir; });
  const th = (k, t) => el('th', { class: sortKey === k ? 'sorted' : '', textContent: t + (sortKey === k ? (sortDir > 0 ? ' ▲' : ' ▼') : ''), onclick: () => { sortDir = sortKey === k ? -sortDir : 1; sortKey = k; renderList(root); } });
  root.replaceChildren(el('div', { class: 'scroll' }, el('table', { class: 'tbl' },
    el('thead', {}, el('tr', {}, el('th'), th('title', 'Titel'), th('column', 'Spalte'), th('priority', 'Priorität'), th('assignee', 'Person'), th('customer', 'Kunde'), el('th', { textContent: 'Labels' }), th('start', 'Start'), th('due', 'Fällig'), th('time', 'Zeit'), th('check', 'Checkliste'))),
    el('tbody', {}, rows.map(c => el('tr', { class: (c.done_at ? 'done ' : '') + (c.due_date && !c.done_at && c.due_date < todayStr() ? 'due-over ' : '') + (S.sel.has(c.id) ? 'sel' : ''), dataset: { id: c.id }, onclick: e => (e.ctrlKey || e.metaKey || S.sel.size) ? (toggleSel(c.id), e.currentTarget.classList.toggle('sel', S.sel.has(c.id))) : open(c.id) },
      el('td', {}, el('input', { type: 'checkbox', checked: S.sel.has(c.id), onclick: e => { e.stopPropagation(); toggleSel(c.id); } })),
      el('td', { class: 'ttl', textContent: c.title }), el('td', { textContent: colName(c.column_id) }), el('td', { textContent: c.priority ? PRI[c.priority] : '' }), el('td', { textContent: c.assignee }), el('td', { textContent: c.customer }),
      el('td', {}, labelIds(c).map(i => lm[i]).filter(Boolean).map(l => el('span', { class: 'chip', style: `background:${l.color};color:${textOn(l.color)}`, textContent: l.name }))),
      el('td', { textContent: fmtDate(c.start_date) }), el('td', { class: 'due', textContent: fmtDate(c.due_date) + (c.due_time ? ' ' + c.due_time : '') }),
      el('td', { textContent: c.tracked || c.est_minutes ? fmtDur(c.tracked) + (c.est_minutes ? ' / ' + fmtDur(c.est_minutes * 60) : '') : '' }), el('td', { textContent: c.cl_total ? `${c.cl_done}/${c.cl_total}` : '' })))))),
    rows.length ? '' : el('p', { class: 'empty', textContent: 'Keine Karten (Filter aktiv?).' }));
}

// ----- Kalender -----
let calMonth = null;
export function renderCalendar(root) {
  const t = new Date(); calMonth ||= new Date(t.getFullYear(), t.getMonth(), 1);
  const first = new Date(calMonth), start = new Date(first); start.setDate(1 - ((first.getDay() + 6) % 7));
  const byDay = {}; for (const c of visible()) if (c.due_date) (byDay[c.due_date] ||= []).push(c);
  const nav = d => () => { calMonth = d === 0 ? new Date(t.getFullYear(), t.getMonth(), 1) : new Date(calMonth.getFullYear(), calMonth.getMonth() + d, 1); renderCalendar(root); };
  const grid = el('div', { class: 'cal' }, WD.map(w => el('div', { class: 'wd', textContent: w })));
  for (let i = 0; i < 42; i++) {
    const d = new Date(start); d.setDate(start.getDate() + i); const s = dstr(d);
    if (i >= 35 && d.getMonth() !== calMonth.getMonth()) break;
    const cell = el('div', { class: 'day' + (d.getMonth() !== calMonth.getMonth() ? ' other' : '') + (s === todayStr() ? ' today' : ''), dataset: { date: s } },
      el('div', { class: 'dn' }, el('span', { textContent: d.getDate() }), el('button', { textContent: '＋', title: 'Karte mit diesem Fälligkeitsdatum', onclick: () => addOn(s) })),
      ...(byDay[s] || []).map(c => el('div', { class: 'cchip p' + c.priority + (c.done_at ? ' done' : ''), textContent: c.title, title: c.title, onclick: () => open(c.id) })));
    grid.append(cell);
  }
  root.replaceChildren(el('div', { class: 'scroll' }, el('div', { class: 'calnav' }, el('button', { textContent: '◀', onclick: nav(-1) }), el('b', { textContent: `${MONTHS[calMonth.getMonth()]} ${calMonth.getFullYear()}` }), el('button', { textContent: '▶', onclick: nav(1) }), el('button', { textContent: 'Heute', onclick: nav(0) })), grid));
}
async function addOn(date) {
  const v = await ask({ title: 'Neue Karte am ' + fmtDate(date), fields: [{ name: 't', placeholder: 'Titel' }, { name: 'c', label: 'Spalte', type: 'select', options: S.data.columns.map(c => ({ value: c.id, label: c.name })) }], ok: 'Anlegen' });
  if (!v?.t.trim()) return;
  try { const r = await api('POST', '/cards', { column_id: +v.c, title: v.t, fields: { due_date: date } }); undo.push({ label: 'Karte erstellt', undo: () => api('DELETE', '/cards/' + r.id), redo: () => api('POST', `/cards/${r.id}/restore`) }); await S.hooks.refresh(); } catch (e) { fail(e); }
}

// ----- Zeitleiste -----
let zoom = 28;
export function renderTimeline(root) {
  const cards = visible().filter(c => c.start_date || c.due_date);
  if (!cards.length) return root.replaceChildren(el('p', { class: 'empty', textContent: 'Keine Karten mit Start- oder Fälligkeitsdatum. Trage Daten in den Karten ein, dann erscheinen sie hier.' }));
  const t = todayStr(), starts = cards.map(c => c.start_date || c.due_date), ends = cards.map(c => c.due_date || c.start_date);
  const from = addDays([...starts, t].sort()[0], -3), to = addDays([...ends, t].sort().pop(), 5);
  const days = Math.round((parseD(to) - parseD(from)) / 864e5) + 1, X = d => Math.round((parseD(d) - parseD(from)) / 864e5) * zoom;
  const head = el('div', { class: 'tl-head', style: `width:${days * zoom}px` });
  for (let i = 0; i < days; i++) { const d = parseD(addDays(from, i)); head.append(el('div', { class: 'tl-day' + (d.getDay() % 6 === 0 ? ' we' : '') + (dstr(d) === t ? ' today' : ''), style: `left:${i * zoom}px;width:${zoom}px`, textContent: zoom >= 20 ? d.getDate() : (d.getDate() === 1 ? d.getDate() : '') }));
    if (d.getDate() === 1 || (i === 0 && parseD(from).getDate() < 24)) head.append(el('div', { class: 'tl-month', style: `left:${i * zoom}px`, textContent: MONTHS[d.getMonth()] + ' ' + d.getFullYear() })); }
  const rows = el('div', { class: 'tl-rows', style: `width:${days * zoom}px` }, el('div', { class: 'tl-today', style: `left:${X(t) + zoom / 2}px` }));
  const byCol = S.data.columns.map(col => [col, cards.filter(c => c.column_id === col.id).sort((a, b) => (a.start_date || a.due_date).localeCompare(b.start_date || b.due_date))]).filter(([, l]) => l.length);
  const labels = el('div', { class: 'tl-labels' });
  for (const [col, list] of byCol) {
    labels.append(el('div', { class: 'tl-col', textContent: col.name })); rows.append(el('div', { class: 'tl-colrow' }));
    for (const c of list) {
      const s = c.start_date || c.due_date, e = c.due_date || c.start_date, x = X(s), w = Math.max(zoom, X(e) - x + zoom);
      labels.append(el('div', { class: 'tl-label', textContent: c.title, title: c.title, onclick: () => open(c.id) }));
      rows.append(el('div', { class: 'tl-row' }, el('div', { class: 'tl-bar p' + c.priority + (c.done_at ? ' done' : '') + (e < t && !c.done_at ? ' over' : ''), style: `left:${x}px;width:${w}px`, title: `${c.title}\n${fmtDate(s)} – ${fmtDate(e)}`, onclick: () => open(c.id) }, el('span', { textContent: c.title }))));
    }
  }
  root.replaceChildren(el('div', { class: 'tl' }, el('div', { class: 'tl-tools' }, el('button', { textContent: '−', onclick: () => { zoom = Math.max(8, zoom - 6); renderTimeline(root); } }), el('span', { class: 'muted', textContent: 'Zoom' }), el('button', { textContent: '+', onclick: () => { zoom = Math.min(80, zoom + 6); renderTimeline(root); } })),
    el('div', { class: 'tl-wrap' }, el('div', { class: 'tl-side' }, el('div', { class: 'tl-corner' }), labels), el('div', { class: 'tl-scroll' }, head, rows))));
  const sc = $('.tl-scroll'); if (sc) sc.scrollLeft = Math.max(0, X(t) - 200);
}

// ----- Wochenplan -----
let weekStart = null;
const mondayOf = s => { const d = parseD(s); d.setDate(d.getDate() - ((d.getDay() + 6) % 7)); return dstr(d); };
export function renderWeek(root) {
  weekStart ||= mondayOf(todayStr());
  const t = todayStr(), cards = visible(), open_ = cards.filter(c => !c.done_at);
  const cell = (date, cls, list, title) => {
    const box = el('div', { class: 'wk ' + cls + (date === t ? ' today' : ''), dataset: { date: date || '' } }, el('div', { class: 'wkh', textContent: title }), el('div', { class: 'wkb', dataset: { date: date || '' } }, list.map(c => cardEl(c, { drag: () => ({ container: '.wkb', items: '.card', onDrop: async info => {
      const nd = info.container.dataset.date || null; if (nd === (c.due_date || null)) return;
      try { await api('PATCH', '/cards/' + c.id, { due_date: nd }); undo.push({ label: 'Termin verschoben', undo: () => api('PATCH', '/cards/' + c.id, { due_date: c.due_date }), redo: () => api('PATCH', '/cards/' + c.id, { due_date: nd }) }); } catch (e) { fail(e); }
      await S.hooks.refresh(); } }) }))));
    return box;
  };
  const days = Array.from({ length: 7 }, (_, i) => addDays(weekStart, i));
  const nav = n => () => { weekStart = n === 0 ? mondayOf(t) : addDays(weekStart, 7 * n); renderWeek(root); };
  root.replaceChildren(el('div', { class: 'scroll' }, el('div', { class: 'calnav' }, el('button', { textContent: '◀', onclick: nav(-1) }), el('b', { textContent: `${fmtDateShort(days[0])} – ${fmtDate(days[6])}` }), el('button', { textContent: '▶', onclick: nav(1) }), el('button', { textContent: 'Diese Woche', onclick: nav(0) })),
    el('div', { class: 'week' }, el('div', { class: 'wkcol side' }, cell(null, 'nodate', open_.filter(c => !c.due_date), 'Ohne Datum')),
      ...days.map((d, i) => el('div', { class: 'wkcol' }, cell(d, '', cards.filter(c => c.due_date === d), `${WD[i]} ${fmtDateShort(d)}`))))));
  const od = open_.filter(c => c.due_date && c.due_date < weekStart);
  if (od.length) $('.wkcol.side', root).append(el('div', { class: 'wk overdue' }, el('div', { class: 'wkh', textContent: 'Früher fällig (' + od.length + ')' }), el('div', { class: 'wko' }, od.map(c => cardEl(c)))));
}
