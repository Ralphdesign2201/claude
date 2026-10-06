// Kartenfenster: Beschreibung, Checklisten, Zeit, Anhänge, Kommentare, Verknüpfungen, Verlauf
import { $, $$, el, S, api, fail, toast, undo, ask, confirmBox, menu, fmtDate, fmtDateTime, fmtDur, utc, PRI, COLORS, textOn } from './util.js';
import { md } from './md.js';
import { startDrag } from './dnd.js';
import { labelIds } from './board.js';

let D = null, tick = null;
const LINKS = { blocks: ['blockiert', 'wird blockiert von'], part_of: ['gehört zu', 'enthält'], duplicate: ['Duplikat von', 'hat Duplikat'] };
const colCache = {};

export const parseMinutes = s => {
  s = String(s || '').trim().toLowerCase().replace(',', '.'); if (!s) return null;
  let m;
  if ((m = s.match(/^(\d+):(\d{1,2})$/))) return +m[1] * 60 + +m[2];
  if ((m = s.match(/^(\d+(?:\.\d+)?)\s*h(?:\s*(\d+)\s*m?(?:in)?)?$/))) return Math.round(+m[1] * 60 + (+m[2] || 0));
  if ((m = s.match(/^(\d+(?:\.\d+)?)\s*m(?:in)?$/))) return Math.round(+m[1]);
  return /^\d+(\.\d+)?$/.test(s) ? Math.round(+s) : NaN;
};
const fmtMin = m => m ? `${Math.floor(m / 60)}:${String(m % 60).padStart(2, '0')}` : '';

export async function openCard(id) {
  try { D = await api('GET', '/cards/' + id); } catch (e) { return fail(e); }
  const dlg = $('#cardDlg');
  draw();
  if (!dlg.open) { dlg.showModal(); dlg.addEventListener('close', onClose, { once: true }); }
}
function onClose() { clearInterval(tick); D = null; S.hooks.refresh(); }
async function reload() { const y = $('#cardDlg .dlg-scroll')?.scrollTop; D = await api('GET', '/cards/' + D.card.id); draw(); const s = $('#cardDlg .dlg-scroll'); if (s && y) s.scrollTop = y; }
const run = async (p, then = reload) => { try { await p; await then(); S.hooks.refresh(); } catch (e) { fail(e); } };

async function save(fields, label = 'Karte bearbeitet') {
  const old = {}; for (const k of Object.keys(fields)) old[k] = D.card[k];
  const id = D.card.id;
  try { await api('PATCH', '/cards/' + id, fields); } catch (e) { return fail(e); }
  Object.assign(D.card, fields);
  undo.push({ label, undo: () => api('PATCH', '/cards/' + id, old), redo: () => api('PATCH', '/cards/' + id, fields) });
  S.hooks.refresh();
}
const sec = (title, ...kids) => el('section', { class: 'sec' }, title ? el('h4', { textContent: title }) : '', ...kids);
const field = (label, input) => el('div', { class: 'f' }, el('label', { textContent: label }), input);

function draw() {
  const c = D.card, dlg = $('#cardDlg'), me = S.session.user?.name || S.session.display_name;
  clearInterval(tick);
  const title = el('input', { class: 'title', value: c.title, onchange: e => { if (e.target.value.trim()) save({ title: e.target.value.trim() }); else e.target.value = c.title; } });
  dlg.replaceChildren(
    el('div', { class: 'dlg-head' }, title,
      el('button', { class: c.done_at ? 'primary' : '', textContent: c.done_at ? '✓ Erledigt' : '○ Erledigen', onclick: () => run(api('POST', `/cards/${c.id}/done`, { done: !c.done_at })) }),
      el('button', { textContent: '🖨', title: 'Drucken / als PDF speichern', onclick: () => { document.body.classList.add('print-card'); print(); setTimeout(() => document.body.classList.remove('print-card'), 500); } }),
      el('button', { textContent: '✕', title: 'Schließen', onclick: () => dlg.close() })),
    el('div', { class: 'dlg-scroll' }, el('div', { class: 'dlg-grid' },
      el('div', { class: 'main' }, descSec(), checklistSec(), subtaskSec(), linkSec(), attachSec(), commentSec(), activitySec()),
      el('aside', { class: 'props' }, propsSec(), timeSec(me), labelSec(), actionSec()))));
  if (D.time.some(t => !t.ended_at)) tick = setInterval(() => { const n = $('#liveTimer'); if (n) n.textContent = elapsed(); }, 1000);
}
const elapsed = () => { const t = D.time.find(t => !t.ended_at); return t ? fmtClock((Date.now() - utc(t.started_at)) / 1000) : ''; };
const fmtClock = s => { s = Math.max(0, Math.floor(s)); return `${Math.floor(s / 3600)}:${String(Math.floor(s % 3600 / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`; };

// ----- Beschreibung (Markdown) -----
function descSec() {
  const c = D.card, box = el('div');
  let editing = !c.description;
  const view = () => {
    box.replaceChildren(el('div', { class: 'md', innerHTML: md(c.description) }), el('div', { class: 'row-r' }, el('button', { class: 'ghost', textContent: '✎ Bearbeiten', onclick: () => { editing = true; edit(); } })));
  };
  const edit = () => {
    const ta = el('textarea', { class: 'mdedit', value: c.description || '', rows: 8, placeholder: 'Beschreibung – Markdown: # Überschrift, **fett**, *kursiv*, - Liste, - [ ] Aufgabe, `Code`, [Text](https://…)' });
    const wrap = (a, b = a, ph = 'Text') => { const s = ta.selectionStart, e = ta.selectionEnd, sel = ta.value.slice(s, e) || ph; ta.setRangeText(a + sel + b, s, e, 'end'); ta.setSelectionRange(s + a.length, s + a.length + sel.length); ta.focus(); };
    const lineP = p => { const s = ta.value.lastIndexOf('\n', ta.selectionStart - 1) + 1; ta.setRangeText(p, s, s, 'end'); ta.focus(); };
    const tools = [['B', () => wrap('**'), 'Fett (Strg+B)'], ['I', () => wrap('*'), 'Kursiv (Strg+I)'], ['H', () => lineP('## '), 'Überschrift'], ['•', () => lineP('- '), 'Liste'], ['1.', () => lineP('1. '), 'Nummeriert'], ['☐', () => lineP('- [ ] '), 'Aufgabe'], ['</>', () => wrap('`'), 'Code'], ['🔗', () => wrap('[', '](https://)', 'Text'), 'Link (Strg+K)']];
    const prev = el('div', { class: 'md preview', hidden: true });
    ta.onkeydown = e => {
      if ((e.ctrlKey || e.metaKey) && e.key === 'b') { e.preventDefault(); wrap('**'); } else if ((e.ctrlKey || e.metaKey) && e.key === 'i') { e.preventDefault(); wrap('*'); } else if ((e.ctrlKey || e.metaKey) && e.key === 'k') { e.preventDefault(); wrap('[', '](https://)', 'Text'); }
      else if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey) {
        const s = ta.selectionStart, ls = ta.value.lastIndexOf('\n', s - 1) + 1, line = ta.value.slice(ls, s), m = line.match(/^(\s*)([-*]|\d+\.)\s(\[[ x]\]\s)?(.*)$/);
        if (m) { e.preventDefault(); if (!m[4]) ta.setRangeText('', ls, s, 'end'); else { const mark = /\d/.test(m[2]) ? (parseInt(m[2], 10) + 1) + '.' : m[2]; ta.setRangeText('\n' + m[1] + mark + ' ' + (m[3] ? '[ ] ' : ''), s, s, 'end'); } }
      } else if (e.key === 'Escape') { e.stopPropagation(); finish(); }
    };
    const finish = async () => { if (ta.value !== (c.description || '')) await save({ description: ta.value }, 'Beschreibung bearbeitet'); editing = false; c.description ? view() : edit(); if (!c.description) editing = true; };
    box.replaceChildren(el('div', { class: 'toolbar' }, tools.map(([l, f, t]) => el('button', { textContent: l, title: t, onclick: f })), el('span', { class: 'sp' }),
      el('button', { textContent: '👁 Vorschau', onclick: e => { prev.hidden = !prev.hidden; ta.hidden = !prev.hidden; prev.innerHTML = md(ta.value); e.target.textContent = prev.hidden ? '👁 Vorschau' : '✎ Text'; } })),
      ta, prev, el('div', { class: 'row-r' }, el('button', { class: 'primary', textContent: 'Fertig', onclick: finish })));
    ta.addEventListener('blur', () => { if (ta.value !== (c.description || '')) save({ description: ta.value }, 'Beschreibung bearbeitet'); });
  };
  editing ? edit() : view();
  return sec('Beschreibung', box);
}

// ----- Checklisten -----
function checklistSec() {
  const box = sec('Checklisten');
  for (const l of D.checklists) {
    const done = l.items.filter(i => i.done).length, pct = l.items.length ? Math.round(100 * done / l.items.length) : 0;
    const list = el('div', { class: 'cl-items', dataset: { cl: l.id } });
    for (const i of l.items) {
      const row = el('div', { class: 'cl-item' + (i.done ? ' done' : ''), dataset: { id: i.id } },
        el('span', { class: 'grip', textContent: '⠿', title: 'Ziehen zum Sortieren' }),
        el('input', { type: 'checkbox', checked: !!i.done, onchange: e => run(api('PATCH', '/items/' + i.id, { done: e.target.checked })) }),
        el('input', { class: 'txt', value: i.text, onchange: e => run(api('PATCH', '/items/' + i.id, { text: e.target.value }), async () => {}) }),
        el('button', { class: 'x', textContent: '✕', title: 'Entfernen', onclick: () => run(api('DELETE', '/items/' + i.id)) }));
      const grip = row.querySelector('.grip'); grip.style.touchAction = 'none';
      grip.addEventListener('pointerdown', e => startDrag(e, { node: row, container: '.cl-items', items: '.cl-item', immediate: true, onDrop: info => run(api('POST', `/items/${i.id}/move`, { checklist_id: +info.container.dataset.cl, before_id: info.before ? +info.before.dataset.id : null })) }));
      list.append(row);
    }
    const add = el('input', { class: 'add', placeholder: '+ Punkt hinzufügen (Enter)', onkeydown: e => { if (e.key === 'Enter' && e.target.value.trim()) { const t = e.target.value; run(api('POST', `/checklists/${l.id}/items`, { text: t }), async () => { await reload(); $$('.cl-block')[D.checklists.indexOf(D.checklists.find(x => x.id === l.id))]?.querySelector('.add')?.focus(); }); } } });
    box.append(el('div', { class: 'cl-block' },
      el('div', { class: 'cl-head' }, el('input', { class: 'cl-title', value: l.title, onchange: e => run(api('PATCH', '/checklists/' + l.id, { title: e.target.value }), async () => {}) }), el('span', { class: 'muted', textContent: `${done}/${l.items.length}` }),
        el('button', { class: 'x', textContent: '🗑', title: 'Checkliste löschen', onclick: async () => { if (await confirmBox('Checkliste löschen?', `„${l.title}“ mit ${l.items.length} Punkten.`)) run(api('DELETE', '/checklists/' + l.id)); } })),
      el('div', { class: 'bar big' }, el('i', { style: `width:${pct}%` })), list, add));
  }
  box.append(el('button', { class: 'ghost', textContent: '+ Checkliste', onclick: async () => { const v = await ask({ title: 'Neue Checkliste', fields: [{ name: 't', value: 'Checkliste' }], ok: 'Anlegen' }); if (v) run(api('POST', `/cards/${D.card.id}/checklists`, { title: v.t })); } }));
  return box;
}

// ----- Unteraufgaben -----
function subtaskSec() {
  const c = D.card;
  const add = el('input', { class: 'add', placeholder: '+ Unteraufgabe (eigene Karte, Enter)', onkeydown: e => { if (e.key === 'Enter' && e.target.value.trim()) run(api('POST', '/cards', { column_id: c.column_id, title: e.target.value, fields: { parent_id: c.id } })); } });
  return sec(`Unteraufgaben${D.subtasks.length ? ` (${D.subtasks.filter(s => s.done_at).length}/${D.subtasks.length})` : ''}`,
    c.parent_id ? el('div', { class: 'muted', textContent: 'Gehört zu: ' }, el('a', { href: '#', textContent: c.parent_title, onclick: e => { e.preventDefault(); openCard(c.parent_id); } })) : '',
    ...D.subtasks.map(s => el('div', { class: 'sub' + (s.done_at ? ' done' : '') },
      el('input', { type: 'checkbox', checked: !!s.done_at, onchange: e => run(api('POST', `/cards/${s.id}/done`, { done: e.target.checked })) }),
      el('a', { href: '#', textContent: s.title, onclick: e => { e.preventDefault(); openCard(s.id); } }), el('span', { class: 'muted', textContent: s.column_name }))), add);
}

// ----- Verknüpfungen -----
function linkSec() {
  const type = el('select', {}, Object.entries(LINKS).map(([k, v]) => el('option', { value: k, textContent: v[0] })));
  const res = el('div', { class: 'linkres' }); let t;
  const q = el('input', { placeholder: 'Karte suchen …', oninput: e => { clearTimeout(t); t = setTimeout(async () => {
    if (!e.target.value.trim()) return res.replaceChildren();
    const rs = (await api('GET', '/search?q=' + encodeURIComponent(e.target.value))).filter(r => r.id !== D.card.id);
    res.replaceChildren(...rs.slice(0, 8).map(r => el('div', { textContent: `${r.title}  ·  ${r.board_name}`, onclick: () => run(api('POST', `/cards/${D.card.id}/links`, { type: type.value, to_card: r.id })) })));
  }, 200); } });
  return sec('Verknüpfungen', ...D.links.map(l => el('div', { class: 'link' }, el('span', { class: 'muted', textContent: LINKS[l.type][l.dir === 'out' ? 0 : 1] }),
    el('a', { href: '#', textContent: ` #${l.card.id} ${l.card.title}`, class: l.card.done_at ? 'strike' : '', onclick: e => { e.preventDefault(); openCard(l.card.id); } }), el('span', { class: 'muted', textContent: '  ' + l.card.board_name }),
    el('button', { class: 'x', textContent: '✕', onclick: () => run(api('DELETE', '/links/' + l.id)) }))), el('div', { class: 'row' }, type, q), res);
}

// ----- Anhänge -----
async function upload(files) {
  for (const f of files) {
    if (f.size > 100e6) { toast(`${f.name}: zu groß (max. 100 MB)`); continue; }
    try { await api('POST', `/cards/${D.card.id}/attachments`, f, { raw: true, headers: { 'Content-Type': f.type || 'application/octet-stream', 'X-Filename': encodeURIComponent(f.name) } }); } catch (e) { fail(e); }
  }
  await reload(); S.hooks.refresh();
}
function preview(a) {
  const dlg = $('#previewDlg'), url = `/api/attachments/${a.id}`;
  let body;
  if (/^image\//.test(a.mime)) body = el('img', { src: url, alt: a.name });
  else if (a.mime === 'application/pdf') body = el('iframe', { src: url, class: 'pdf' });
  else if (/^(video|audio)\//.test(a.mime)) body = el(a.mime.startsWith('video') ? 'video' : 'audio', { src: url, controls: true });
  else if (a.mime === 'text/plain') { body = el('pre', { textContent: 'Lade …' }); fetch(url).then(r => r.text()).then(t => body.textContent = t); }
  else body = el('p', { textContent: 'Für diesen Dateityp gibt es keine Vorschau.' });
  dlg.replaceChildren(el('div', { class: 'dlg-head' }, el('b', { class: 'grow', textContent: a.name }), el('a', { class: 'btn', href: url + '?dl=1', textContent: '⤓ Herunterladen' }), el('button', { textContent: '✕', onclick: () => dlg.close() })), el('div', { class: 'pv' }, body));
  dlg.showModal();
}
function attachSec() {
  const input = el('input', { type: 'file', multiple: true, hidden: true, onchange: e => upload([...e.target.files]) });
  const drop = el('div', { class: 'drop', textContent: 'Dateien hierher ziehen, einfügen (Strg+V) oder klicken', onclick: () => input.click() });
  drop.ondragover = e => { e.preventDefault(); drop.classList.add('over'); }; drop.ondragleave = () => drop.classList.remove('over');
  drop.ondrop = e => { e.preventDefault(); drop.classList.remove('over'); upload([...e.dataTransfer.files]); };
  $('#cardDlg').onpaste = e => { const fs = [...(e.clipboardData?.files || [])]; if (fs.length) { e.preventDefault(); upload(fs); } };
  return sec(`Anhänge${D.attachments.length ? ` (${D.attachments.length})` : ''}`, el('div', { class: 'atts' }, D.attachments.map(a => el('div', { class: 'att' },
    /^image\//.test(a.mime) ? el('img', { src: `/api/attachments/${a.id}`, alt: '', loading: 'lazy', onclick: () => preview(a) }) : el('div', { class: 'ico', textContent: '📄', onclick: () => preview(a) }),
    el('a', { href: '#', textContent: a.name, title: a.name, onclick: e => { e.preventDefault(); preview(a); } }), el('span', { class: 'muted', textContent: fmtSize(a.size) }),
    el('button', { class: 'x', textContent: '✕', onclick: async () => { if (await confirmBox('Anhang löschen?', a.name)) run(api('DELETE', '/attachments/' + a.id)); } })))), drop, input);
}
const fmtSize = n => n > 1e6 ? (n / 1e6).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1e3)) + ' KB';

// ----- Kommentare -----
function commentSec() {
  const ta = el('textarea', { rows: 2, placeholder: 'Kommentar schreiben … (Strg+Enter sendet)' });
  const send = () => { if (ta.value.trim()) run(api('POST', `/cards/${D.card.id}/comments`, { body: ta.value })); };
  ta.onkeydown = e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) send(); };
  return sec(`Kommentare${D.comments.length ? ` (${D.comments.length})` : ''}`, ...D.comments.map(m => {
    const body = el('div', { class: 'md', innerHTML: md(m.body) });
    const box = el('div', { class: 'comment' }, el('div', { class: 'cmeta' }, el('b', { textContent: m.user || '–' }), ` ${fmtDateTime(m.created_at)}${m.edited_at ? ' (bearbeitet)' : ''} `,
      el('a', { href: '#', textContent: 'Bearbeiten', onclick: e => { e.preventDefault(); const t = el('textarea', { rows: 3, value: m.body });
        box.replaceChildren(t, el('div', { class: 'row-r' }, el('button', { textContent: 'Abbrechen', onclick: () => draw() }), el('button', { class: 'primary', textContent: 'Speichern', onclick: () => run(api('PATCH', '/comments/' + m.id, { body: t.value })) }))); t.focus(); } }),
      ' · ', el('a', { href: '#', textContent: 'Löschen', onclick: async e => { e.preventDefault(); if (await confirmBox('Kommentar löschen?', '')) run(api('DELETE', '/comments/' + m.id)); } })), body);
    return box;
  }), ta, el('div', { class: 'row-r' }, el('button', { class: 'primary', textContent: 'Kommentieren', onclick: send })));
}
function activitySec() {
  const s = el('details', { class: 'sec' }, el('summary', { textContent: `Aktivität (${D.activity.length})` }), ...D.activity.map(a => el('div', { class: 'act' }, el('span', { class: 'muted', textContent: fmtDateTime(a.at) + ' · ' }), el('b', { textContent: a.user || '–' }), ' ' + a.text)));
  return s;
}

// ----- Eigenschaften -----
async function columnsOf(boardId) { return colCache[boardId] ||= (await api('GET', '/boards/' + boardId)).columns; }
function propsSec() {
  const c = D.card, d = S.data;
  const boardSel = el('select', {}, S.boards.map(b => el('option', { value: b.id, textContent: b.name, selected: b.id === D.board.id })));
  const colSel = el('select');
  const fillCols = async () => { const cols = await columnsOf(boardSel.value); colSel.replaceChildren(...cols.map(k => el('option', { value: k.id, textContent: k.name, selected: k.id === c.column_id }))); };
  fillCols();
  boardSel.onchange = async () => { delete colCache[boardSel.value]; await fillCols(); colSel.dispatchEvent(new Event('change')); };
  colSel.onchange = () => run(api('POST', `/cards/${c.id}/move`, { column_id: +colSel.value }), async () => { delete colCache[boardSel.value]; await reload(); });
  const people = el('datalist', { id: 'dlPeople' }, (d?.people || []).map(p => el('option', { value: p })));
  const custs = el('datalist', { id: 'dlCust' }, (d?.customers || []).map(p => el('option', { value: p })));
  const crm = S.settings.crm_url && c.crm_ref ? el('a', { href: S.settings.crm_url.replace('{ref}', encodeURIComponent(c.crm_ref)), target: '_blank', rel: 'noopener noreferrer', textContent: '↗ im CRM öffnen' }) : '';
  const recur = el('select', { onchange: e => { save({ recur: e.target.value }); every.disabled = !e.target.value; } }, [['', 'Nie'], ['daily', 'Täglich'], ['weekly', 'Wöchentlich'], ['monthly', 'Monatlich']].map(([v, l]) => el('option', { value: v, textContent: l, selected: (c.recur || '') === v })));
  const every = el('input', { type: 'number', min: 1, value: c.recur_every || 1, disabled: !c.recur, onchange: e => save({ recur_every: e.target.value }) });
  const est = el('input', { value: fmtMin(c.est_minutes), placeholder: 'z. B. 1:30 oder 90m', onchange: e => { const m = parseMinutes(e.target.value); if (Number.isNaN(m)) { toast('Bitte z. B. 1:30, 2h oder 90m eingeben'); e.target.value = fmtMin(c.est_minutes); } else save({ est_minutes: m }); } });
  return sec('Eigenschaften',
    field('Board', boardSel), field('Spalte', colSel),
    field('Priorität', el('select', { onchange: e => save({ priority: +e.target.value }) }, PRI.map((p, i) => el('option', { value: i, textContent: p, selected: c.priority === i })))),
    field('Person', el('input', { value: c.assignee, list: 'dlPeople', onchange: e => save({ assignee: e.target.value }) })), people,
    field('Kunde', el('input', { value: c.customer, list: 'dlCust', onchange: e => save({ customer: e.target.value }) })), custs,
    field('CRM-Referenz', el('div', {}, el('input', { value: c.crm_ref, placeholder: 'Kunden-/Projekt-ID', onchange: e => save({ crm_ref: e.target.value }).then(() => draw()) }), crm)),
    el('div', { class: 'row' }, field('Start', el('input', { type: 'date', value: c.start_date || '', onchange: e => save({ start_date: e.target.value || null }) })), field('Fällig', el('input', { type: 'date', value: c.due_date || '', onchange: e => save({ due_date: e.target.value || null }) }))),
    field('Uhrzeit (für Erinnerung)', el('input', { type: 'time', value: c.due_time || '', onchange: e => save({ due_time: e.target.value || null }) })),
    field('Zeitschätzung', est), el('div', { class: 'row' }, field('Wiederholen', recur), field('alle', every)));
}

// ----- Zeit -----
function timeSec(me) {
  const c = D.card, running = D.time.find(t => !t.ended_at), mine = running && running.user === me;
  const tracked = D.time.filter(t => t.ended_at).reduce((a, t) => a + t.seconds, 0), est = (c.est_minutes || 0) * 60;
  const min = el('input', { placeholder: 'Minuten / 1:30', style: 'max-width:110px' }), note = el('input', { placeholder: 'Notiz (optional)' });
  return sec('Zeit',
    running ? el('div', { class: 'timer run' }, el('b', { id: 'liveTimer', textContent: elapsed() }), el('span', { class: 'muted', textContent: mine ? '' : ` läuft (${running.user})` }), el('button', { class: 'danger solid', textContent: '■ Stopp', onclick: () => run(api('POST', '/timer/stop')) }))
      : el('button', { class: 'primary', textContent: '▶ Zeitnehmer starten', onclick: () => run(api('POST', `/cards/${c.id}/timer/start`)) }),
    el('div', { class: 'muted', textContent: `Erfasst: ${fmtDur(tracked)}${est ? ' von ' + fmtDur(est) + ' geschätzt' : ''}` }),
    est ? el('div', { class: 'bar big' + (tracked > est ? ' over' : '') }, el('i', { style: `width:${Math.min(100, Math.round(100 * tracked / est))}%` })) : '',
    el('div', { class: 'row' }, min, note, el('button', { textContent: '+', title: 'Zeit nachtragen', onclick: () => { const m = parseMinutes(min.value); if (!m) return toast('Minuten angeben, z. B. 45 oder 1:30'); run(api('POST', `/cards/${c.id}/time`, { minutes: m, note: note.value })); } })),
    el('details', {}, el('summary', { textContent: `Einträge (${D.time.filter(t => t.ended_at).length})` }), ...D.time.filter(t => t.ended_at).map(t => el('div', { class: 'tentry' }, el('span', { textContent: `${fmtDateTime(t.started_at).slice(0, 10)} · ${fmtDur(t.seconds)}${t.user ? ' · ' + t.user : ''}${t.note ? ' · ' + t.note : ''}` }),
      el('button', { class: 'x', textContent: '✕', onclick: () => run(api('DELETE', '/time/' + t.id)) })))));
}

// ----- Labels -----
function labelSec() {
  const have = new Set(D.labels), all = S.data?.labels || [];
  const toggle = id => { have.has(id) ? have.delete(id) : have.add(id); run(api('PUT', `/cards/${D.card.id}/labels`, { ids: [...have] })); };
  return sec('Labels', el('div', { class: 'chips big' }, all.map(l => el('span', { class: 'chip' + (have.has(l.id) ? ' on' : ''), style: `--c:${l.color};background:${have.has(l.id) ? l.color : 'transparent'};color:${have.has(l.id) ? textOn(l.color) : 'inherit'};border-color:${l.color}`, textContent: l.name, onclick: () => toggle(l.id) })),
    el('span', { class: 'chip add', textContent: '+ Neu', onclick: async () => { const v = await ask({ title: 'Neues Label', fields: [{ name: 'n', label: 'Name' }, { name: 'c', label: 'Farbe', type: 'color', value: COLORS[Math.floor(Math.random() * COLORS.length)] }], ok: 'Anlegen' }); if (v?.n.trim()) { const r = await api('POST', '/labels', { name: v.n, color: v.c }); have.add(r.id); await run(api('PUT', `/cards/${D.card.id}/labels`, { ids: [...have] }), async () => { await S.hooks.refresh(); await reload(); }); } } }),
    all.length ? el('span', { class: 'chip add', textContent: '✎', title: 'Labels verwalten', onclick: manageLabels }) : ''));
}
export async function manageLabels() {
  const dlg = $('#askDlg'); const labels = await api('GET', '/labels');
  dlg.replaceChildren(el('h3', { textContent: 'Labels verwalten' }), ...labels.map(l => el('div', { class: 'row', style: 'align-items:center;margin-bottom:6px' },
    el('input', { type: 'color', value: l.color, style: 'max-width:46px;padding:0', onchange: e => api('PATCH', '/labels/' + l.id, { color: e.target.value }) }),
    el('input', { value: l.name, onchange: e => api('PATCH', '/labels/' + l.id, { name: e.target.value }) }),
    el('button', { class: 'danger', textContent: '🗑', onclick: async e => { if (await confirmBox('Label löschen?', `„${l.name}“ wird von allen Karten entfernt.`)) { await api('DELETE', '/labels/' + l.id); e.target.closest('.row').remove(); } } }))),
    el('div', { class: 'actions' }, el('span', { class: 'sp' }), el('button', { class: 'primary', textContent: 'Fertig', onclick: () => dlg.close() })));
  dlg.onclose = async () => { await S.hooks.refresh(); if (D) reload(); }; dlg.showModal();
}

// ----- Aktionen -----
function actionSec() {
  const c = D.card;
  return sec('', el('div', { class: 'btns' },
    el('button', { textContent: '⧉ Kopieren', onclick: async () => { try { const r = await api('POST', `/cards/${c.id}/copy`); undo.push({ label: 'Karte kopiert', undo: () => api('DELETE', '/cards/' + r.id), redo: () => api('POST', `/cards/${r.id}/restore`) }); toast('Kopie erstellt'); await S.hooks.refresh(); openCard(r.id); } catch (e) { fail(e); } } }),
    el('button', { textContent: '▣ Archivieren', onclick: async () => { await save({ archived: 1 }, 'Karte archiviert'); $('#cardDlg').close(); toast('Archiviert (Rückgängig: Strg+Z)'); } }),
    el('button', { class: 'danger', textContent: '🗑 Löschen', onclick: async () => { const id = c.id; await api('DELETE', '/cards/' + id); undo.push({ label: 'Karte gelöscht', undo: () => api('POST', `/cards/${id}/restore`), redo: () => api('DELETE', '/cards/' + id) }); $('#cardDlg').close(); toast('In den Papierkorb verschoben (Strg+Z macht es rückgängig)'); } })),
    el('div', { class: 'muted small', textContent: `#${c.id} · erstellt ${fmtDateTime(c.created_at)}${c.created_by ? ' von ' + c.created_by : ''}` }));
}
