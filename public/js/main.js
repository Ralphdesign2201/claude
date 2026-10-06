// Start, Navigation, Ansichten, Filter
import { $, $$, el, S, api, fail, toast, pref, CLIENT, fmtDur, utc, ask, menu } from './util.js';
import { renderBoard, passes, filterActive, labelMap } from './board.js';
import { renderList, renderCalendar, renderTimeline, renderWeek } from './views.js';
import { renderDashboard, renderAnalytics, renderTimeReport } from './reports.js';
import { openCard } from './card.js';
import { boardDialog, trashDialog, helpDialog, gate } from './dialogs.js';
import { openSettings, applyPrefs } from './settings.js';
import { initKeys, updateSelbar } from './keys.js';
import { startReminders } from './notify.js';

const BOARD_VIEWS = [['board', '▦ Board'], ['list', '☰ Liste'], ['calendar', '▤ Kalender'], ['timeline', '▭ Zeitleiste'], ['week', '▥ Woche']];
const GLOBAL_VIEWS = [['dashboard', '⌂ Dashboard'], ['analytics', '📈 Auswertung'], ['time', '⏱ Zeiten']];
const isBoardView = v => BOARD_VIEWS.some(x => x[0] === v);

async function loadBoards() { S.boards = await api('GET', '/boards'); }
async function loadBoard(id) {
  if (id != null) S.id = id;
  if (!S.boards.some(b => b.id === S.id)) S.id = S.boards[0]?.id ?? null;
  S.data = S.id ? await api('GET', '/boards/' + S.id) : null;
  pref.set('board', S.id);
}
async function refresh() { try { await loadBoards(); await loadBoard(); S.sel.forEach(id => { if (!S.data?.cards.some(c => c.id === id)) S.sel.delete(id); }); render(); updateTimer(); } catch (e) { if (!/Anmeldung|Gesperrt/.test(e.message)) fail(e); } }
async function reloadAll(id) { await loadBoards(); await loadBoard(id); S.filter = {}; render(); }
function setView(v) { S.view = v; pref.set('view', v); render(); }

// ----- Seitenleiste -----
function renderSide() {
  const nav = $('#boards'); nav.replaceChildren(...S.boards.map(b => el('div', { class: 'bitem' + (b.id === S.id && isBoardView(S.view) ? ' on' : ''), onclick: async () => { S.filter = {}; S.sel.clear(); if (!isBoardView(S.view) && S.view !== 'analytics') S.view = 'board'; await loadBoard(b.id); render(); } },
    el('i', { textContent: b.icon, style: 'background:' + b.color }), el('span', { textContent: b.name }))));
  $('#gnav').replaceChildren(...GLOBAL_VIEWS.map(([v, l]) => el('button', { class: 'ghost' + (S.view === v ? ' on' : ''), textContent: l, onclick: () => setView(v) })));
}
// ----- Kopfzeile -----
function renderTop() {
  const d = S.data, bv = isBoardView(S.view);
  $('#boardTitle').textContent = bv || S.view === 'analytics' ? d?.board.name || '' : GLOBAL_VIEWS.find(v => v[0] === S.view)?.[1].slice(2) || '';
  $('#boardMenu').replaceChildren(d && (bv || S.view === 'analytics') ? el('button', { class: 'ghost', textContent: '⚙', title: 'Board-Einstellungen', onclick: () => boardDialog(d.board) }) : '');
  $('#viewtabs').replaceChildren(...(d ? BOARD_VIEWS.map(([v, l]) => el('button', { class: S.view === v ? 'on' : '', textContent: l, onclick: () => setView(v) })) : []));
  $('#viewtabs').hidden = !d;
  $('#boardTitle').style.setProperty('--c', d?.board.color || 'var(--accent)');
}
// ----- Filter -----
function renderFilters() {
  const bar = $('#filters'), d = S.data, show = d && isBoardView(S.view); bar.hidden = !show; if (!show) return;
  const f = S.filter, set = k => e => { f[k] = e.target.value; render(); };
  const sel = (k, label, opts) => el('select', { class: f[k] ? 'active' : '', onchange: set(k) }, el('option', { value: '', textContent: label }), opts.map(([v, l]) => el('option', { value: v, textContent: l, selected: String(f[k]) === String(v) })));
  const text = el('input', { type: 'search', placeholder: 'Filter …', value: f.text || '', oninput: e => { f.text = e.target.value; clearTimeout(text.t); text.t = setTimeout(renderContent, 200); } });
  const saved = el('select', { onchange: e => { const v = d.views.find(x => x.id === +e.target.value); if (v) { S.filter = { ...v.filter }; S.view = v.view; render(); } } }, el('option', { value: '', textContent: '★ Gespeicherte Ansichten' }), d.views.map(v => el('option', { value: v.id, textContent: v.name })));
  bar.replaceChildren(text,
    sel('label', 'Label', d.labels.map(l => [l.id, l.name])), sel('priority', 'Priorität', [[3, 'Dringend'], [2, 'Hoch'], [1, 'Niedrig'], [0, 'Keine']]),
    sel('due', 'Fälligkeit', [['today', 'Heute'], ['overdue', 'Überfällig'], ['week', 'Diese Woche'], ['none', 'Ohne Datum']]),
    sel('person', 'Person', [['__none', '(niemand)'], ...d.people.map(p => [p, p])]), sel('customer', 'Kunde', d.customers.map(p => [p, p])),
    filterActive() ? el('button', { textContent: '✕ Zurücksetzen', onclick: () => { S.filter = {}; render(); } }) : '',
    el('span', { class: 'sp' }), saved,
    filterActive() || S.view !== 'board' ? el('button', { textContent: '★ Ansicht speichern', onclick: async () => { const v = await ask({ title: 'Ansicht speichern', text: 'Merkt sich Filter und Darstellung dieses Boards.', fields: [{ name: 'n', placeholder: 'Name, z. B. „Meine offenen Aufgaben“' }], ok: 'Speichern' }); if (v?.n.trim()) { await api('POST', `/boards/${S.id}/views`, { name: v.n, view: S.view, filter: S.filter }); toast('Ansicht gespeichert'); refresh(); } } }) : '',
    d.views.length ? el('button', { textContent: '🗑', title: 'Gespeicherte Ansicht löschen', onclick: e => menu(e.currentTarget, d.views.map(v => ({ label: 'Löschen: ' + v.name, danger: true, run: async () => { await api('DELETE', '/views/' + v.id); refresh(); } }))) }) : '');
}
// ----- Inhalt -----
function render() { renderSide(); renderTop(); renderFilters(); renderContent(); }
function renderContent() {
  const root = $('#content'), v = S.view, d = S.data;
  if (v === 'dashboard') renderDashboard(root).catch(fail);
  else if (v === 'time') renderTimeReport(root);
  else if (!d) root.replaceChildren(el('div', { class: 'empty big' }, el('p', { textContent: 'Noch kein Board vorhanden.' }), el('button', { class: 'primary', textContent: '+ Neues Board', onclick: () => boardDialog(null) })));
  else if (v === 'analytics') renderAnalytics(root).catch(fail);
  else ({ board: renderBoard, list: renderList, calendar: renderCalendar, timeline: renderTimeline, week: renderWeek }[v] || renderBoard)(root);
  updateSelbar();
}

// ----- Zeitnehmer-Anzeige -----
let tickT;
async function updateTimer() {
  let t = null; try { t = await api('GET', '/timer'); } catch { return; }
  const box = $('#timerbox'); clearInterval(tickT);
  if (!t || !t.card_id) { box.hidden = true; return; }
  box.hidden = false;
  const lbl = el('span'), draw = () => lbl.textContent = fmtDur((Date.now() - utc(t.started_at)) / 1000).replace(' min', ' min') ;
  draw(); tickT = setInterval(draw, 15000);
  box.replaceChildren(el('span', { class: 'rec', textContent: '●' }), lbl, el('a', { href: '#', textContent: t.title, onclick: e => { e.preventDefault(); openCard(t.card_id); } }), el('button', { textContent: '■', title: 'Zeitnehmer stoppen', onclick: async () => { await api('POST', '/timer/stop'); updateTimer(); refresh(); } }));
}

// ----- Suche -----
function initSearch() {
  let st; const inp = $('#search'), box = $('#results');
  inp.addEventListener('input', e => {
    clearTimeout(st); const q = e.target.value.trim(); if (!q) { box.hidden = true; return; }
    st = setTimeout(async () => {
      const rs = await api('GET', '/search?q=' + encodeURIComponent(q)).catch(() => []);
      box.replaceChildren(...(rs.length ? rs.map(r => el('div', { onclick: async () => { box.hidden = true; inp.value = ''; if (r.board_id !== S.id || !isBoardView(S.view)) { S.filter = {}; S.view = isBoardView(S.view) ? S.view : 'board'; await loadBoard(r.board_id); render(); } openCard(r.id); } },
        el('b', { textContent: r.title }), r.archived ? ' (archiviert)' : '', el('small', { textContent: `${r.board_name} › ${r.column_name} · gefunden in: ${r.where}` }))) : [el('div', { class: 'muted', textContent: 'Nichts gefunden.' })]));
      box.hidden = false;
    }, 200);
  });
  inp.addEventListener('keydown', e => { if (e.key === 'Escape') { box.hidden = true; inp.blur(); } });
  document.addEventListener('click', e => { if (!e.target.closest('#results,#search')) box.hidden = true; });
}
// ----- Live-Aktualisierung (Mehrbenutzer im Heimnetz) -----
function connectEvents() {
  const es = new EventSource('/api/events'); let t;
  es.onmessage = e => { const m = JSON.parse(e.data); if (m.src === CLIENT || document.body.classList.contains('dragging')) return; clearTimeout(t); t = setTimeout(refresh, 300); };
  es.onerror = () => { es.close(); setTimeout(() => api('GET', '/session').then(s => { if (!s.locked && !(s.auth_required && !s.user)) connectEvents(); }).catch(() => setTimeout(connectEvents, 5000)), 3000); };
}

// ----- Start -----
S.hooks.render = render; S.hooks.refresh = refresh; S.hooks.reloadAll = reloadAll; S.hooks.openCard = openCard; S.hooks.setView = setView; S.hooks.help = helpDialog;
S.hooks.sessionChanged = () => { renderSide(); };
S.hooks.gate = async () => { const s = await fetch('/api/session').then(r => r.json()); gate(s, boot); };
let started = false;
async function boot() {
  applyPrefs();
  S.session = await fetch('/api/session').then(r => r.json());
  if (S.session.locked || (S.session.auth_required && !S.session.user)) return gate(S.session, boot);
  S.settings = await api('GET', '/settings');
  S.view = pref.get('view', 'board');
  await loadBoards(); const saved = pref.get('board', null); await loadBoard(S.boards.some(b => b.id === saved) ? saved : S.boards[0]?.id);
  render(); updateTimer();
  if (!started) { started = true; initKeys(); initSearch(); startReminders(); }
  connectEvents();
}
$('#newBoard').onclick = () => boardDialog(null);
$('#openSettings').onclick = () => openSettings();
$('#openTrash').onclick = () => trashDialog();
$('#openHelp').onclick = helpDialog;
$('#toggleSide').onclick = () => document.body.classList.toggle('side-open');
$('#quickBtn').onclick = () => import('./dialogs.js').then(m => m.quickDialog());
addEventListener('beforeprint', () => document.body.classList.add('printing')); addEventListener('afterprint', () => document.body.classList.remove('printing'));
boot().catch(fail);
