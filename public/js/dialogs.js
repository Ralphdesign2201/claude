// Dialoge: Board, Papierkorb/Archiv, Schnellerfassung, Hilfe, Anmeldung
import { $, el, S, api, fail, toast, ask, confirmBox, COLORS, ICONS, fmtDateTime, todayStr, undo } from './util.js';

export async function boardDialog(b) {
  const dlg = $('#boardDlg'), isNew = !b;
  const tpls = isNew ? await api('GET', '/templates') : [];
  let color = b?.color || COLORS[0], icon = b?.icon || ICONS[0];
  const name = el('input', { value: b?.name || '', placeholder: 'z. B. Kunde Müller, Website-Relaunch, Privat' });
  const tpl = el('select', {}, tpls.map(t => el('option', { value: t.key, textContent: `${t.name}  (${t.columns.map(c => c.name).join(' › ')})` })));
  const lane = el('select', {}, [['none', 'Keine'], ['assignee', 'Nach Person'], ['priority', 'Nach Priorität'], ['customer', 'Nach Kunde']].map(([v, l]) => el('option', { value: v, textContent: l, selected: b?.swimlane === v })));
  const colorsEl = el('div', { class: 'colors' }), iconsEl = el('div', { class: 'colors' });
  const draw = () => {
    colorsEl.replaceChildren(...COLORS.map(c => el('span', { style: 'background:' + c, class: c === color ? 'on' : '', onclick: () => { color = c; draw(); } })));
    iconsEl.replaceChildren(...ICONS.map(i => el('span', { class: 'ic' + (i === icon ? ' on' : ''), textContent: i, onclick: () => { icon = i; draw(); } })));
  };
  draw();
  const save = async () => {
    if (!name.value.trim()) return name.focus();
    try {
      if (isNew) { const r = await api('POST', '/boards', { name: name.value.trim(), color, icon, template: tpl.value }); dlg.close(); await S.hooks.reloadAll(r.id); }
      else { await api('PATCH', '/boards/' + b.id, { name: name.value.trim(), color, icon, swimlane: lane.value }); dlg.close(); await S.hooks.reloadAll(b.id); }
    } catch (e) { fail(e); }
  };
  dlg.replaceChildren(el('h3', { textContent: isNew ? 'Neues Board' : 'Board bearbeiten' }),
    el('label', { textContent: 'Name' }), name,
    ...(isNew ? [el('label', { textContent: 'Vorlage' }), tpl, el('div', { class: 'muted small', textContent: 'Eigene Vorlagen entstehen über „Als Vorlage speichern“ in den Board-Einstellungen.' })] : [el('label', { textContent: 'Swimlanes (Zeilen)' }), lane]),
    el('label', { textContent: 'Farbe' }), colorsEl, el('label', { textContent: 'Symbol' }), iconsEl,
    ...(isNew ? [] : [el('div', { class: 'btns', style: 'margin-top:14px' },
      el('button', { textContent: 'Duplizieren', onclick: async () => { try { const r = await api('POST', `/boards/${b.id}/duplicate`); dlg.close(); await S.hooks.reloadAll(r.id); toast('Board dupliziert'); } catch (e) { fail(e); } } }),
      el('button', { textContent: 'Als Vorlage speichern', onclick: async () => { const v = await ask({ title: 'Als Vorlage speichern', text: 'Spalten, WIP-Limits und Swimlanes werden übernommen (ohne Karten).', fields: [{ name: 'n', value: b.name }], ok: 'Speichern' }); if (v) { await api('POST', `/boards/${b.id}/save-template`, { name: v.n }); toast('Vorlage gespeichert'); } } }),
      el('a', { class: 'btn', href: `/api/boards/${b.id}/export?format=json&files=1`, textContent: 'Export JSON' }), el('a', { class: 'btn', href: `/api/boards/${b.id}/export?format=md`, textContent: 'Markdown' }), el('a', { class: 'btn', href: `/api/boards/${b.id}/export?format=csv`, textContent: 'CSV' }),
      el('button', { textContent: '🖨 Drucken / PDF', onclick: () => { dlg.close(); setTimeout(() => print(), 200); } }))]),
    el('div', { class: 'actions' },
      ...(isNew ? [] : [el('button', { class: 'danger', textContent: 'Archivieren', onclick: async () => { if (!await confirmBox('Board archivieren?', `„${b.name}“ verschwindet aus der Liste, ist aber im Archiv wiederherstellbar.`, 'Archivieren')) return; await api('PATCH', '/boards/' + b.id, { archived: 1 }); dlg.close(); await S.hooks.reloadAll(); } })]),
      el('span', { class: 'sp' }), el('button', { textContent: 'Abbrechen', onclick: () => dlg.close() }), el('button', { class: 'primary', textContent: 'Speichern', onclick: save })));
  dlg.onkeydown = e => { if (e.key === 'Enter' && e.target.tagName === 'INPUT') save(); };
  dlg.showModal(); name.focus();
}

export async function trashDialog(tab = 'trash') {
  const dlg = $('#trashDlg'), [trash, arch] = await Promise.all([api('GET', '/trash'), api('GET', '/archive')]);
  const reload = t => async fn => { try { await fn(); } catch (e) { fail(e); } await S.hooks.refresh(); trashDialog(t); };
  const body = tab === 'trash' ? [
    ...(trash.length ? trash.map(i => el('div', { class: 'brow' }, el('span', { class: 'grow', textContent: i.title }), el('span', { class: 'muted', textContent: `${i.board_name} · ${fmtDateTime(i.deleted_at)}` }),
      el('button', { textContent: 'Wiederherstellen', onclick: () => reload('trash')(() => api('POST', `/cards/${i.id}/restore`)) }), el('button', { class: 'danger', textContent: 'Endgültig', onclick: () => reload('trash')(() => api('POST', `/cards/${i.id}/purge`)) })))
      : [el('p', { class: 'muted', textContent: 'Der Papierkorb ist leer.' })]),
    trash.length ? el('button', { class: 'danger', textContent: 'Papierkorb leeren', onclick: async () => { if (await confirmBox('Papierkorb leeren?', `${trash.length} Karten werden endgültig gelöscht.`, 'Leeren')) reload('trash')(() => api('POST', '/trash/empty')); } }) : ''
  ] : [
    ...arch.boards.map(b => el('div', { class: 'brow' }, el('b', { class: 'grow', textContent: '▦ Board: ' + b.name }), el('button', { textContent: 'Wiederherstellen', onclick: () => reload('archive')(() => api('PATCH', '/boards/' + b.id, { archived: 0 })) }),
      el('button', { class: 'danger', textContent: 'Endgültig', onclick: async () => { if (await confirmBox('Board endgültig löschen?', b.name)) reload('archive')(() => api('DELETE', '/boards/' + b.id)); } }))),
    ...arch.columns.map(k => el('div', { class: 'brow' }, el('span', { class: 'grow', textContent: 'Spalte: ' + k.name }), el('span', { class: 'muted', textContent: k.board_name }), el('button', { textContent: 'Wiederherstellen', onclick: () => reload('archive')(() => api('PATCH', '/columns/' + k.id, { archived: 0 })) }),
      el('button', { class: 'danger', textContent: 'Endgültig', onclick: async () => { if (await confirmBox('Spalte endgültig löschen?', k.name)) reload('archive')(() => api('DELETE', '/columns/' + k.id)); } }))),
    ...arch.cards.map(c => el('div', { class: 'brow' }, el('span', { class: 'grow', textContent: c.title }), el('span', { class: 'muted', textContent: c.board_name }), el('button', { textContent: 'Wiederherstellen', onclick: () => reload('archive')(() => api('PATCH', '/cards/' + c.id, { archived: 0 })) }))),
    !arch.boards.length && !arch.columns.length && !arch.cards.length ? el('p', { class: 'muted', textContent: 'Das Archiv ist leer.' }) : ''];
  dlg.replaceChildren(el('div', { class: 'dlg-head' }, el('h3', { class: 'grow', textContent: tab === 'trash' ? 'Papierkorb' : 'Archiv' }), el('button', { textContent: '✕', onclick: () => dlg.close() })),
    el('div', { class: 'stabs' }, el('button', { class: tab === 'trash' ? 'on' : '', textContent: `Papierkorb (${trash.length})`, onclick: () => trashDialog('trash') }), el('button', { class: tab === 'archive' ? 'on' : '', textContent: 'Archiv', onclick: () => trashDialog('archive') })), el('div', { class: 'sbody' }, body));
  if (!dlg.open) dlg.showModal();
}

export async function quickDialog() {
  const dlg = $('#askDlg'); const boards = S.boards; if (!boards.length) return toast('Lege zuerst ein Board an');
  const title = el('input', { placeholder: 'Was ist zu tun? (Enter)' }), due = el('input', { type: 'date' });
  const b = el('select', {}, boards.map(x => el('option', { value: x.id, textContent: x.name, selected: x.id === S.id }))), c = el('select');
  const fill = async () => { const d = S.data && +b.value === S.id ? S.data : await api('GET', '/boards/' + b.value); c.replaceChildren(...d.columns.filter(k => !k.done).map(k => el('option', { value: k.id, textContent: k.name }))); };
  b.onchange = fill; await fill();
  const send = async () => { if (!title.value.trim()) return title.focus(); try { const r = await api('POST', '/cards', { column_id: +c.value, title: title.value, fields: due.value ? { due_date: due.value } : {} }); undo.push({ label: 'Karte erstellt', undo: () => api('DELETE', '/cards/' + r.id), redo: () => api('POST', `/cards/${r.id}/restore`) }); toast('Karte erstellt'); title.value = ''; title.focus(); await S.hooks.refresh(); } catch (e) { fail(e); } };
  dlg.replaceChildren(el('h3', { textContent: 'Schnellerfassung' }), title, el('div', { class: 'row' }, el('div', {}, el('label', { textContent: 'Board' }), b), el('div', {}, el('label', { textContent: 'Spalte' }), c), el('div', {}, el('label', { textContent: 'Fällig' }), due)),
    el('div', { class: 'actions' }, el('span', { class: 'muted small', textContent: 'Enter = anlegen & weiter · Esc = schließen' }), el('span', { class: 'sp' }), el('button', { class: 'primary', textContent: 'Hinzufügen', onclick: send })));
  dlg.onkeydown = e => { if (e.key === 'Enter' && e.target.tagName !== 'SELECT') { e.preventDefault(); send(); } }; dlg.onclose = null;
  dlg.showModal(); title.focus();
}

export function helpDialog() {
  const dlg = $('#askDlg');
  const keys = [['n', 'Neue Karte (in Spalte der markierten Karte)'], ['q', 'Schnellerfassung'], ['/', 'Suche'], ['b', 'Neues Board'], ['j / k  oder  ↓ / ↑', 'Karte darunter / darüber'], ['h / l  oder  ← / →', 'Zur Spalte links / rechts'], ['Enter / o', 'Karte öffnen'], ['0 1 2 3', 'Priorität setzen (keine / niedrig / hoch / dringend)'],
    ['x', 'Karte markieren (Mehrfachauswahl)'], ['Strg+Klick', 'Mehrere Karten markieren'], ['d', 'Erledigt umschalten'], ['c', 'Karte kopieren'], ['a', 'Archivieren'], ['Entf', 'In den Papierkorb'], ['Strg+Z / Strg+Y', 'Rückgängig / Wiederholen'], ['Alt+1 … 5', 'Ansicht: Board, Liste, Kalender, Zeitleiste, Woche'], ['Esc', 'Auswahl aufheben / Fenster schließen'], ['?', 'Diese Hilfe']];
  dlg.replaceChildren(el('h3', { textContent: 'Tastenkürzel' }), el('div', { class: 'keys' }, keys.map(([k, t]) => [el('kbd', { textContent: k }), el('span', { textContent: t })])),
    el('p', { class: 'muted small', textContent: 'Schnellerfassung ohne Browser-Tab: Öffne /schnell.html als eigenes App-Fenster (Chrome/Edge: Menü → Installieren) und lege dafür ein Tastenkürzel im Betriebssystem an.' }),
    el('div', { class: 'actions' }, el('span', { class: 'sp' }), el('button', { class: 'primary', textContent: 'Schließen', onclick: () => dlg.close() })));
  dlg.onkeydown = null; dlg.onclose = null; dlg.showModal();
}

export function gate(session, onDone) {
  const g = $('#gate'); g.hidden = false;
  const locked = session.locked, name = el('input', { placeholder: 'Name', autocomplete: 'username' }), pw = el('input', { type: 'password', placeholder: locked ? 'Dateipasswort' : 'Passwort', autocomplete: 'current-password' }), err = el('div', { class: 'err' });
  const go = async () => {
    err.textContent = '';
    try { if (locked) await api('POST', '/unlock', { password: pw.value }); else await api('POST', '/login', { name: name.value, password: pw.value }); g.hidden = true; onDone(); } catch (e) { err.textContent = e.message; pw.select(); }
  };
  const submit = e => { if (e.key === 'Enter') go(); };
  name.onkeydown = pw.onkeydown = submit;
  g.replaceChildren(el('div', { class: 'gatebox' }, el('h2', { textContent: locked ? '🔐 Datei entsperren' : '🔒 Anmelden' }), el('p', { class: 'muted', textContent: locked ? 'Die Datenbank ist verschlüsselt.' : 'Lokales Kanban' }), ...(locked ? [] : [name]), pw, err, el('button', { class: 'primary', textContent: locked ? 'Entsperren' : 'Anmelden', onclick: go })));
  (locked ? pw : name).focus();
}
