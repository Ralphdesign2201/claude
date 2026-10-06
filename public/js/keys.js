// Tastenkürzel, Mehrfachauswahl-Leiste
import { $, $$, el, S, api, fail, toast, undo, ask, menu, confirmBox, PRI } from './util.js';
import { toggleDone, toggleSel, labelIds } from './board.js';
import { quickDialog, helpDialog, boardDialog } from './dialogs.js';

const cardById = id => S.data?.cards.find(c => c.id === id);
function setFocus(id, scroll = true) {
  S.focus = id; $$('.card.kfocus').forEach(n => n.classList.remove('kfocus'));
  const n = $(`.card[data-id="${id}"]`); if (n) { n.classList.add('kfocus'); if (scroll) n.scrollIntoView({ block: 'nearest', inline: 'nearest' }); }
}
function nav(dir) {
  const cur = $(`.card[data-id="${S.focus}"]`);
  if (!cur) { const f = $('#content .card'); if (f) setFocus(+f.dataset.id); return; }
  const cell = cur.parentElement, sibs = [...cell.children].filter(k => k.classList.contains('card')), i = sibs.indexOf(cur);
  if (dir === 'down' && sibs[i + 1]) return setFocus(+sibs[i + 1].dataset.id);
  if (dir === 'up' && sibs[i - 1]) return setFocus(+sibs[i - 1].dataset.id);
  if (dir === 'left' || dir === 'right') {
    const cells = [...cell.parentElement.children]; let j = cells.indexOf(cell) + (dir === 'right' ? 1 : -1);
    for (; j >= 0 && j < cells.length; j += dir === 'right' ? 1 : -1) { const cs = [...cells[j].children].filter(k => k.classList.contains('card')); if (cs.length) return setFocus(+cs[Math.min(i, cs.length - 1)].dataset.id); }
  }
}
async function patchWithUndo(id, fields, label) {
  const c = cardById(id), old = Object.fromEntries(Object.keys(fields).map(k => [k, c[k]]));
  try { await api('PATCH', '/cards/' + id, fields); } catch (e) { return fail(e); }
  undo.push({ label, undo: () => api('PATCH', '/cards/' + id, old), redo: () => api('PATCH', '/cards/' + id, fields) }); await S.hooks.refresh();
}
export async function bulk(op, value, label) {
  const ids = [...S.sel]; if (!ids.length) return;
  try { await api('POST', '/cards/bulk', { ids, op, value }); } catch (e) { return fail(e); }
  if (op === 'archive') undo.push({ label: 'Archivieren', undo: () => Promise.all(ids.map(id => api('PATCH', '/cards/' + id, { archived: 0 }))), redo: () => api('POST', '/cards/bulk', { ids, op: 'archive' }) });
  if (op === 'delete') undo.push({ label: 'Löschen', undo: () => Promise.all(ids.map(id => api('POST', `/cards/${id}/restore`))), redo: () => api('POST', '/cards/bulk', { ids, op: 'delete' }) });
  toast(label || 'Erledigt'); S.sel.clear(); updateSelbar(); await S.hooks.refresh();
}
export function updateSelbar() {
  const bar = $('#selbar'), n = S.sel.size; bar.hidden = !n; if (!n) return;
  const d = S.data;
  bar.replaceChildren(el('b', { textContent: `${n} ausgewählt` }),
    el('button', { textContent: 'Verschieben ▾', onclick: e => menu(e.currentTarget, d.columns.map(c => ({ label: c.name, run: () => bulk('move', c.id, 'Verschoben') }))) }),
    el('button', { textContent: 'Label ▾', onclick: e => menu(e.currentTarget, [...d.labels.map(l => ({ label: '+ ' + l.name, run: () => bulk('label_add', l.id, 'Label gesetzt') })), '-', ...d.labels.map(l => ({ label: '− ' + l.name, run: () => bulk('label_remove', l.id, 'Label entfernt') }))]) }),
    el('button', { textContent: 'Priorität ▾', onclick: e => menu(e.currentTarget, PRI.map((p, i) => ({ label: p, run: () => bulk('priority', i, 'Priorität gesetzt') }))) }),
    el('button', { textContent: 'Person …', onclick: async () => { const v = await ask({ title: 'Person zuweisen', fields: [{ name: 'p', value: '', placeholder: 'Name (leer = niemand)' }] }); if (v) bulk('assignee', v.p, 'Zugewiesen'); } }),
    el('button', { textContent: 'Archivieren', onclick: () => bulk('archive', null, 'Archiviert') }),
    el('button', { class: 'danger', textContent: 'Löschen', onclick: () => bulk('delete', null, 'In den Papierkorb verschoben') }),
    el('button', { textContent: '✕', title: 'Auswahl aufheben', onclick: () => { S.sel.clear(); $$('.card.sel,tr.sel').forEach(n => n.classList.remove('sel')); updateSelbar(); } }));
}

export function initKeys() {
  S.hooks.selbar = updateSelbar;
  document.addEventListener('keydown', async e => {
    const typing = e.target.closest('input,textarea,select,[contenteditable]'), modal = document.querySelector('dialog[open]');
    if ((e.ctrlKey || e.metaKey) && !typing && !modal) {
      if (e.key === 'z' && !e.shiftKey) { e.preventDefault(); return undo.undo(); }
      if (e.key === 'y' || (e.key.toLowerCase() === 'z' && e.shiftKey)) { e.preventDefault(); return undo.redo(); }
    }
    if (e.key === 'Escape' && !modal) {
      if (S.sel.size) { S.sel.clear(); $$('.card.sel,tr.sel').forEach(n => n.classList.remove('sel')); updateSelbar(); } else if (S.focus) setFocus(null);
      $('#results').hidden = true; if (typing) e.target.blur(); return;
    }
    if (typing || modal || e.ctrlKey || e.metaKey) return;
    const k = e.key, id = S.focus, c = id && cardById(id);
    if (e.altKey) { const v = ['board', 'list', 'calendar', 'timeline', 'week'][+k - 1]; if (v) { e.preventDefault(); S.hooks.setView(v); } return; }
    if (k === '/') { e.preventDefault(); $('#search').focus(); }
    else if (k === 'q') { e.preventDefault(); quickDialog(); }
    else if (k === 'b') boardDialog(null);
    else if (k === '?') helpDialog();
    else if (k === 'n') { e.preventDefault(); const col = c?.column_id || S.data?.columns.find(x => !x.collapsed)?.id; if (col) { S.view = 'board'; S.adding = col; S.hooks.render(); } }
    else if (k === 'j' || k === 'ArrowDown') { e.preventDefault(); nav('down'); } else if (k === 'k' || k === 'ArrowUp') { e.preventDefault(); nav('up'); }
    else if (k === 'h' || k === 'ArrowLeft') { e.preventDefault(); nav('left'); } else if (k === 'l' || k === 'ArrowRight') { e.preventDefault(); nav('right'); }
    else if (!c) return;
    else if (k === 'Enter' || k === 'o') { e.preventDefault(); S.hooks.openCard(id); }
    else if (/^[0-3]$/.test(k)) patchWithUndo(id, { priority: +k }, 'Priorität');
    else if (k === 'x') toggleSel(id);
    else if (k === 'd') toggleDone(c);
    else if (k === 'c') { try { const r = await api('POST', `/cards/${id}/copy`); undo.push({ label: 'Kopieren', undo: () => api('DELETE', '/cards/' + r.id), redo: () => api('POST', `/cards/${r.id}/restore`) }); toast('Kopie erstellt'); await S.hooks.refresh(); } catch (x) { fail(x); } }
    else if (k === 'a') { nav('down'); S.sel = new Set([id]); await bulk('archive', null, 'Archiviert'); }
    else if (k === 'Delete' || k === 'Backspace') { e.preventDefault(); S.sel = new Set([id]); await bulk('delete', null, 'In den Papierkorb verschoben (Strg+Z macht es rückgängig)'); }
  });
}
