// Gemeinsame Helfer und Zustand
export const $ = (s, r = document) => r.querySelector(s);
export const $$ = (s, r = document) => [...r.querySelectorAll(s)];
export function el(tag, props = {}, ...kids) {
  const { dataset, style, class: cls, list, ...rest } = props || {};
  const attrs = {}; for (const k of Object.keys(rest)) if (/^(data|aria)-/.test(k)) { attrs[k] = rest[k]; delete rest[k]; }
  const e = Object.assign(document.createElement(tag), rest);
  for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v);
  if (list) e.setAttribute('list', list);
  if (cls) e.className = cls;
  if (dataset) Object.assign(e.dataset, dataset);
  if (style) typeof style === 'string' ? (e.style.cssText = style) : Object.assign(e.style, style);
  for (const k of kids.flat(Infinity)) if (k != null && k !== false) e.append(k);
  return e;
}
export const CLIENT = Math.random().toString(36).slice(2);

export const S = {
  boards: [], id: null, data: null, view: 'board', filter: {}, sel: new Set(), focus: null, adding: null,
  session: {}, settings: {}, labels: [], hooks: {},
};
const store = { get: k => { try { return localStorage.getItem(k); } catch { return null; } }, set: (k, v) => { try { localStorage.setItem(k, v); } catch {} } };
export const pref = { get: (k, d) => { const v = store.get('kb.' + k); if (v == null) return d; try { return JSON.parse(v); } catch { return d; } }, set: (k, v) => store.set('kb.' + k, JSON.stringify(v)) };

export async function api(method, url, body, opts = {}) {
  const r = await fetch('/api' + url, { method, headers: { 'X-Kanban': '1', 'X-Client': CLIENT, ...(opts.raw ? opts.headers : { 'Content-Type': 'application/json' }) }, body: opts.raw ? body : body ? JSON.stringify(body) : undefined });
  if (!r.ok) {
    const j = await r.json().catch(() => ({}));
    if (r.status === 401 && j.login) S.hooks.gate?.();
    if (r.status === 423) S.hooks.gate?.();
    throw new Error(j.error || r.statusText);
  }
  return opts.text ? r.text() : r.json();
}
export function toast(msg, ms = 3000) {
  const t = $('#toast'); t.textContent = msg; t.hidden = false; clearTimeout(toast.t); toast.t = setTimeout(() => t.hidden = true, ms);
}
export const fail = e => toast('Fehler: ' + (e?.message || e), 5000);
export const pad = n => String(n).padStart(2, '0');
export const dstr = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
export const todayStr = () => dstr(new Date());
export const parseD = s => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
export const addDays = (s, n) => { const d = parseD(s); d.setDate(d.getDate() + n); return dstr(d); };
export const fmtDate = s => s ? s.split('-').reverse().join('.') : '';
export const fmtDateShort = s => s ? s.slice(8) + '.' + s.slice(5, 7) + '.' : '';
export const WD = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
export const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
export const utc = s => new Date(!s ? NaN : /T|Z/.test(s) ? s : s.replace(' ', 'T') + 'Z');
export const fmtDateTime = s => { const d = utc(s); return isNaN(d) ? '' : `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`; };
export const fmtDur = sec => { sec = Math.max(0, Math.round(sec)); const h = Math.floor(sec / 3600), m = Math.floor(sec % 3600 / 60); return h ? `${h}:${pad(m)} h` : `${m} min`; };
export const fmtDurLong = sec => { const d = sec / 86400; return d >= 1 ? `${Math.round(d * 10) / 10} Tage` : fmtDur(sec); };
export const PRI = ['Keine', 'Niedrig', 'Hoch', 'Dringend'];
export const COLORS = ['#4f7cff', '#2fa66a', '#e08a1e', '#d93a3a', '#8a5cf5', '#14a8b8', '#e0509a', '#6b7285'];
export const ICONS = ['▦', '★', '⚑', '✎', '⌂', '☕', '♥', '⚙', '☀', '✉'];
export const initials = n => (n || '?').split(/\s+/).map(w => w[0]).slice(0, 2).join('').toUpperCase();
export const textOn = hex => { const n = parseInt(hex.slice(1), 16), l = ((n >> 16) * 299 + ((n >> 8) & 255) * 587 + (n & 255) * 114) / 1000; return l > 150 ? '#1c2030' : '#fff'; };

// ----- Rückgängig / Wiederholen -----
const undoS = [], redoS = [];
export const undo = {
  push(e) { undoS.push(e); if (undoS.length > 50) undoS.shift(); redoS.length = 0; },
  async undo() { const e = undoS.pop(); if (!e) return toast('Nichts rückgängig zu machen'); try { await e.undo(); redoS.push(e); toast('Rückgängig: ' + e.label); } catch (x) { fail(x); } await S.hooks.refresh?.(); },
  async redo() { const e = redoS.pop(); if (!e) return toast('Nichts zu wiederholen'); try { await e.redo(); undoS.push(e); toast('Wiederholt: ' + e.label); } catch (x) { fail(x); } await S.hooks.refresh?.(); },
};

// ----- Eingabefenster -----
export function ask({ title, fields = [], ok = 'OK', danger = false, text }) {
  return new Promise(res => {
    const dlg = $('#askDlg'); let done = false;
    const inputs = fields.map(f => {
      const i = f.type === 'select' ? el('select', {}, f.options.map(o => el('option', { value: o.value ?? o, textContent: o.label ?? o, selected: (o.value ?? o) == f.value })))
        : f.type === 'textarea' ? el('textarea', { value: f.value ?? '', rows: 4 }) : el('input', { type: f.type || 'text', value: f.value ?? '', placeholder: f.placeholder || '', min: f.min, autocomplete: 'off' });
      return [f, i];
    });
    const finish = v => { if (done) return; done = true; dlg.close(); res(v); };
    const submit = () => finish(Object.fromEntries(inputs.map(([f, i]) => [f.name, i.type === 'checkbox' ? i.checked : i.value])));
    dlg.replaceChildren(el('h3', { textContent: title }), text ? el('p', { class: 'muted', textContent: text }) : '',
      ...inputs.map(([f, i]) => el('div', {}, f.label ? el('label', { textContent: f.label }) : '', i)),
      el('div', { class: 'actions' }, el('span', { class: 'sp' }), el('button', { textContent: 'Abbrechen', onclick: () => finish(null) }),
        el('button', { class: danger ? 'danger solid' : 'primary', textContent: ok, onclick: submit })));
    dlg.onkeydown = e => { if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') { e.preventDefault(); submit(); } };
    dlg.onclose = () => finish(null);
    dlg.showModal(); inputs[0]?.[1].focus(); inputs[0]?.[1].select?.();
  });
}
export const confirmBox = (title, text, ok = 'Löschen') => ask({ title, text, ok, danger: true }).then(v => !!v);

// ----- Kontextmenü -----
export function menu(anchor, items) {
  $('#popmenu')?.remove();
  const m = el('div', { id: 'popmenu', class: 'popmenu' }, items.filter(Boolean).map(it => it === '-' ? el('hr') : el('button', { class: it.danger ? 'danger' : '', textContent: (it.check ? (it.checked ? '✓ ' : '   ') : '') + it.label, onclick: () => { m.remove(); it.run(); } })));
  document.body.append(m);
  const r = anchor.getBoundingClientRect(), w = m.offsetWidth, h = m.offsetHeight;
  m.style.left = Math.max(4, Math.min(innerWidth - w - 4, r.left)) + 'px';
  m.style.top = (r.bottom + h > innerHeight ? Math.max(4, r.top - h) : r.bottom + 2) + 'px';
  setTimeout(() => addEventListener('pointerdown', function f(e) { if (!m.contains(e.target)) { m.remove(); removeEventListener('pointerdown', f); } }), 0);
  return m;
}
