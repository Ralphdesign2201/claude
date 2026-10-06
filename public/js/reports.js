// Dashboard, Auswertungen, Zeitbericht
import { $, el, S, api, fail, fmtDate, fmtDateShort, fmtDur, fmtDurLong, todayStr, addDays, dstr } from './util.js';

const open = id => S.hooks.openCard(id);
const miniCard = c => el('div', { class: 'mini' + (c.done_at ? ' done' : ''), onclick: () => open(c.id) },
  el('i', { class: 'dot', style: `background:${c.board_color}` }), el('span', { class: 'grow', textContent: c.title }), el('span', { class: 'muted', textContent: c.board_name }),
  c.due_date ? el('span', { class: 'due' + (c.due_date < todayStr() && !c.done_at ? ' over' : ''), textContent: fmtDateShort(c.due_date) }) : '');

export async function renderDashboard(root) {
  const d = await api('GET', '/dashboard'); let tab = 'today';
  const tiles = [['today', 'Heute fällig', d.today, ''], ['overdue', 'Überfällig', d.overdue, 'bad'], ['doing', 'In Arbeit', d.doing, ''], ['done_week', 'Diese Woche erledigt', d.done_week, 'good']];
  const list = el('div', { class: 'dlist' });
  const draw = () => { list.replaceChildren(el('h3', { textContent: tiles.find(t => t[0] === tab)[1] }), ...(d[tab].length ? d[tab].map(miniCard) : [el('p', { class: 'empty', textContent: 'Nichts in dieser Liste.' })])); $$t().forEach(t => t.classList.toggle('on', t.dataset.k === tab)); };
  const $$t = () => [...root.querySelectorAll('.tile')];
  root.replaceChildren(el('div', { class: 'scroll pad' }, el('h2', { textContent: 'Dashboard' }),
    el('div', { class: 'tiles' }, tiles.map(([k, t, l, cls]) => el('button', { class: 'tile ' + cls, dataset: { k }, onclick: () => { tab = k; draw(); } }, el('b', { textContent: l.length }), el('span', { textContent: t })))),
    el('div', { class: 'two' }, list, el('div', {}, el('h3', { textContent: 'Demnächst fällig' }), ...(d.soon.length ? d.soon.map(miniCard) : [el('p', { class: 'empty', textContent: 'Keine kommenden Termine.' })])))));
  draw();
}

// ----- Diagramme (reines HTML/SVG) -----
const hbars = (rows, fmt) => { const max = Math.max(1, ...rows.map(r => r.v)); return el('div', { class: 'hbars' }, rows.length ? rows.map(r => el('div', { class: 'hb' }, el('span', { class: 'lbl', textContent: r.k, title: r.k }), el('div', { class: 'track' }, el('i', { style: `width:${Math.max(1, 100 * r.v / max)}%` })), el('span', { class: 'val', textContent: fmt(r.v) }))) : [el('p', { class: 'empty', textContent: 'Noch keine Daten.' })]); };
function vbars(rows) {
  const max = Math.max(1, ...rows.map(r => r.v));
  return el('div', { class: 'vbars' }, rows.map(r => el('div', { class: 'vb', title: `${r.k}: ${r.v}` }, el('span', { class: 'n', textContent: r.v }), el('i', { style: `height:${100 * r.v / max}%` }), el('span', { class: 'l', textContent: r.k }))));
}
function burndown(data) {
  const W = 640, H = 220, P = 30, max = Math.max(1, ...data.map(d => d.scope)), x = i => P + i * (W - P - 10) / (data.length - 1), y = v => H - 22 - (H - 40) * v / max;
  const line = (k, cls) => `<polyline class="${cls}" fill="none" points="${data.map((d, i) => `${x(i)},${y(d[k])}`).join(' ')}"/>`;
  const grid = [0, .5, 1].map(f => `<line class="gl" x1="${P}" x2="${W - 10}" y1="${y(max * f)}" y2="${y(max * f)}"/><text x="${P - 4}" y="${y(max * f) + 4}" text-anchor="end">${Math.round(max * f)}</text>`).join('');
  const ticks = [0, 7, 14, 21, 29].map(i => `<text x="${x(i)}" y="${H - 6}" text-anchor="middle">${fmtDateShort(data[i].date)}</text>`).join('');
  const wrap = el('div', { class: 'burn' }); wrap.innerHTML = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Burn-down">${grid}${ticks}${line('scope', 'scope')}${line('open', 'open')}</svg><div class="legend"><span class="k open"></span> Offene Karten <span class="k scope"></span> Gesamtumfang</div>`;
  return wrap;
}
const card = (t, ...k) => el('section', { class: 'rcard' }, el('h3', { textContent: t }), ...k);

export async function renderAnalytics(root) {
  if (!S.id) return root.replaceChildren(el('p', { class: 'empty', textContent: 'Kein Board gewählt.' }));
  const a = await api('GET', `/boards/${S.id}/analytics`);
  root.replaceChildren(el('div', { class: 'scroll pad' }, el('h2', { textContent: 'Auswertung: ' + S.data.board.name }),
    el('div', { class: 'cards3' },
      card('Durchlaufzeit pro Spalte', el('p', { class: 'muted', textContent: 'Durchschnittliche Verweildauer einer Karte in der Spalte.' }), hbars(a.cycle.map(c => ({ k: c.column, v: c.avg_seconds })), fmtDurLong)),
      card('Durchsatz pro Woche', el('p', { class: 'muted', textContent: 'Erledigte Karten, letzte 12 Wochen (Wochenbeginn Montag).' }), vbars(a.throughput.map(t => ({ k: fmtDateShort(t.week), v: t.done })))),
      card('Burn-down (30 Tage)', burndown(a.burndown)),
      card('Zeitaufwand je Label', hbars(a.time_by_label.map(r => ({ k: r.key, v: r.seconds })), fmtDur)),
      card('Zeitaufwand je Kunde/Projekt', hbars(a.time_by_customer.map(r => ({ k: r.key, v: r.seconds })), fmtDur)))));
}

// ----- Zeitbericht -----
let rep = { from: '', to: '', board_id: '', user: '', group: 'entry' };
export async function renderTimeReport(root) {
  if (!rep.from) { const d = new Date(); rep.from = dstr(new Date(d.getFullYear(), d.getMonth(), 1)); rep.to = todayStr(); }
  const qs = () => new URLSearchParams(Object.entries(rep).filter(([, v]) => v)).toString();
  let r; try { r = await api('GET', '/time/report?' + qs()); } catch (e) { return fail(e); }
  const set = (k) => e => { rep[k] = e.target.value; renderTimeReport(root); };
  const sel = (k, opts) => el('select', { onchange: set(k) }, opts.map(([v, l]) => el('option', { value: v, textContent: l, selected: rep[k] === v })));
  root.replaceChildren(el('div', { class: 'scroll pad' }, el('h2', { textContent: 'Zeitbericht' }),
    el('div', { class: 'filters inline' },
      el('label', { textContent: 'Von' }), el('input', { type: 'date', value: rep.from, onchange: set('from') }), el('label', { textContent: 'Bis' }), el('input', { type: 'date', value: rep.to, onchange: set('to') }),
      sel('board_id', [['', 'Alle Boards'], ...S.boards.map(b => [String(b.id), b.name])]), sel('user', [['', 'Alle Personen'], ...r.users.filter(Boolean).map(u => [u, u])]),
      sel('group', [['entry', 'Einzelne Einträge'], ['day', 'Nach Tag'], ['card', 'Nach Karte'], ['label', 'Nach Label'], ['customer', 'Nach Kunde'], ['board', 'Nach Board'], ['user', 'Nach Person']]),
      el('a', { class: 'btn primary', href: '/api/time/report?format=csv&' + qs(), textContent: '⤓ CSV (Excel)' })),
    el('p', { class: 'muted', textContent: `Gesamt: ${fmtDur(r.total_seconds)} (${(r.total_seconds / 3600).toFixed(2).replace('.', ',')} h) in ${r.rows.length} Zeilen` }),
    el('div', { class: 'scroll x' }, el('table', { class: 'tbl' }, el('thead', {}, el('tr', {}, r.head.map(h => el('th', { textContent: h })))), el('tbody', {}, r.rows.map(row => el('tr', {}, row.map(c => el('td', { textContent: c }))))))),
    r.rows.length ? '' : el('p', { class: 'empty', textContent: 'Keine Zeiteinträge im Zeitraum.' })));
}
