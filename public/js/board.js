// Board-Ansicht: Spalten, Swimlanes, Karten, Ziehen, Mehrfachauswahl
import { $, $$, el, S, api, fail, toast, fmtDateShort, fmtDur, todayStr, PRI, initials, textOn, undo, ask, menu, confirmBox, addDays } from './util.js';
import { startDrag } from './dnd.js';

export const labelMap = () => Object.fromEntries((S.data?.labels || []).map(l => [l.id, l]));
export const labelIds = c => c.labels ? String(c.labels).split(',').map(Number) : [];
const weekEnd = () => { const d = new Date(); const w = (d.getDay() + 6) % 7; return addDays(todayStr(), 6 - w); };

export function passes(c) {
  const f = S.filter;
  if (f.text) { const t = f.text.toLowerCase(); if (![c.title, c.description, c.customer, c.assignee].some(x => (x || '').toLowerCase().includes(t))) return false; }
  if (f.label && !labelIds(c).includes(+f.label)) return false;
  if (f.priority !== undefined && f.priority !== '' && c.priority !== +f.priority) return false;
  if (f.person) { if (f.person === '__none' ? c.assignee : c.assignee !== f.person) return false; }
  if (f.customer && c.customer !== f.customer) return false;
  if (f.due) {
    const t = todayStr(), open = !c.done_at;
    if (f.due === 'today' && !(c.due_date === t)) return false;
    if (f.due === 'overdue' && !(open && c.due_date && c.due_date < t)) return false;
    if (f.due === 'week' && !(c.due_date && c.due_date >= t && c.due_date <= weekEnd())) return false;
    if (f.due === 'none' && c.due_date) return false;
  }
  return true;
}
export const filterActive = () => Object.values(S.filter).some(v => v !== '' && v !== undefined && v !== null);

// ----- Karte -----
export function cardEl(c, o = {}) {
  const lm = labelMap(), t = todayStr();
  const over = c.due_date && !c.done_at && c.due_date < t, today = c.due_date === t && !c.done_at;
  const est = c.est_minutes ? c.est_minutes * 60 : 0;
  const people = c.assignee ? el('span', { class: 'avatar', title: c.assignee, textContent: initials(c.assignee) }) : '';
  const node = el('div', { class: 'card' + (over ? ' due-over' : '') + (c.done_at ? ' done' : '') + (S.sel.has(c.id) ? ' sel' : '') + (S.focus === c.id ? ' kfocus' : '') + (c.blocked_by ? ' blocked' : ''), dataset: { id: c.id } },
    c.priority ? el('div', { class: 'pri p' + c.priority, title: 'Priorität: ' + PRI[c.priority] }) : '',
    el('button', { class: 'chk', title: c.done_at ? 'Wieder öffnen' : 'Als erledigt markieren', textContent: c.done_at ? '✓' : '', 'data-nodrag': 1, onclick: e => { e.stopPropagation(); toggleDone(c); } }),
    labelIds(c).length ? el('div', { class: 'chips' }, labelIds(c).map(i => lm[i]).filter(Boolean).map(l => el('span', { class: 'chip', style: `background:${l.color};color:${textOn(l.color)}`, textContent: l.name }))) : '',
    c.parent_title ? el('div', { class: 'parent', textContent: '↳ ' + c.parent_title }) : '',
    el('div', { class: 't', textContent: c.title }),
    el('div', { class: 'meta' },
      c.due_date ? el('span', { class: 'due' + (over ? ' over' : today ? ' today' : ''), textContent: '⏱ ' + fmtDateShort(c.due_date) + (c.due_time ? ' ' + c.due_time : '') }) : '',
      c.blocked_by ? el('span', { class: 'over', title: 'Blockiert durch andere Karte', textContent: '⛔' }) : '',
      c.cl_total ? el('span', { textContent: `☑ ${c.cl_done}/${c.cl_total}` }) : '',
      c.sub_total ? el('span', { textContent: `↳ ${c.sub_done}/${c.sub_total}` }) : '',
      c.n_comments ? el('span', { textContent: '💬 ' + c.n_comments }) : '', c.n_att ? el('span', { textContent: '📎 ' + c.n_att }) : '',
      c.n_links ? el('span', { textContent: '🔗' }) : '', c.recur ? el('span', { title: 'Wiederkehrend', textContent: '↻' }) : '',
      c.description ? el('span', { title: 'Hat Beschreibung', textContent: '≡' }) : '',
      (c.tracked || est || c.running_since) ? el('span', { class: (c.running_since ? 'run ' : '') + (est && c.tracked > est ? 'over' : ''), textContent: (c.running_since ? '● ' : '⏲ ') + fmtDur(c.tracked) + (est ? ' / ' + fmtDur(est) : '') }) : '',
      c.customer ? el('span', { class: 'cust', textContent: c.customer }) : '', el('span', { class: 'sp' }), people),
    c.cl_total ? el('div', { class: 'bar' }, el('i', { style: `width:${Math.round(100 * c.cl_done / c.cl_total)}%` })) : '');
  const open = () => S.hooks.openCard(c.id);
  node.addEventListener('pointerdown', e => {
    if (e.target.closest('.chk')) return;
    if (o.drag) startDrag(e, { node, onClick: ev => click(ev), ...o.drag(c, node) });
    else node.onclick = ev => click(ev);
  });
  if (!o.drag) node.onclick = ev => click(ev);
  function click(ev) { if (ev.ctrlKey || ev.metaKey || ev.shiftKey || S.sel.size) toggleSel(c.id); else { S.focus = c.id; open(); } }
  return node;
}
export function toggleSel(id) {
  S.sel.has(id) ? S.sel.delete(id) : S.sel.add(id);
  $$(`.card[data-id="${id}"]`).forEach(n => n.classList.toggle('sel', S.sel.has(id)));
  S.hooks.selbar?.();
}
export async function toggleDone(c) {
  const was = !!c.done_at;
  try { await api('POST', `/cards/${c.id}/done`, { done: !was }); } catch (e) { return fail(e); }
  undo.push({ label: 'Erledigt-Status', undo: () => api('POST', `/cards/${c.id}/done`, { done: was }), redo: () => api('POST', `/cards/${c.id}/done`, { done: !was }) });
  await S.hooks.refresh();
}

// ----- Spalten -----
const colSorted = (col, cards) => {
  if (col.sort === 'date') return [...cards].sort((a, b) => (a.due_date || '9999').localeCompare(b.due_date || '9999') || a.pos - b.pos);
  if (col.sort === 'priority') return [...cards].sort((a, b) => b.priority - a.priority || a.pos - b.pos);
  return cards;
};
function lanesOf(d) {
  const mode = d.board.swimlane, cards = d.cards;
  if (mode === 'priority') return [3, 2, 1, 0].map(p => ({ key: String(p), label: PRI[p] === 'Keine' ? 'Ohne Priorität' : PRI[p] }));
  if (mode === 'assignee' || mode === 'customer') {
    const k = mode, vals = [...new Set(cards.map(c => c[k]).filter(Boolean))].sort();
    return [...vals.map(v => ({ key: v, label: v })), { key: '', label: mode === 'assignee' ? 'Ohne Person' : 'Ohne Kunde' }];
  }
  return [{ key: '', label: '' }];
}
const laneVal = (mode, c) => mode === 'priority' ? String(c.priority) : mode === 'none' ? '' : (c[mode] || '');

export function renderBoard(root) {
  const keep = root.querySelector('.quick')?.value;
  const d = S.data, mode = d.board.swimlane || 'none', lanes = lanesOf(d);
  const colById = Object.fromEntries(d.columns.map(c => [c.id, c]));
  const heads = el('div', { class: 'heads' });
  const grid = el('div', { class: 'grid' }, heads);
  for (const col of d.columns) heads.append(headEl(col, heads));
  heads.append(el('button', { class: 'addcol', textContent: '+ Spalte', onclick: addColumn }));
  for (const lane of lanes) {
    const inLane = d.cards.filter(c => laneVal(mode, c) === lane.key);
    const rowCards = inLane.filter(passes);
    if (mode !== 'none') grid.append(el('div', { class: 'lane-label' }, el('b', { textContent: lane.label }), el('span', { class: 'muted', textContent: ` ${rowCards.length}` })));
    const row = el('div', { class: 'lane-row' });
    for (const col of d.columns) {
      const cell = el('div', { class: 'cell' + (col.collapsed ? ' collapsed' : '') + (overWip(col, d) ? ' over-wip' : ''), dataset: { col: col.id, lane: lane.key } });
      if (!col.collapsed) for (const c of colSorted(col, rowCards.filter(c => c.column_id === col.id))) cell.append(cardEl(c, { drag: (card) => cardDrag(card, colById, mode) }));
      row.append(cell);
    }
    grid.append(row);
  }
  root.replaceChildren(el('div', { id: 'boardScroll', class: 'boardscroll' }, grid));
  const inp = $('.quick', root); if (inp) { if (keep) inp.value = keep; inp.focus(); inp.setSelectionRange(inp.value.length, inp.value.length); }
}
const overWip = (col, d) => col.wip_limit && d.cards.filter(c => c.column_id === col.id).length > col.wip_limit;

function headEl(col, headsEl) {
  const d = S.data, all = d.cards.filter(c => c.column_id === col.id), shown = all.filter(passes).length;
  const over = overWip(col, d);
  const count = (col.wip_limit ? `${all.length}/${col.wip_limit}` : shown === all.length ? `${all.length}` : `${shown}/${all.length}`);
  const h = el('div', { class: 'chead' + (col.collapsed ? ' collapsed' : '') + (over ? ' over-wip' : ''), dataset: { id: col.id } },
    el('div', { class: 'hrow' },
      el('span', { class: 'grip', textContent: '⠿', title: 'Spalte verschieben' }),
      el('span', { class: 'name', textContent: col.name, title: col.done ? 'Zählt als „Erledigt“' : '', ondblclick: () => renameCol(col) }),
      col.done ? el('span', { class: 'donebadge', textContent: '✓' }) : '',
      el('span', { class: 'count', title: over ? 'WIP-Limit überschritten' : '', textContent: count }),
      el('button', { textContent: '＋', title: 'Karte hinzufügen', onclick: () => { S.adding = S.adding === col.id ? null : col.id; S.hooks.render(); } }),
      el('button', { textContent: col.collapsed ? '▸' : '▾', title: 'Ein-/Ausklappen', onclick: () => act(api('PATCH', '/columns/' + col.id, { collapsed: col.collapsed ? 0 : 1 })) }),
      el('button', { textContent: '⋯', title: 'Spaltenoptionen', onclick: e => columnMenu(col, e.currentTarget) })),
    S.adding === col.id ? el('input', { class: 'quick', placeholder: 'Titel, Enter zum Hinzufügen', 'data-nodrag': 1, onkeydown: async e => {
      if (e.key === 'Escape') { S.adding = null; S.hooks.render(); }
      else if (e.key === 'Enter' && e.target.value.trim()) {
        const title = e.target.value.trim(); e.target.value = '';
        try { const r = await api('POST', '/cards', { column_id: col.id, title }); undo.push({ label: 'Karte erstellt', undo: () => api('DELETE', '/cards/' + r.id), redo: () => api('POST', `/cards/${r.id}/restore`) }); await S.hooks.refresh(); } catch (x) { fail(x); }
      }
    }, onblur: e => { const inp = e.target; if (!inp.value.trim()) setTimeout(() => { if (inp.isConnected && S.adding === col.id && document.activeElement !== inp) { S.adding = null; S.hooks.render(); } }, 200); } }) : '');
  h.querySelector('.grip').addEventListener('pointerdown', e => startDrag(e, {
    node: h, container: '.heads', fixed: headsEl, items: '.chead', horizontal: true, immediate: true, endMarker: headsEl.querySelector('.addcol'),
    onDrop: async info => { try { await api('POST', `/columns/${col.id}/move`, { before_id: info.before?.dataset.id ?? null }); } catch (x) { fail(x); } await S.hooks.refresh(); },
  }));
  h.querySelector('.grip').style.touchAction = 'none';
  return h;
}
const act = p => p.then(() => S.hooks.refresh()).catch(fail);
async function renameCol(col) { const v = await ask({ title: 'Spalte umbenennen', fields: [{ name: 'n', value: col.name }] }); if (v?.n.trim()) act(api('PATCH', '/columns/' + col.id, { name: v.n.trim() })); }
async function addColumn() { const v = await ask({ title: 'Neue Spalte', fields: [{ name: 'n', placeholder: 'Name' }], ok: 'Anlegen' }); if (v?.n.trim()) act(api('POST', '/columns', { board_id: S.id, name: v.n.trim() })); }
function columnMenu(col, anchor) {
  menu(anchor, [
    { label: 'Umbenennen', run: () => renameCol(col) },
    { label: 'WIP-Limit setzen …', run: async () => { const v = await ask({ title: 'WIP-Limit', text: 'Maximale Kartenzahl. Wird sie überschritten, färbt sich die Spalte rot. Leer = kein Limit.', fields: [{ name: 'n', type: 'number', min: 1, value: col.wip_limit || '' }] }); if (v) act(api('PATCH', '/columns/' + col.id, { wip_limit: v.n })); } },
    { label: 'Zählt als „Erledigt“-Spalte', check: true, checked: !!col.done, run: () => act(api('PATCH', '/columns/' + col.id, { done: col.done ? 0 : 1 })) },
    '-',
    ...[['manual', 'Sortierung: manuell'], ['date', 'Sortierung: nach Datum'], ['priority', 'Sortierung: nach Priorität']].map(([k, l]) => ({ label: l, check: true, checked: (col.sort || 'manual') === k, run: () => act(api('PATCH', '/columns/' + col.id, { sort: k })) })),
    '-',
    { label: 'Archivieren', danger: true, run: async () => { if (await confirmBox('Spalte archivieren?', `„${col.name}“ samt Karten verschwindet aus der Ansicht (im Archiv wiederherstellbar).`, 'Archivieren')) { await act(api('PATCH', '/columns/' + col.id, { archived: 1 })); undo.push({ label: 'Spalte archiviert', undo: () => api('PATCH', '/columns/' + col.id, { archived: 0 }), redo: () => api('PATCH', '/columns/' + col.id, { archived: 1 }) }); } } },
  ]);
}

// ----- Karten ziehen -----
function cardDrag(card, colById, mode) {
  return {
    container: '.cell', items: '.card', accept: c => !c.classList.contains('collapsed'),
    onDrop: async info => {
      const cell = info.container, colId = +cell.dataset.col, lane = cell.dataset.lane, col = colById[colId], sorted = col.sort && col.sort !== 'manual';
      const same = S.data.cards.filter(c => c.column_id === card.column_id).sort((a, b) => a.pos - b.pos), i = same.findIndex(c => c.id === card.id);
      const prev = { column_id: card.column_id, before_id: same[i + 1]?.id ?? null, after_id: same[i - 1]?.id ?? null };
      const body = { column_id: colId };
      if (!sorted) { if (info.before) body.before_id = +info.before.dataset.id; else if (info.after) body.after_id = +info.after.dataset.id; }
      let set, back;
      if (mode !== 'none' && lane !== laneVal(mode, card)) {
        const v = mode === 'priority' ? +lane : lane;
        set = { [mode]: v }; back = { [mode]: mode === 'priority' ? card.priority : card[mode] };
      }
      if (set) body.set = set;
      try { await api('POST', `/cards/${card.id}/move`, body); } catch (x) { fail(x); }
      undo.push({ label: 'Karte verschoben', undo: () => api('POST', `/cards/${card.id}/move`, { ...prev, ...(back ? { set: back } : {}) }), redo: () => api('POST', `/cards/${card.id}/move`, body) });
      await S.hooks.refresh();
    },
  };
}
