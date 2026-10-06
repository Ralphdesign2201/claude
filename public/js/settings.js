// Einstellungen: Darstellung, Sicherung, Sicherheit, Netzwerk, Import/Export, Datenschutz
import { $, el, S, api, fail, toast, pref, ask, confirmBox, fmtDateTime } from './util.js';
import { RULES, askPermission } from './notify.js';
import { manageLabels } from './card.js';

export function applyPrefs() {
  const t = pref.get('theme', 'auto');
  t === 'auto' ? delete document.documentElement.dataset.theme : document.documentElement.dataset.theme = t;
  document.body.classList.toggle('compact', pref.get('density', 'comfortable') === 'compact');
}
const row = (label, ...k) => el('div', { class: 'srow' }, el('label', { textContent: label }), el('div', {}, ...k));
const select = (opts, val, on) => el('select', { onchange: e => on(e.target.value) }, opts.map(([v, l]) => el('option', { value: v, textContent: l, selected: v === val })));
const dl = (href, text) => el('a', { class: 'btn', href, textContent: text });

export async function openSettings(tab = 'allgemein') {
  const dlg = $('#settingsDlg'); const s = S.settings = await api('GET', '/settings'), info = await api('GET', '/info');
  const users = await api('GET', '/users').catch(() => []);
  const me = S.session.user, isAdmin = !users.length || me?.role === 'admin';
  const put = async (patch, msg) => { try { S.settings = await api('PUT', '/settings', patch); if (msg) toast(msg); S.hooks.render(); } catch (e) { fail(e); } };
  const tabs = { allgemein: 'Allgemein', sicherung: 'Sicherung', sicherheit: 'Sicherheit', netzwerk: 'Netzwerk', daten: 'Import / Export', datenschutz: 'Datenschutz' };
  const body = el('div', { class: 'sbody' });

  const views = {
    allgemein: () => [
      row('Darstellung', select([['auto', 'Automatisch (System)'], ['light', 'Hell'], ['dark', 'Dunkel']], pref.get('theme', 'auto'), v => { pref.set('theme', v); applyPrefs(); })),
      row('Kartengröße', select([['comfortable', 'Bequem'], ['compact', 'Kompakt']], pref.get('density', 'comfortable'), v => { pref.set('density', v); applyPrefs(); })),
      row('Mein Anzeigename', el('input', { value: s.display_name, onchange: e => put({ display_name: e.target.value }, 'Gespeichert') }), el('div', { class: 'muted small', textContent: 'Erscheint im Aktivitätsverlauf und bei Zeiteinträgen (wenn keine Benutzer angelegt sind).' })),
      row('Erinnerungen', ...RULES.map(([k, l]) => el('label', { class: 'chkl' }, el('input', { type: 'checkbox', checked: pref.get('reminders', ['day']).includes(k), onchange: e => { const r = new Set(pref.get('reminders', ['day'])); e.target.checked ? r.add(k) : r.delete(k); pref.set('reminders', [...r]); } }), ' ' + l)),
        el('button', { textContent: 'Systembenachrichtigungen erlauben', onclick: async () => toast('Berechtigung: ' + await askPermission()) }), el('div', { class: 'muted small', textContent: 'Erinnerungen erscheinen, solange das Kanban-Fenster im Browser geöffnet ist. Eine Uhrzeit an der Karte macht „1 Stunde vorher“ genau.' })),
      row('Labels', el('button', { textContent: 'Labels verwalten …', onclick: () => manageLabels() })),
      row('CRM-Verknüpfung', el('input', { value: s.crm_url, placeholder: 'z. B. http://localhost/crm/kunde/{ref}', onchange: e => put({ crm_url: e.target.value }, 'Gespeichert') }), el('div', { class: 'muted small', textContent: 'Optional. Die CRM-Referenz einer Karte ersetzt {ref} und wird dann als Link angezeigt. Auswertungen und CSV enthalten Kunde und Referenz zum Import ins CRM.' })),
      row('Tastenkürzel', el('button', { textContent: 'Übersicht anzeigen (?)', onclick: () => { dlg.close(); S.hooks.help(); } })),
    ],
    sicherung: () => {
      const list = el('div', { class: 'blist' }, 'Lade …');
      api('GET', '/backups').then(b => list.replaceChildren(...(b.list.length ? b.list.map(x => el('div', { class: 'brow' }, el('span', { class: 'grow', textContent: x.name + (x.encrypted ? ' 🔒' : '') }), el('span', { class: 'muted', textContent: Math.round(x.size / 1024) + ' KB' }),
        isAdmin ? el('button', { textContent: 'Wiederherstellen', onclick: async () => { if (await confirmBox('Sicherung wiederherstellen?', 'Der aktuelle Stand wird vorher automatisch gesichert. Danach wird alles neu geladen.', 'Wiederherstellen')) { try { await api('POST', '/backups/restore', { name: x.name }); toast('Wiederhergestellt'); location.reload(); } catch (e) { fail(e); } } } }) : '')) : [el('p', { class: 'muted', textContent: 'Noch keine Sicherungen.' })])));
      return [
        row('Datenordner', el('code', { textContent: info.data_dir }), el('div', { class: 'muted small', textContent: 'Datenbank: ' + info.db_file + ' · Anhänge: ' + info.files_dir + '. Ordner kopieren = alles gesichert.' })),
        row('Automatisch', el('div', { class: 'muted', textContent: 'Täglich beim ersten Start des Tages und stündlich geprüft.' }), el('label', { textContent: 'Anzahl aufbewahrter Sicherungen' }), el('input', { type: 'number', min: 1, max: 365, value: s.backup_keep, onchange: e => put({ backup_keep: e.target.value }, 'Gespeichert') })),
        row('Zusätzlicher Ordner', el('input', { value: s.backup_dir2, placeholder: 'z. B. E:\\Sicherung oder /mnt/nas/kanban', onchange: e => put({ backup_dir2: e.target.value }, 'Gespeichert') }), el('div', { class: 'muted small', textContent: 'USB-Stick oder NAS. Jede Sicherung wird zusätzlich dorthin kopiert.' })),
        row('Jetzt sichern', el('button', { class: 'primary', textContent: 'Sicherung erstellen', onclick: async () => { try { const r = await api('POST', '/backup'); toast('Gesichert: ' + r.file + (r.second ? (r.second.startsWith('FEHLER') ? ' – ' + r.second : ' (+ zweiter Ordner)') : '')); openSettings('sicherung'); } catch (e) { fail(e); } } })),
        row('Vorhandene', list)];
    },
    sicherheit: () => [
      el('h4', { textContent: 'Passwortschutz & Benutzer' }),
      el('p', { class: 'muted', textContent: users.length ? 'Beim Start ist eine Anmeldung nötig.' : 'Ohne Benutzer ist das Programm offen (nur auf diesem Rechner erreichbar). Lege einen Benutzer an, um beim Start ein Passwort zu verlangen.' }),
      ...users.map(u => el('div', { class: 'brow' }, el('b', { class: 'grow', textContent: u.name + (u.role === 'admin' ? ' (Administrator)' : '') }),
        el('button', { textContent: 'Passwort ändern', onclick: async () => { if (!isAdmin && me.id !== u.id) return; const v = await ask({ title: 'Neues Passwort für ' + u.name, fields: [{ name: 'p', type: 'password', label: 'Passwort (mind. 6 Zeichen)' }] }); if (v) try { await api('PATCH', '/users/' + u.id, { password: v.p }); toast('Passwort geändert'); } catch (e) { fail(e); } } }),
        isAdmin ? el('button', { class: 'danger', textContent: 'Entfernen', onclick: async () => { if (await confirmBox('Benutzer entfernen?', u.name + (users.length === 1 ? ' – danach ist keine Anmeldung mehr nötig.' : ''), 'Entfernen')) try { await api('DELETE', '/users/' + u.id); S.session = await api('GET', '/session'); openSettings('sicherheit'); } catch (e) { fail(e); } } }) : '')),
      isAdmin ? el('button', { class: 'primary', textContent: users.length ? '+ Benutzer hinzufügen' : '🔒 Passwortschutz einrichten', onclick: async () => {
        const v = await ask({ title: users.length ? 'Neuer Benutzer' : 'Passwortschutz einrichten', fields: [{ name: 'n', label: 'Name', value: users.length ? '' : s.display_name }, { name: 'p', type: 'password', label: 'Passwort (mind. 6 Zeichen)' }, ...(users.length ? [{ name: 'r', type: 'select', label: 'Rolle', options: [['user', 'Benutzer'], ['admin', 'Administrator']] }] : [])], ok: 'Anlegen' });
        if (v) try { await api('POST', '/users', { name: v.n, password: v.p, role: v.r }); S.session = await api('GET', '/session'); S.hooks.sessionChanged(); openSettings('sicherheit'); } catch (e) { fail(e); } } }) : '',
      users.length ? el('button', { textContent: 'Abmelden', onclick: async () => { await api('POST', '/logout'); location.reload(); } }) : '',
      el('hr'), el('h4', { textContent: 'Datei verschlüsseln' }),
      el('p', { class: 'muted', textContent: info.encrypted ? 'Datenbank, Anhänge und Sicherungen sind mit AES-256 verschlüsselt. Beim Start fragt das Programm nach dem Dateipasswort. Ohne Passwort sind die Daten unwiederbringlich. Während der Laufzeit liegt eine entschlüsselte Arbeitskopie im temporären Ordner des Systems; sie wird beim Beenden gelöscht.' : 'Optional: Datenbank, Anhänge und Sicherungen werden verschlüsselt gespeichert. Das Passwort kann nicht wiederhergestellt werden – bewahre es sicher auf.' }),
      isAdmin ? el('button', { class: info.encrypted ? '' : 'primary', textContent: info.encrypted ? 'Verschlüsselung ausschalten …' : '🔐 Verschlüsselung einschalten …', onclick: async () => {
        const v = await ask({ title: info.encrypted ? 'Verschlüsselung ausschalten' : 'Verschlüsselung einschalten', text: info.encrypted ? 'Zur Bestätigung das Dateipasswort eingeben.' : 'Wähle ein Dateipasswort (mind. 6 Zeichen). Merke es dir gut!', fields: [{ name: 'p', type: 'password', label: 'Dateipasswort' }], ok: 'Bestätigen' });
        if (v) try { await api('POST', '/security/encryption', { enable: !info.encrypted, password: v.p }); toast(info.encrypted ? 'Verschlüsselung ausgeschaltet' : 'Datei ist jetzt verschlüsselt'); openSettings('sicherheit'); } catch (e) { fail(e); } } }) : ''],
    netzwerk: () => [
      el('p', { class: 'muted', textContent: 'Standardmäßig ist das Kanban nur auf diesem Rechner erreichbar (127.0.0.1). Auf Wunsch kannst du es im Heimnetz freigeben: mit Benutzern, Zuweisung und Live-Aktualisierung. Dafür muss mindestens ein Benutzer mit Passwort angelegt sein (Reiter „Sicherheit“).' }),
      el('label', { class: 'chkl' }, el('input', { type: 'checkbox', checked: !!s.lan, disabled: !isAdmin, onchange: async e => { try { await put({ lan: e.target.checked }); toast(e.target.checked ? 'Freigabe wird aktiviert … Seite lädt neu' : 'Freigabe wird beendet …'); setTimeout(() => location.reload(), 1500); } catch { e.target.checked = !e.target.checked; } } }), ' Im lokalen Netzwerk freigeben'),
      el('p', {}, el('b', { textContent: 'Erreichbar unter: ' }), ...info.urls.map(u => el('code', { textContent: u + '  ' }))),
      el('p', { class: 'muted small', textContent: 'Die Verbindung ist unverschlüsselt (http). Nutze die Freigabe nur in einem vertrauenswürdigen Heimnetz, niemals aus dem Internet erreichbar machen.' })],
    daten: () => {
      const file = el('input', { type: 'file', accept: '.json,.csv', hidden: true, onchange: async e => {
        const f = e.target.files[0]; if (!f) return; const text = await f.text(), csv = /\.csv$/i.test(f.name);
        try { const r = await api('POST', '/import', { kind: csv ? 'csv' : 'json', text, name: f.name.replace(/\.\w+$/, '') }); toast(`${r.boards.length} Board(s) importiert`); await S.hooks.reloadAll(r.boards[0]); dlg.close(); } catch (x) { fail(x); } e.target.value = ''; } });
      return [
        el('h4', { textContent: 'Export' }),
        row('Alles', dl('/api/export/json', '⤓ Vollständig (JSON)'), dl('/api/export/json?files=1', '⤓ Mit Anhängen (JSON)'), dl('/api/export/cards.csv', '⤓ Karten (CSV)'), dl('/api/export/markdown', '⤓ Zum Lesen (Markdown)')),
        S.id ? row('Aktuelles Board', dl(`/api/boards/${S.id}/export?format=json&files=1`, 'JSON'), dl(`/api/boards/${S.id}/export?format=csv`, 'CSV'), dl(`/api/boards/${S.id}/export?format=md`, 'Markdown'), el('button', { textContent: '🖨 Drucken / PDF', onclick: () => { dlg.close(); setTimeout(() => print(), 200); } })) : '',
        el('hr'), el('h4', { textContent: 'Import' }),
        el('p', { class: 'muted', textContent: 'Unterstützt: Export dieses Programms (JSON), Trello (Board → Menü → Drucken und Exportieren → JSON), CSV aus Excel und anderen Kanban-Tools (Spalten: Titel, Spalte/Liste, Beschreibung, Fällig, Priorität, Labels, Person, Kunde). Importierte Daten kommen als neue Boards dazu.' }),
        el('button', { class: 'primary', textContent: '⤒ Datei importieren …', onclick: () => file.click() }), file];
    },
    datenschutz: () => {
      const out = el('div', { class: 'muted', textContent: '' });
      return [
        el('p', {}, el('b', { textContent: 'Keine Telemetrie, keine Cloud, keine Konten.' })),
        row('Server lauscht auf', el('code', { textContent: `${info.bind}:${info.port}` }), el('span', { class: 'muted', textContent: info.bind === '127.0.0.1' ? '  nur dieser Rechner' : '  Heimnetz freigegeben' })),
        row('Ausgehende Verbindungen', el('b', { textContent: info.outgoing }), el('div', { class: 'muted small', textContent: 'Der Server enthält keinen Code, der ins Internet verbindet. Alle Dateien (Oberfläche, Schriften, Symbole) liegen lokal.' })),
        row('Browser-Sperre (CSP)', el('code', { textContent: info.csp })),
        row('Selbsttest', el('button', { textContent: 'Internetzugriff testen', onclick: async () => {
          out.textContent = 'Teste …';
          try { await fetch('https://example.com/', { mode: 'no-cors' }); out.textContent = '⚠ Verbindung nach außen war möglich (ungewöhnlich).'; } catch { out.textContent = '✓ Blockiert: Die Seite darf keine Verbindung ins Internet aufbauen.'; } } }), out),
        row('Daten', el('code', { textContent: info.data_dir }), el('div', { class: 'muted small', textContent: `Rechner: ${info.host} · Node ${info.node} · ${info.encrypted ? 'verschlüsselt' : 'unverschlüsselt'}` })),
        row('Mehrere Rechner', el('div', { class: 'muted small', textContent: 'Den Datenordner kannst du per Sync-Dienst oder USB mitnehmen. Eine Sperrdatei (kanban.lock) verhindert, dass zwei Rechner gleichzeitig schreiben.' }))];
    },
  };
  const draw = t => { body.replaceChildren(...views[t]()); $$tabs().forEach(b => b.classList.toggle('on', b.dataset.t === t)); };
  const $$tabs = () => [...dlg.querySelectorAll('.stabs button')];
  dlg.replaceChildren(el('div', { class: 'dlg-head' }, el('h3', { class: 'grow', textContent: 'Einstellungen' }), el('button', { textContent: '✕', onclick: () => dlg.close() })),
    el('div', { class: 'stabs' }, Object.entries(tabs).map(([k, l]) => el('button', { dataset: { t: k }, textContent: l, onclick: () => draw(k) }))), body);
  draw(tab); if (!dlg.open) dlg.showModal();
}
