'use strict';
const $ = (s, r = document) => r.querySelector(s);
const el = (tag, props = {}, ...kids) => {
  const { dataset, ...rest } = props;
  const e = Object.assign(document.createElement(tag), rest);
  if (dataset) Object.assign(e.dataset, dataset);
  kids.flat().forEach(k => e.append(k));
  return e;
};
const api = async (method, url, body) => {
  const r = await fetch('/api' + url, { method, headers: { 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined });
  if (!r.ok) throw new Error((await r.json().catch(() => ({}))).error || r.statusText);
  return r.json();
};
const toast = (msg) => { const t = $('#toast'); t.textContent = msg; t.hidden = false; clearTimeout(toast.t); toast.t = setTimeout(() => t.hidden = true, 2500); };
const PRI = ['Keine', 'Niedrig', 'Hoch', 'Dringend'];
const COLORS = ['#4f7cff', '#2fa66a', '#e08a1e', '#d93a3a', '#8a5cf5', '#14a8b8', '#6b7285'];
const ICONS = ['▦', '★', '⚑', '✎', '⌂', '☕', '♥', '⚙'];

const S = { boards: [], id: null, data: null };

// ---------- Laden ----------
async function loadBoards() { S.boards = await api('GET', '/boards'); }
async function loadBoard(id) {
  if (id != null) S.id = id;
  if (!S.boards.some(b => b.id === S.id)) S.id = S.boards[0]?.id;
  S.data = S.id ? await api('GET', '/boards/' + S.id) : null;
  try { localStorage.setItem('board', S.id); } catch {}
  render();
}
async function refresh() { await loadBoards(); await loadBoard(); }
const act = (p) => p.then(() => loadBoard()).catch(e => toast('Fehler: ' + e.message));

// ---------- Darstellung ----------
function applyTheme(t) { if (t) document.documentElement.dataset.theme = t; else delete document.documentElement.dataset.theme; }
try { applyTheme(localStorage.getItem('theme')); } catch {}
$('#theme').onclick = () => {
  const cur = document.documentElement.dataset.theme;
  const next = cur === 'dark' ? 'light' : 'dark';
  applyTheme(next); try { localStorage.setItem('theme', next); } catch {}
};

// ---------- Rendern ----------
function render() {
  const nav = $('#boards'); nav.replaceChildren();
  for (const b of S.boards) {
    nav.append(el('div', { className: 'bitem' + (b.id === S.id ? ' on' : ''), onclick: () => loadBoard(b.id) },
      el('i', { textContent: b.icon, style: 'background:' + b.color }), el('span', { textContent: b.name })));
  }
  const d = S.data, boardEl = $('#board'); boardEl.replaceChildren();
  $('#boardTitle').textContent = d ? d.board.name : '';
  const menu = $('#boardMenu'); menu.replaceChildren();
  if (!d) { boardEl.append(el('p', { textContent: 'Kein Board vorhanden. Lege links ein neues an.' })); return; }
  menu.append(el('button', { className: 'ghost', textContent: '⚙ Board', onclick: () => boardDialog(d.board) }));
  for (const c of d.columns) boardEl.append(columnEl(c, d.cards.filter(k => k.column_id === c.id)));
  boardEl.append(el('div', { className: 'newcol' }, el('button', { textContent: '+ Spalte', onclick: async () => {
    const name = prompt('Name der neuen Spalte:'); if (name) act(api('POST', '/columns', { board_id: S.id, name }));
  } })));
}

function columnEl(c, cards) {
  const over = c.wip_limit && cards.length > c.wip_limit;
  const col = el('div', { className: 'col' + (over ? ' over-wip' : '') + (c.collapsed ? ' collapsed' : ''), dataset: { id: c.id } });
  const head = el('div', { className: 'col-head' },
    el('span', { className: 'grip', textContent: '⠿', title: 'Spalte verschieben' }),
    el('span', { className: 'name', textContent: c.name, ondblclick: () => rename(c) }),
    el('span', { className: 'count', textContent: c.wip_limit ? `${cards.length}/${c.wip_limit}` : cards.length }),
    el('button', { textContent: c.collapsed ? '▸' : '▾', title: 'Ein-/Ausklappen', onclick: () => act(api('PATCH', '/columns/' + c.id, { collapsed: c.collapsed ? 0 : 1 })) }),
    el('button', { textContent: '⋯', title: 'Spaltenoptionen', onclick: () => columnMenu(c) }));
  const body = el('div', { className: 'col-body' }, cards.map(cardEl));
  const input = el('input', { placeholder: '+ Karte hinzufügen', onkeydown: (e) => {
    if (e.key === 'Enter' && input.value.trim()) { const t = input.value.trim(); input.value = ''; act(api('POST', '/cards', { column_id: c.id, title: t })).then(() => $(`.col[data-id="${c.id}"] .add input`)?.focus()); }
  } });
  col.append(head, body, el('div', { className: 'add' }, input));
  head.querySelector('.grip').addEventListener('pointerdown', e => startDrag(e, 'col', col));
  return col;
}

async function rename(c) {
  const name = prompt('Neuer Name der Spalte:', c.name); if (name && name.trim()) act(api('PATCH', '/columns/' + c.id, { name: name.trim() }));
}
function columnMenu(c) {
  const a = prompt(`Spalte „${c.name}“\n1 = Umbenennen\n2 = WIP-Limit setzen\n3 = Archivieren`, '1');
  if (a === '1') rename(c);
  else if (a === '2') {
    const v = prompt('WIP-Limit (Zahl, leer = kein Limit):', c.wip_limit || '');
    if (v !== null) act(api('PATCH', '/columns/' + c.id, { wip_limit: parseInt(v, 10) > 0 ? parseInt(v, 10) : null }));
  } else if (a === '3' && confirm('Spalte archivieren? Ihre Karten verschwinden aus der Ansicht.')) act(api('PATCH', '/columns/' + c.id, { archived: 1 }));
}

const todayStr = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
const fmtDate = s => s ? s.split('-').reverse().join('.') : '';
function cardEl(k) {
  const overdue = k.due_date && k.due_date < todayStr();
  const c = el('div', { className: 'card' + (overdue ? ' due-over' : ''), dataset: { id: k.id } },
    k.priority ? el('div', { className: 'pri p' + k.priority, title: 'Priorität: ' + PRI[k.priority] }) : '',
    el('div', { className: 't', textContent: k.title }),
    (k.due_date || k.description) ? el('div', { className: 'meta' }, k.due_date ? el('span', { className: 'due', textContent: '⏱ ' + fmtDate(k.due_date) }) : '', k.description ? el('span', { textContent: '≡' }) : '') : '');
  c.addEventListener('pointerdown', e => startDrag(e, 'card', c, k));
  return c;
}

// ---------- Drag & Drop (Maus und Touch) ----------
function startDrag(e, kind, node, card) {
  if (e.button > 0 || e.target.closest('input,button')) return;
  const isTouch = e.pointerType === 'touch', sx = e.clientX, sy = e.clientY;
  let started = false, ghost, ph, timer, lastTarget = null;
  const rect = node.getBoundingClientRect(), ox = sx - rect.left, oy = sy - rect.top;
  const blockScroll = ev => { if (started) ev.preventDefault(); };

  const begin = () => {
    started = true;
    ghost = node.cloneNode(true); ghost.className += ' ghost-card';
    ghost.style.cssText = `width:${rect.width}px;left:${rect.left}px;top:${rect.top}px`;
    document.body.append(ghost);
    ph = el('div', { className: kind === 'card' ? 'ph' : 'ph ph-col' });
    if (kind === 'card') ph.style.height = rect.height + 'px';
    node.after(ph); node.hidden = true;
    document.body.style.userSelect = 'none';
  };
  const pos = (ev) => {
    ghost.style.left = ev.clientX - ox + 'px'; ghost.style.top = ev.clientY - oy + 'px';
    if (kind === 'card') {
      const under = document.elementFromPoint(ev.clientX, ev.clientY);
      const body = under?.closest('.col:not(.collapsed) .col-body') || (under?.closest('.col:not(.collapsed)')?.querySelector('.col-body'));
      if (!body) return;
      const kids = [...body.children].filter(k => k !== ph && k !== node && k.classList.contains('card'));
      const before = kids.find(k => { const r = k.getBoundingClientRect(); return ev.clientY < r.top + r.height / 2; });
      before ? body.insertBefore(ph, before) : body.append(ph);
    } else {
      const bd = $('#board'), kids = [...bd.children].filter(k => k.classList.contains('col') && k !== node);
      const before = kids.find(k => { const r = k.getBoundingClientRect(); return ev.clientX < r.left + r.width / 2; });
      before ? bd.insertBefore(ph, before) : bd.insertBefore(ph, bd.querySelector('.newcol'));
    }
    const bd = $('#board'), br = bd.getBoundingClientRect();
    if (ev.clientX > br.right - 40) bd.scrollLeft += 12; else if (ev.clientX < br.left + 40) bd.scrollLeft -= 12;
  };
  const move = ev => {
    if (!started) {
      if (Math.hypot(ev.clientX - sx, ev.clientY - sy) > 6) { if (isTouch) cleanup(); else begin(); }
      if (!started) return;
    }
    pos(ev);
  };
  const end = async (ev) => {
    const wasStarted = started; cleanup();
    if (!wasStarted) { if (kind === 'card' && ev.type === 'pointerup') cardDialog(card); return; }
    let call;
    if (kind === 'card') {
      const body = ph.parentElement, colId = Number(body.closest('.col').dataset.id);
      let index = 0;
      for (const k of body.children) { if (k === ph) break; if (k.classList.contains('card') && k !== node) index++; }
      call = api('POST', `/cards/${card.id}/move`, { column_id: colId, index });
    } else {
      const cols = [...$('#board').children].filter(k => k.classList.contains('col') || k === ph);
      call = api('POST', `/columns/${node.dataset.id}/move`, { index: cols.filter(k => k !== node).indexOf(ph) });
    }
    ph.remove(); node.hidden = false;
    await act(call);
  };
  function cleanup() {
    clearTimeout(timer);
    removeEventListener('pointermove', move); removeEventListener('pointerup', end); removeEventListener('pointercancel', end);
    removeEventListener('touchmove', blockScroll);
    ghost?.remove(); document.body.style.userSelect = '';
  }
  addEventListener('pointermove', move); addEventListener('pointerup', end); addEventListener('pointercancel', end);
  if (isTouch) {
    // Touch: langes Drücken startet das Ziehen, sonst scrollt die Seite normal
    addEventListener('touchmove', blockScroll, { passive: false });
    timer = setTimeout(() => { removeEventListener('pointermove', move); addEventListener('pointermove', move); begin(); }, 350);
    node.style.touchAction = 'pan-x pan-y';
  } else e.preventDefault();
}

// ---------- Kartenfenster ----------
function cardDialog(k) {
  const dlg = $('#cardDlg');
  const t = el('input', { value: k.title }), desc = el('textarea', { value: k.description || '', placeholder: 'Beschreibung (Markdown-Text)' });
  const pri = el('select', {}, PRI.map((n, i) => el('option', { value: i, textContent: n, selected: i === k.priority })));
  const due = el('input', { type: 'date', value: k.due_date || '' });
  const save = async () => { await act(api('PATCH', '/cards/' + k.id, { title: t.value.trim() || k.title, description: desc.value, priority: Number(pri.value), due_date: due.value || null })); dlg.close(); };
  dlg.replaceChildren(
    el('label', { textContent: 'Titel' }), t,
    el('label', { textContent: 'Beschreibung' }), desc,
    el('div', { className: 'row' }, el('div', {}, el('label', { textContent: 'Priorität' }), pri), el('div', {}, el('label', { textContent: 'Fällig am' }), due)),
    el('div', { className: 'actions' },
      el('button', { className: 'danger', textContent: 'Löschen', onclick: async () => { await act(api('DELETE', '/cards/' + k.id)); dlg.close(); toast('In den Papierkorb verschoben'); } }),
      el('button', { textContent: 'Archivieren', onclick: async () => { await act(api('PATCH', '/cards/' + k.id, { archived: 1 })); dlg.close(); } }),
      el('span', { className: 'sp' }),
      el('button', { textContent: 'Abbrechen', onclick: () => dlg.close() }),
      el('button', { className: 'primary', textContent: 'Speichern', onclick: save })));
  dlg.onkeydown = e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) save(); };
  dlg.showModal(); t.focus();
}

// ---------- Boarddialog ----------
const TEMPL = { leer: 'Leer (Zu erledigen / In Arbeit / Erledigt)', webprojekt: 'Webprojekt (Briefing … Fertig)' };
function boardDialog(b) {
  const dlg = $('#boardDlg'), isNew = !b;
  let color = b?.color || COLORS[0], icon = b?.icon || ICONS[0];
  const name = el('input', { value: b?.name || '', placeholder: 'Name des Boards' });
  const tpl = el('select', {}, Object.entries(TEMPL).map(([v, n]) => el('option', { value: v, textContent: n })));
  const colorsEl = el('div', { className: 'colors' }), iconsEl = el('div', { className: 'colors' });
  const draw = () => {
    colorsEl.replaceChildren(...COLORS.map(c => el('span', { style: 'background:' + c, className: c === color ? 'on' : '', onclick: () => { color = c; draw(); } })));
    iconsEl.replaceChildren(...ICONS.map(i => el('span', { textContent: i, style: 'border-radius:6px;text-align:center;background:var(--col);width:30px;height:30px;line-height:24px', className: i === icon ? 'on' : '', onclick: () => { icon = i; draw(); } })));
  };
  draw();
  const save = async () => {
    if (!name.value.trim()) return name.focus();
    if (isNew) { const r = await api('POST', '/boards', { name: name.value.trim(), color, icon, template: tpl.value }); await loadBoards(); await loadBoard(r.id); }
    else { await api('PATCH', '/boards/' + b.id, { name: name.value.trim(), color, icon }); await refresh(); }
    dlg.close();
  };
  dlg.replaceChildren(
    el('h3', { textContent: isNew ? 'Neues Board' : 'Board bearbeiten' }),
    el('label', { textContent: 'Name' }), name,
    ...(isNew ? [el('label', { textContent: 'Vorlage' }), tpl] : []),
    el('label', { textContent: 'Farbe' }), colorsEl, el('label', { textContent: 'Symbol' }), iconsEl,
    el('div', { className: 'actions' },
      ...(isNew ? [] : [
        el('button', { textContent: 'Duplizieren', onclick: async () => { const r = await api('POST', `/boards/${b.id}/duplicate`); await loadBoards(); await loadBoard(r.id); dlg.close(); } }),
        el('button', { className: 'danger', textContent: 'Archivieren', onclick: async () => { if (!confirm('Board archivieren?')) return; await api('PATCH', '/boards/' + b.id, { archived: 1 }); dlg.close(); await refresh(); } })]),
      el('span', { className: 'sp' }),
      el('button', { textContent: 'Abbrechen', onclick: () => dlg.close() }),
      el('button', { className: 'primary', textContent: 'Speichern', onclick: save })));
  dlg.onkeydown = e => { if (e.key === 'Enter' && e.target.tagName === 'INPUT') save(); };
  dlg.showModal(); name.focus();
}
$('#newBoard').onclick = () => boardDialog(null);

// ---------- Suche ----------
let st;
$('#search').addEventListener('input', e => {
  clearTimeout(st);
  const q = e.target.value.trim(), box = $('#results');
  if (!q) { box.hidden = true; return; }
  st = setTimeout(async () => {
    const rs = await api('GET', '/search?q=' + encodeURIComponent(q));
    box.replaceChildren(...(rs.length ? rs.map(r => el('div', { onclick: async () => { box.hidden = true; $('#search').value = ''; await loadBoard(r.board_id); }, },
      r.title, el('small', { textContent: `${r.board_name} › ${r.column_name}` }))) : [el('div', { textContent: 'Nichts gefunden.' })]));
    box.hidden = false;
  }, 200);
});
document.addEventListener('click', e => { if (!e.target.closest('#results,#search')) $('#results').hidden = true; });

// ---------- Papierkorb, Sicherung, Export ----------
$('#trash').onclick = async () => {
  const dlg = $('#trashDlg'), items = await api('GET', '/trash');
  dlg.replaceChildren(el('h3', { textContent: 'Papierkorb' }),
    ...(items.length ? items.map(i => el('div', { className: 'actions' }, el('span', { className: 'sp', textContent: i.title }),
      el('button', { textContent: 'Wiederherstellen', onclick: async () => { await api('POST', `/cards/${i.id}/restore`); dlg.close(); await loadBoard(); } }))) : [el('p', { textContent: 'Leer.' })]),
    el('div', { className: 'actions' }, el('span', { className: 'sp' }), el('button', { textContent: 'Schließen', onclick: () => dlg.close() })));
  dlg.showModal();
};
$('#backup').onclick = async () => { const r = await api('POST', '/backup'); toast('Gesichert: ' + r.file.split(/[\\/]/).pop()); };
$('#export').onclick = async () => {
  const data = await api('GET', '/export');
  const a = el('a', { href: URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })), download: `kanban-export-${todayStr()}.json` });
  a.click(); URL.revokeObjectURL(a.href);
};

// ---------- Tastaturkürzel ----------
document.addEventListener('keydown', e => {
  if (e.target.closest('input,textarea,select') || e.ctrlKey || e.metaKey || e.altKey || document.querySelector('dialog[open]')) return;
  if (e.key === '/') { e.preventDefault(); $('#search').focus(); }
  else if (e.key === 'n') { e.preventDefault(); const i = $('.col:not(.collapsed) .add input'); i?.focus(); }
  else if (e.key === 'b') boardDialog(null);
});

(async () => {
  await loadBoards();
  let saved = null; try { saved = Number(localStorage.getItem('board')); } catch {}
  await loadBoard(S.boards.some(b => b.id === saved) ? saved : S.boards[0]?.id);
})();
