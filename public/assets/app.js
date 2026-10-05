(function () {
  'use strict';

  /* ---------- Hilfsfunktionen ---------- */
  var $ = function (s, el) { return (el || document).querySelector(s); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var eur = function (n) { return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(n || 0); };
  var fdate = function (iso) { return iso ? new Date(iso).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '–'; };
  var r2 = function (n) { return Math.round(n * 100) / 100; };
  var hrs = function (min) { return (min / 60).toLocaleString('de-DE', { maximumFractionDigits: 2 }) + ' Std.'; };
  var today = function () { return new Date().toISOString().slice(0, 10); };
  var noon = function (dateStr) { return new Date(dateStr + 'T12:00:00').toISOString(); };
  var lsGet = function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } };
  var lsSet = function (k, v) { try { localStorage.setItem(k, v); } catch (e) { /* Speicher nicht verfügbar */ } };
  var lsDel = function (k) { try { localStorage.removeItem(k); } catch (e) { /* Speicher nicht verfügbar */ } };

  var CLIENT_STATUS = { LEAD: ['Lead', 'info'], ACTIVE: ['Aktiv', 'good'], INACTIVE: ['Inaktiv', 'neutral'], ARCHIVED: ['Archiviert', 'neutral'] };
  var PROJECT_STATUS = { PLANNED: ['Geplant', 'neutral'], IN_PROGRESS: ['In Arbeit', 'info'], REVIEW: ['Abnahme', 'warn'], DONE: ['Fertig', 'good'], ON_HOLD: ['Pausiert', 'neutral'], CANCELLED: ['Abgebrochen', 'neutral'] };
  var INVOICE_STATUS = { DRAFT: ['Entwurf', 'neutral'], SENT: ['Versendet', 'info'], PAID: ['Bezahlt', 'good'], OVERDUE: ['Überfällig', 'bad'], CANCELLED: ['Storniert', 'neutral'] };
  var CONTRACT_STATUS = { DRAFT: ['Entwurf', 'neutral'], SENT: ['Versendet', 'info'], SIGNED: ['Unterschrieben', 'good'], CANCELLED: ['Storniert', 'neutral'] };
  var PRIORITY = { LOW: ['Niedrig', 'neutral'], MEDIUM: ['Mittel', 'info'], HIGH: ['Hoch', 'warn'], URGENT: ['Dringend', 'bad'] };
  var COLS = [['OPEN', 'Offen'], ['IN_PROGRESS', 'In Arbeit'], ['DONE', 'Erledigt']];
  var pill = function (map, key) { var m = map[key] || [key, 'neutral']; return '<span class="pill ' + m[1] + '">' + esc(m[0]) + '</span>'; };
  var opts = function (map) { return Object.keys(map).map(function (k) { return [k, map[k][0]]; }); };

  /* ---------- API ---------- */
  var TOKEN_KEY = 'crm-token';
  var token = lsGet(TOKEN_KEY);
  var me = null;

  function api(method, path, body, isForm) {
    var headers = {};
    if (token) headers.Authorization = 'Bearer ' + token;
    var init = { method: method, headers: headers };
    if (body !== undefined) {
      if (isForm) init.body = body;
      else { headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(body); }
    }
    return fetch(path, init).catch(function () { throw new Error('Server nicht erreichbar'); }).then(function (res) {
      if (res.status === 204) return null;
      return res.json().catch(function () { return null; }).then(function (data) {
        if (!res.ok) {
          if (res.status === 401 && token && path.indexOf('/api/auth/login') < 0) logout();
          var msg = (data && data.error) || ('Fehler ' + res.status);
          var fe = data && data.details && data.details.fieldErrors;
          if (fe && Object.keys(fe).length) { var k = Object.keys(fe)[0]; msg += ' (' + k + ': ' + fe[k][0] + ')'; }
          throw new Error(msg);
        }
        return data;
      });
    });
  }
  var qs = function (o) {
    var p = Object.keys(o).filter(function (k) { return o[k] !== '' && o[k] != null; }).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(o[k]); });
    return p.length ? '?' + p.join('&') : '';
  };
  var listAll = function (path, params) { return api('GET', path + qs(Object.assign({ pageSize: 100 }, params || {}))).then(function (r) { return r.items; }); };

  /* ---------- Zustand ---------- */
  var ui = { view: 'dashboard', clientId: null, projectId: null, invoiceId: null, q: '', cstatus: '', istatus: '' };

  /* ---------- Rechnungs-Hilfen ---------- */
  function shownStatus(inv) {
    if (inv.status === 'SENT' && inv.totals.balance > 0 && inv.dueDate && new Date(inv.dueDate) < new Date()) return 'OVERDUE';
    return inv.status;
  }
  function previewTotals(items, rate, discount) {
    var sub = r2(items.reduce(function (s, i) { return s + r2(i.quantity * i.unitPrice); }, 0));
    var net = sub - discount, tax = r2(net * rate / 100);
    return { sub: sub, tax: tax, total: Math.max(0, r2(net + tax)) };
  }

  /* ---------- Anmeldung ---------- */
  function logout() {
    token = null; me = null; lsDel(TOKEN_KEY);
    renderLogin();
  }
  function renderLogin(mode, error) {
    var reg = mode === 'register';
    $('#root').innerHTML = '<div class="login"><form id="lform" novalidate><h1>' + (reg ? 'Ersten Benutzer anlegen' : 'Webdesigner CRM') + '</h1>' +
      '<div class="sub">' + (reg ? 'Der erste Benutzer wird automatisch Administrator.' : 'Bitte melde dich an.') + '</div>' +
      (error ? '<div class="err" role="alert">' + esc(error) + '</div>' : '') +
      (reg ? '<label for="lname">Name<input id="lname" autocomplete="name"></label>' : '') +
      '<label for="lemail">E-Mail<input id="lemail" type="email" autocomplete="username"></label>' +
      '<label for="lpass">Passwort<input id="lpass" type="password" autocomplete="' + (reg ? 'new-password' : 'current-password') + '"></label>' +
      '<button class="btn primary" type="submit" style="justify-content:center">' + (reg ? 'Registrieren' : 'Anmelden') + '</button>' +
      '<button class="switch" type="button" id="lswitch">' + (reg ? 'Zurück zur Anmeldung' : 'Noch kein Konto? Ersten Benutzer anlegen') + '</button></form></div>';
    $('#lemail').focus();
    $('#lswitch').onclick = function () { renderLogin(reg ? 'login' : 'register'); };
    $('#lform').onsubmit = function (e) {
      e.preventDefault();
      var body = { email: $('#lemail').value.trim(), password: $('#lpass').value };
      if (reg) body.name = $('#lname').value.trim();
      var btn = $('#lform button[type=submit]'); btn.disabled = true;
      api('POST', reg ? '/api/auth/register' : '/api/auth/login', body).then(function (res) {
        token = res.token; me = res.user; lsSet(TOKEN_KEY, token); ui.view = 'dashboard'; render();
      }).catch(function (err) { renderLogin(mode, err.message); });
    };
  }

  /* ---------- Rahmen ---------- */
  function navItems() {
    var n = [['dashboard', 'Dashboard'], ['clients', 'Kunden'], ['projects', 'Projekte'], ['times', 'Zeiten'], ['invoices', 'Rechnungen']];
    if (me && me.role === 'ADMIN') n.push(['team', 'Team']);
    return n;
  }
  function renderShell() {
    if (!$('#main')) {
      $('#root').innerHTML = '<div class="app"><aside class="side"><div class="brand"><i>W</i><span>Webdesigner CRM</span></div><nav class="nav" id="nav" aria-label="Bereiche"></nav>' +
        '<div class="side-foot"><div class="user"><span class="avatar" id="sb-avatar"></span><span id="sb-name"></span></div><button class="btn sm" data-act="logout">Abmelden</button></div></aside><main class="main" id="main"></main></div>';
    }
    $('#sb-avatar').textContent = (me.name || '?').split(/\s+/).map(function (w) { return w[0]; }).join('').slice(0, 2).toUpperCase();
    $('#sb-name').innerHTML = esc(me.name) + '<br><span class="sub">' + (me.role === 'ADMIN' ? 'Admin' : 'Mitarbeiter') + '</span>';
    $('#nav').innerHTML = navItems().map(function (n) {
      var cur = ui.view === n[0] || (n[0] === 'clients' && ui.view === 'client') || (n[0] === 'invoices' && ui.view === 'invoice');
      return '<button data-act="nav" data-v="' + n[0] + '"' + (cur ? ' aria-current="page"' : '') + '>' + n[1] + '</button>';
    }).join('');
  }
  var head = function (title, sub, actions) {
    return '<div class="head"><div><h1>' + title + '</h1>' + (sub ? '<p>' + sub + '</p>' : '') + '</div><div class="row">' + (actions || '') + '</div></div>';
  };
  var kpi = function (l, v, s, cls) { return '<div class="card kpi ' + (cls || '') + '"><span class="l">' + l + '</span><span class="v">' + v + '</span><span class="s">' + s + '</span></div>'; };

  /* ---------- Ansichten ---------- */
  function vDashboard() {
    return Promise.all([api('GET', '/api/dashboard/summary'), listAll('/api/invoices')]).then(function (r) {
      var s = r[0], invoices = r[1];
      var openCount = invoices.filter(function (i) { return i.totals.balance > 0 && i.status !== 'CANCELLED'; }).length;
      return head('Guten Tag, ' + esc(me.name.split(' ')[0]), 'Das ist heute los in deinem Studio.', '<button class="btn primary" data-act="new-invoice">+ Rechnung</button><button class="btn" data-act="new-client">+ Kunde</button>') +
        '<div class="grid kpis">' +
        kpi('Bezahlt', eur(s.revenue.paid), 'Zahlungseingänge gesamt') +
        kpi('Offen', eur(s.revenue.outstanding), openCount + ' Rechnungen') +
        kpi('Überfällig', eur(s.revenue.overdue), s.revenue.overdue > 0 ? 'Zahlungserinnerung fällig' : 'Alles im Zeitplan', s.revenue.overdue > 0 ? 'bad' : '') +
        kpi('Aktive Projekte', String(s.projects.active), s.tasks.open + ' offene Aufgaben') + '</div>' +
        '<div class="grid two"><div class="card"><h2>Zahlungseingänge <span class="sub">letzte 6 Monate</span></h2>' + revenueChart(invoices) + '</div>' +
        '<div class="card"><h2>Anstehende Aufgaben</h2><div class="list">' + (s.tasks.upcoming.length ? s.tasks.upcoming.map(function (t) {
          var late = new Date(t.dueDate) < new Date();
          return '<div><div class="grow"><button class="link" data-act="open-project" data-id="' + esc(t.project.id) + '">' + esc(t.title) + '</button><div class="sub">' + esc(t.project.name) + '</div></div><span class="pill ' + (late ? 'bad' : 'neutral') + '">' + fdate(t.dueDate) + '</span></div>';
        }).join('') : '<div class="empty">Keine offenen Aufgaben mit Termin.</div>') + '</div></div></div>' +
        '<div class="card"><h2>Letzte Aktivitäten</h2><div class="list">' + (s.activities.length ? s.activities.slice(0, 8).map(function (a) {
          return '<div><div class="grow">' + esc(a.message) + (a.client ? '<div class="sub">' + esc(a.client.name) + '</div>' : '') + '</div><span class="sub num">' + fdate(a.createdAt) + '</span></div>';
        }).join('') : '<div class="empty">Noch keine Aktivitäten. Lege einen Kunden an, dann geht es los.</div>') + '</div></div>';
    });
  }

  function revenueChart(invoices) {
    var months = [], now = new Date();
    for (var k = 5; k >= 0; k--) { var d = new Date(now.getFullYear(), now.getMonth() - k, 1); months.push({ y: d.getFullYear(), m: d.getMonth(), sum: 0 }); }
    invoices.forEach(function (i) {
      i.payments.forEach(function (p) {
        var d = new Date(p.paidAt);
        months.forEach(function (mo) { if (mo.y === d.getFullYear() && mo.m === d.getMonth()) mo.sum += p.amount; });
      });
    });
    var max = Math.max.apply(null, months.map(function (m) { return m.sum; }).concat([1000]));
    var step = max > 8000 ? 2500 : max > 4000 ? 1000 : 500, top = Math.ceil(max / step) * step;
    var W = 560, H = 230, L = 48, R = 8, T = 22, B = 28, iw = W - L - R, ih = H - T - B, bw = iw / months.length, g = '';
    for (var v = 0; v <= top; v += step) {
      var y = T + ih - (v / top) * ih;
      g += '<line class="grid-l" x1="' + L + '" x2="' + (W - R) + '" y1="' + y + '" y2="' + y + '"/><text x="' + (L - 8) + '" y="' + (y + 4) + '" text-anchor="end">' + (v >= 1000 ? (v / 1000).toLocaleString('de-DE') + ' T' : v) + '</text>';
    }
    months.forEach(function (mo, i) {
      var h = (mo.sum / top) * ih, x = L + i * bw + bw * 0.2, w = bw * 0.6, y2 = T + ih - h;
      g += '<rect class="bar ' + (i === months.length - 1 ? 'cur' : 'past') + '" x="' + x + '" y="' + y2 + '" width="' + w + '" height="' + Math.max(h, 0) + '" rx="4"/>';
      if (mo.sum > 0) g += '<text class="val" x="' + (x + w / 2) + '" y="' + (y2 - 6) + '" text-anchor="middle">' + Math.round(mo.sum).toLocaleString('de-DE') + '</text>';
      g += '<text x="' + (x + w / 2) + '" y="' + (H - 8) + '" text-anchor="middle">' + new Date(mo.y, mo.m, 1).toLocaleDateString('de-DE', { month: 'short' }) + '</text>';
    });
    return '<svg class="chart" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Zahlungseingänge pro Monat in Euro">' + g + '</svg><div class="sub">Beträge in Euro, brutto. T = Tausend.</div>';
  }

  function clientRows(items, total) {
    return '<div class="tablewrap"><table><thead><tr><th>Kunde</th><th>Status</th><th>Ort</th><th class="r">Projekte</th><th class="r">Rechnungen</th></tr></thead><tbody>' +
      (items.length ? items.map(function (c) {
        return '<tr class="click" data-act="open-client" data-id="' + esc(c.id) + '"><td><button class="link" data-act="open-client" data-id="' + esc(c.id) + '">' + esc(c.company || c.name) + '</button><div class="sub">' + esc(c.name) + '</div></td><td>' + pill(CLIENT_STATUS, c.status) + '</td><td>' + esc(c.city || '') + '</td><td class="r num">' + c._count.projects + '</td><td class="r num">' + c._count.invoices + '</td></tr>';
      }).join('') : '<tr><td colspan="5" class="empty">' + (ui.q || ui.cstatus ? 'Kein Kunde passt zu deiner Suche.' : 'Noch keine Kunden. Lege deinen ersten Kunden an.') + '</td></tr>') +
      '</tbody></table></div>' + (total > items.length ? '<div class="sub" style="padding:8px 12px">Es werden die ersten ' + items.length + ' von ' + total + ' Kunden angezeigt. Nutze die Suche.</div>' : '');
  }
  function fetchClients() {
    return api('GET', '/api/clients' + qs({ pageSize: 100, search: ui.q, status: ui.cstatus, sort: 'name' }));
  }
  function vClients() {
    return fetchClients().then(function (res) {
      var chips = [['', 'Alle']].concat(opts(CLIENT_STATUS));
      return head('Kunden', res.meta.total + ' Kunden', '<button class="btn primary" data-act="new-client">+ Neuer Kunde</button>') +
        '<div class="row"><input class="search" id="q" type="search" placeholder="Name, Firma, E-Mail oder Telefon suchen" value="' + esc(ui.q) + '" aria-label="Kunden suchen"><div class="chips">' +
        chips.map(function (c) { return '<button class="chip" data-act="cfilter" data-v="' + c[0] + '" aria-pressed="' + (ui.cstatus === c[0]) + '">' + c[1] + '</button>'; }).join('') + '</div></div>' +
        '<div class="card" style="padding:6px" id="tbl">' + clientRows(res.items, res.meta.total) + '</div>';
    });
  }

  function vClient() {
    return Promise.all([api('GET', '/api/clients/' + ui.clientId), listAll('/api/invoices', { clientId: ui.clientId })]).then(function (r) {
      var c = r[0], invoices = r[1];
      var contact = c.contacts[0];
      var notes = c.notes.slice().sort(function (a, b) { return (b.pinned - a.pinned) || (a.createdAt < b.createdAt ? 1 : -1); });
      return '<button class="btn ghost sm back" data-act="nav" data-v="clients" style="align-self:flex-start">← Alle Kunden</button>' +
        head(esc(c.company || c.name), pill(CLIENT_STATUS, c.status) + ' &nbsp;' + (c.tags ? c.tags.split(',').map(function (t) { return t.trim() ? '<span class="tag">' + esc(t.trim()) + '</span>' : ''; }).join(' ') : ''),
          '<button class="btn" data-act="edit-client" data-id="' + esc(c.id) + '">Bearbeiten</button><button class="btn danger" data-act="del-client" data-id="' + esc(c.id) + '">Löschen</button><button class="btn primary" data-act="new-invoice" data-id="' + esc(c.id) + '">+ Rechnung</button>') +
        '<div class="grid two-eq"><div class="card"><h2>Kontakt</h2><dl class="kv"><dt>Ansprechpartner</dt><dd>' + esc(contact ? contact.name + (contact.role ? ' · ' + contact.role : '') : c.name) + '</dd><dt>E-Mail</dt><dd>' + esc(c.email || '–') + '</dd><dt>Telefon</dt><dd>' + esc(c.phone || '–') + '</dd><dt>Ort</dt><dd>' + esc([c.zip, c.city].filter(Boolean).join(' ') || '–') + '</dd><dt>Website</dt><dd>' + esc(c.website || '–') + '</dd></dl></div>' +
        '<div class="card"><h2>Notizen <button class="btn sm" data-act="new-note" data-id="' + esc(c.id) + '">+ Notiz</button></h2><div class="list">' + (notes.length ? notes.map(function (n) {
          return '<div><div class="grow">' + (n.pinned ? '<span class="pill warn">Angeheftet</span> ' : '') + esc(n.body) + '<div class="sub">' + fdate(n.createdAt) + '</div></div><button class="btn sm ghost" data-act="del-note" data-id="' + esc(n.id) + '" aria-label="Notiz löschen">✕</button></div>';
        }).join('') : '<div class="empty">Noch keine Notizen.</div>') + '</div></div></div>' +
        '<div class="grid two-eq"><div class="card"><h2>Projekte <button class="btn sm" data-act="new-project" data-id="' + esc(c.id) + '">+ Projekt</button></h2><div class="list">' + (c.projects.length ? c.projects.map(function (p) {
          return '<div><div class="grow"><button class="link" data-act="open-project" data-id="' + esc(p.id) + '">' + esc(p.name) + '</button><div class="sub">Budget ' + (p.budget ? eur(p.budget) : '–') + '</div></div>' + pill(PROJECT_STATUS, p.status) + '</div>';
        }).join('') : '<div class="empty">Noch keine Projekte.</div>') + '</div></div>' +
        '<div class="card"><h2>Rechnungen</h2><div class="list">' + (invoices.length ? invoices.map(function (i) {
          return '<div><div class="grow"><button class="link mono" data-act="open-invoice" data-id="' + esc(i.id) + '">' + esc(i.number) + '</button><div class="sub">' + fdate(i.issueDate) + '</div></div><span class="num">' + eur(i.totals.total) + '</span>' + pill(INVOICE_STATUS, shownStatus(i)) + '</div>';
        }).join('') : '<div class="empty">Noch keine Rechnungen.</div>') + '</div></div></div>' +
        '<div class="grid two-eq"><div class="card"><h2>Verträge <button class="btn sm" data-act="new-contract" data-id="' + esc(c.id) + '">+ Vertrag</button></h2><div class="list">' + (c.contracts.length ? c.contracts.map(function (k) {
          return '<div><div class="grow"><b>' + esc(k.title) + '</b><div class="sub">' + (k.value != null ? eur(k.value) + ' · ' : '') + (k.endDate ? 'bis ' + fdate(k.endDate) : 'unbefristet') + '</div></div>' + pill(CONTRACT_STATUS, k.status) + '<button class="btn sm ghost" data-act="del-contract" data-id="' + esc(k.id) + '" aria-label="Vertrag löschen">✕</button></div>';
        }).join('') : '<div class="empty">Noch keine Verträge.</div>') + '</div></div>' +
        '<div class="card"><h2>Dokumente <button class="btn sm" data-act="pick-file">+ Datei</button><input type="file" id="fup" hidden></h2><div class="list">' + (c.documents.length ? c.documents.map(function (d) {
          return '<div><div class="grow"><a class="link" href="' + esc(d.url) + '" target="_blank" rel="noopener">' + esc(d.name) + '</a><div class="sub">' + Math.max(1, Math.round((d.size || 0) / 1024)).toLocaleString('de-DE') + ' KB · ' + fdate(d.createdAt) + '</div></div><button class="btn sm ghost" data-act="del-doc" data-id="' + esc(d.id) + '" aria-label="Dokument löschen">✕</button></div>';
        }).join('') : '<div class="empty">Noch keine Dokumente. Lade Briefings oder Angebote hoch (bis 25 MB).</div>') + '</div></div></div>' +
        '<div class="card"><h2>Verlauf</h2><div class="list">' + (c.activities.length ? c.activities.slice(0, 10).map(function (a) { return '<div><div class="grow">' + esc(a.message) + '</div><span class="sub num">' + fdate(a.createdAt) + '</span></div>'; }).join('') : '<div class="empty">Noch keine Aktivitäten.</div>') + '</div></div>';
    });
  }

  function vProjects() {
    return listAll('/api/projects').then(function (projects) {
      if (!projects.length) return head('Projekte', '', '<button class="btn primary" data-act="new-project">+ Neues Projekt</button>') + '<div class="card empty">Noch keine Projekte. Lege dein erstes Projekt an.</div>';
      if (!projects.some(function (p) { return p.id === ui.projectId; })) ui.projectId = projects[0].id;
      return api('GET', '/api/projects/' + ui.projectId).then(function (p) {
        var tasks = p.tasks;
        var minutes = p.timeEntries.reduce(function (s, t) { return s + t.minutes; }, 0);
        var worth = p.timeEntries.filter(function (t) { return t.billable; }).reduce(function (s, t) { return s + t.minutes / 60 * (p.hourlyRate || 0); }, 0);
        var pct = p.budget ? Math.min(100, Math.round(worth / p.budget * 100)) : 0;
        return head('Projekte', 'Aufgaben per Drag & Drop oder mit den Pfeilen verschieben.', '<button class="btn primary" data-act="new-project">+ Neues Projekt</button>') +
          '<div class="row"><label class="sub" for="psel">Projekt</label><select id="psel" style="min-width:240px">' + projects.map(function (x) { return '<option value="' + esc(x.id) + '"' + (x.id === p.id ? ' selected' : '') + '>' + esc(x.name) + '</option>'; }).join('') + '</select></div>' +
          '<div class="card"><div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))"><div><div class="sub">Kunde</div><button class="link" data-act="open-client" data-id="' + esc(p.client.id) + '">' + esc(p.client.company || p.client.name) + '</button></div>' +
          '<div><div class="sub">Status</div><select id="pstatus" aria-label="Projektstatus">' + opts(PROJECT_STATUS).map(function (o) { return '<option value="' + o[0] + '"' + (o[0] === p.status ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select></div>' +
          '<div><div class="sub">Fällig</div><b>' + fdate(p.dueDate) + '</b></div>' +
          '<div><div class="sub">' + (p.budget ? 'Budget verbraucht' : 'Gebuchte Zeit') + ' · ' + hrs(minutes) + '</div>' + (p.budget ? '<div class="progress' + (worth > p.budget ? ' over' : '') + '" style="margin:8px 0 4px"><i style="width:' + pct + '%"></i></div><span class="num">' + eur(worth) + ' von ' + eur(p.budget) + ' (' + pct + ' %)</span>' : '<div class="num" style="margin-top:6px">' + eur(worth) + ' abrechenbar</div>') + '</div></div></div>' +
          '<div class="board">' + COLS.map(function (col, ci) {
            var list = tasks.filter(function (t) { return t.status === col[0]; });
            return '<div class="col" data-col="' + col[0] + '"><h3><span>' + col[1] + '</span><span class="num">' + list.length + '</span></h3>' + list.map(function (t) {
              return '<div class="task" draggable="true" data-task="' + esc(t.id) + '"><span class="t">' + esc(t.title) + '</span><div class="row">' + pill(PRIORITY, t.priority) + (t.dueDate ? '<span class="sub num">fällig ' + fdate(t.dueDate) + '</span>' : '') + '</div><div class="meta"><button class="btn sm ghost danger" data-act="del-task" data-id="' + esc(t.id) + '" aria-label="Aufgabe löschen">Löschen</button><span class="mv">' +
                '<button data-act="move" data-id="' + esc(t.id) + '" data-d="-1" aria-label="Eine Spalte zurück"' + (ci === 0 ? ' disabled' : '') + '>←</button><button data-act="move" data-id="' + esc(t.id) + '" data-d="1" aria-label="Eine Spalte weiter"' + (ci === 2 ? ' disabled' : '') + '>→</button></span></div></div>';
            }).join('') + (col[0] === 'OPEN' ? '<button class="btn sm" data-act="new-task">+ Aufgabe</button>' : '') + '</div>';
          }).join('') + '</div>';
      });
    });
  }

  function vTimes() {
    return api('GET', '/api/time-entries').then(function (entries) {
      var total = entries.reduce(function (s, e) { return s + e.minutes; }, 0);
      var value = function (e) { return e.billable ? e.minutes / 60 * (e.project.hourlyRate || 0) : 0; };
      var billable = entries.reduce(function (s, e) { return s + value(e); }, 0);
      return head('Zeiterfassung', 'Gebuchte Zeit über alle Projekte.', '<button class="btn primary" data-act="new-time">+ Zeit buchen</button>') +
        '<div class="grid kpis">' + kpi('Gebucht', hrs(total), entries.length + ' Einträge') + kpi('Abrechenbar', eur(billable), 'zum Projekt-Stundensatz') + '</div>' +
        '<div class="card" style="padding:6px"><div class="tablewrap"><table><thead><tr><th>Datum</th><th>Projekt</th><th>Tätigkeit</th><th>Wer</th><th class="r">Dauer</th><th class="r">Wert</th><th></th></tr></thead><tbody>' + (entries.length ? entries.map(function (e) {
          return '<tr><td class="num">' + fdate(e.date) + '</td><td>' + esc(e.project.name) + '</td><td>' + esc(e.description || '–') + (e.billable ? '' : ' <span class="tag">intern</span>') + '</td><td>' + esc(e.user ? e.user.name : '–') + '</td><td class="r num">' + hrs(e.minutes) + '</td><td class="r num">' + (e.billable ? eur(value(e)) : '–') + '</td><td class="r"><button class="btn sm ghost" data-act="del-time" data-id="' + esc(e.id) + '" aria-label="Eintrag löschen">✕</button></td></tr>';
        }).join('') : '<tr><td colspan="7" class="empty">Noch keine Zeiten gebucht.</td></tr>') + '</tbody></table></div></div>';
    });
  }

  function vInvoices() {
    return listAll('/api/invoices').then(function (all) {
      var rows = all.filter(function (i) { return !ui.istatus || shownStatus(i) === ui.istatus; });
      var chips = [['', 'Alle']].concat(opts(INVOICE_STATUS));
      return head('Rechnungen', 'Nummern werden automatisch vergeben.', '<button class="btn primary" data-act="new-invoice">+ Neue Rechnung</button>') +
        '<div class="chips">' + chips.map(function (c) { return '<button class="chip" data-act="ifilter" data-v="' + c[0] + '" aria-pressed="' + (ui.istatus === c[0]) + '">' + c[1] + '</button>'; }).join('') + '</div>' +
        '<div class="card" style="padding:6px"><div class="tablewrap"><table><thead><tr><th>Nummer</th><th>Kunde</th><th>Datum</th><th>Fällig</th><th>Status</th><th class="r">Betrag</th><th class="r">Offen</th></tr></thead><tbody>' + (rows.length ? rows.map(function (i) {
          return '<tr class="click" data-act="open-invoice" data-id="' + esc(i.id) + '"><td><button class="link mono" data-act="open-invoice" data-id="' + esc(i.id) + '">' + esc(i.number) + '</button></td><td>' + esc(i.client.company || i.client.name) + '</td><td class="num">' + fdate(i.issueDate) + '</td><td class="num">' + fdate(i.dueDate) + '</td><td>' + pill(INVOICE_STATUS, shownStatus(i)) + '</td><td class="r num">' + eur(i.totals.total) + '</td><td class="r num">' + (i.totals.balance > 0 && i.status !== 'CANCELLED' ? eur(i.totals.balance) : '–') + '</td></tr>';
        }).join('') : '<tr><td colspan="7" class="empty">' + (ui.istatus ? 'Keine Rechnungen mit diesem Status.' : 'Noch keine Rechnungen.') + '</td></tr>') + '</tbody></table></div></div>';
    });
  }

  function vInvoice() {
    return api('GET', '/api/invoices/' + ui.invoiceId).then(function (i) {
      var t = i.totals, st = shownStatus(i);
      return '<button class="btn ghost sm back" data-act="nav" data-v="invoices" style="align-self:flex-start">← Alle Rechnungen</button>' +
        head('<span class="mono">' + esc(i.number) + '</span>', pill(INVOICE_STATUS, st) + ' &nbsp;<button class="link" data-act="open-client" data-id="' + esc(i.client.id) + '">' + esc(i.client.company || i.client.name) + '</button>' + (i.project ? ' · ' + esc(i.project.name) : ''),
          (i.status === 'DRAFT' ? '<button class="btn" data-act="inv-status" data-v="SENT">Als versendet markieren</button>' : '') +
          (t.balance > 0 && i.status !== 'CANCELLED' ? '<button class="btn primary" data-act="new-payment">Zahlung erfassen</button>' : '') +
          (i.status !== 'CANCELLED' && i.status !== 'PAID' ? '<button class="btn danger" data-act="inv-status" data-v="CANCELLED">Stornieren</button>' : '') +
          (i.status === 'DRAFT' || i.status === 'CANCELLED' ? '<button class="btn danger" data-act="del-invoice">Löschen</button>' : '')) +
        '<div class="grid two"><div class="card"><h2>Positionen</h2><div class="tablewrap"><table><thead><tr><th>Beschreibung</th><th class="r">Menge</th><th class="r">Einzelpreis</th><th class="r">Summe</th></tr></thead><tbody>' + i.items.map(function (x) {
          return '<tr><td>' + esc(x.description) + '</td><td class="r num">' + x.quantity.toLocaleString('de-DE') + '</td><td class="r num">' + eur(x.unitPrice) + '</td><td class="r num">' + eur(r2(x.quantity * x.unitPrice)) + '</td></tr>';
        }).join('') + '</tbody></table></div><div class="sums" style="margin-top:12px"><div><span>Netto</span><span>' + eur(t.subtotal) + '</span></div>' + (i.discount ? '<div><span>Rabatt</span><span>− ' + eur(i.discount) + '</span></div>' : '') + '<div><span>MwSt. ' + i.taxRate + ' %</span><span>' + eur(t.tax) + '</span></div><div class="tot"><span>Gesamt</span><span>' + eur(t.total) + '</span></div></div>' + (i.notes ? '<p class="sub" style="margin-top:12px">' + esc(i.notes) + '</p>' : '') + '</div>' +
        '<div class="card"><h2>Zahlungen</h2><dl class="kv" style="margin-bottom:12px"><dt>Rechnungsdatum</dt><dd>' + fdate(i.issueDate) + '</dd><dt>Fällig am</dt><dd>' + fdate(i.dueDate) + '</dd><dt>Bezahlt</dt><dd class="num">' + eur(t.paid) + '</dd><dt>Offen</dt><dd class="num"><b>' + eur(t.balance) + '</b></dd></dl><div class="list">' + (i.payments.length ? i.payments.map(function (pm) {
          return '<div><div class="grow"><b class="num">' + eur(pm.amount) + '</b><div class="sub">' + fdate(pm.paidAt) + (pm.method ? ' · ' + esc(pm.method) : '') + '</div></div><button class="btn sm ghost" data-act="del-payment" data-id="' + esc(pm.id) + '" aria-label="Zahlung löschen">✕</button></div>';
        }).join('') : '<div class="empty">Noch keine Zahlung eingegangen.</div>') + '</div></div></div>';
    });
  }

  function vTeam() {
    return api('GET', '/api/users').then(function (users) {
      return head('Team', 'Benutzer und Rollen verwalten.', '<button class="btn primary" data-act="new-user">+ Benutzer</button>') +
        '<div class="card" style="padding:6px"><div class="tablewrap"><table><thead><tr><th>Name</th><th>E-Mail</th><th>Rolle</th><th></th></tr></thead><tbody>' + users.map(function (u) {
          var self = u.id === me.id;
          return '<tr><td><b>' + esc(u.name) + '</b>' + (self ? ' <span class="tag">du</span>' : '') + '</td><td>' + esc(u.email) + '</td><td><select class="inline" data-user="' + esc(u.id) + '" aria-label="Rolle von ' + esc(u.name) + '"><option value="ADMIN"' + (u.role === 'ADMIN' ? ' selected' : '') + '>Admin</option><option value="MEMBER"' + (u.role === 'MEMBER' ? ' selected' : '') + '>Mitarbeiter</option></select></td><td class="r">' + (self ? '' : '<button class="btn sm ghost danger" data-act="del-user" data-id="' + esc(u.id) + '">Entfernen</button>') + '</td></tr>';
        }).join('') + '</tbody></table></div></div>';
    });
  }

  /* ---------- Rendern ---------- */
  var VIEWS = { dashboard: vDashboard, clients: vClients, client: vClient, projects: vProjects, times: vTimes, invoices: vInvoices, invoice: vInvoice, team: vTeam };
  var seq = 0;
  function render() {
    if (!token || !me) return;
    var mine = ++seq;
    renderShell();
    var main = $('#main');
    if (!main.firstChild) main.innerHTML = '<div class="empty">Lädt …</div>';
    return VIEWS[ui.view]().then(function (html) {
      if (mine !== seq) return;
      var focusId = document.activeElement && document.activeElement.id === 'q';
      main.innerHTML = html;
      if (focusId) { var q = $('#q'); if (q) { q.focus(); q.setSelectionRange(q.value.length, q.value.length); } }
    }).catch(function (err) {
      if (mine !== seq || !token) return;
      main.innerHTML = '<div class="errbox"><span>' + esc(err.message) + '</span><button class="btn" data-act="retry">Erneut versuchen</button></div>';
    });
  }
  function go(view, extra) { ui.view = view; if (extra) Object.keys(extra).forEach(function (k) { ui[k] = extra[k]; }); render(); window.scrollTo(0, 0); }

  /* ---------- Dialoge ---------- */
  function toast(msg) {
    document.querySelectorAll('.toast').forEach(function (n) { n.remove(); });
    var el = document.createElement('div'); el.className = 'toast'; el.setAttribute('role', 'status'); el.textContent = msg; document.body.appendChild(el);
    setTimeout(function () { el.remove(); }, 2400);
  }
  function closeModal() { $('#layer').innerHTML = ''; }
  function modal(title, body, submitLabel, onSubmit, init, danger) {
    $('#layer').innerHTML = '<div class="overlay" data-act="overlay"><form class="modal" id="mform" role="dialog" aria-modal="true" aria-labelledby="mt" novalidate><h2 id="mt">' + title + '</h2>' + body +
      '<div class="actions"><button type="button" class="btn" data-act="close">Abbrechen</button><button type="submit" class="btn ' + (danger ? 'danger' : 'primary') + '">' + submitLabel + '</button></div></form></div>';
    var f = $('#mform');
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = f.querySelector('button[type=submit]'); btn.disabled = true;
      Promise.resolve().then(function () { return onSubmit(f); }).then(function (res) {
        if (res === false) { btn.disabled = false; return; }
        closeModal();
      }).catch(function (err) { btn.disabled = false; toast(err.message); });
    });
    if (init) init(f);
    var first = f.querySelector('input,select,textarea'); if (first) first.focus(); else f.querySelector('button[type=submit]').focus();
  }
  function confirmDialog(text, label, onConfirm) {
    modal('Bist du sicher?', '<p style="margin:0">' + text + '</p>', label, onConfirm, null, true);
  }
  var field = function (id, label, val, type, extra) { return '<label' + (extra && extra.full ? ' class="full"' : '') + ' for="' + id + '">' + label + '<input id="' + id + '" type="' + (type || 'text') + '" value="' + esc(val == null ? '' : val) + '"' + (extra && extra.attrs ? ' ' + extra.attrs : '') + '></label>'; };
  var selectField = function (id, label, options, cur, full) { return '<label' + (full ? ' class="full"' : '') + ' for="' + id + '">' + label + '<select id="' + id + '">' + options.map(function (o) { return '<option value="' + esc(o[0]) + '"' + (o[0] === cur ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') + '</select></label>'; };
  var v = function (f, id) { return f.querySelector('#' + id).value.trim(); };
  var num = function (f, id) { var x = parseFloat(v(f, id)); return isNaN(x) ? undefined : x; };
  var clean = function (o) { Object.keys(o).forEach(function (k) { if (o[k] === undefined || o[k] === '') delete o[k]; }); return o; };
  var clientOptions = function (clients) { return clients.map(function (c) { return [c.id, c.company || c.name]; }); };
  var bad = function (msg) { toast(msg); return false; };
  var done = function (msg) { toast(msg); render(); };

  function clientDialog(c) {
    var isNew = !c; c = c || { name: '', company: '', email: '', phone: '', city: '', tags: '', status: 'LEAD' };
    modal(isNew ? 'Neuer Kunde' : 'Kunde bearbeiten',
      '<div class="form">' + field('cname', 'Ansprechpartner *', c.name) + field('ccompany', 'Firma', c.company) + field('cemail', 'E-Mail', c.email, 'email') + field('cphone', 'Telefon', c.phone) + field('ccity', 'Ort', c.city) +
      selectField('cstat', 'Status', opts(CLIENT_STATUS), c.status) + field('ctags', 'Tags (mit Komma getrennt)', c.tags, 'text', { full: true }) + '</div>', 'Speichern',
      function (f) {
        if (!v(f, 'cname')) return bad('Bitte einen Namen eingeben.');
        var data = { name: v(f, 'cname'), company: v(f, 'ccompany'), email: v(f, 'cemail'), phone: v(f, 'cphone'), city: v(f, 'ccity'), tags: v(f, 'ctags'), status: v(f, 'cstat') };
        return (isNew ? api('POST', '/api/clients', clean(data)) : api('PATCH', '/api/clients/' + c.id, data)).then(function (res) {
          if (isNew) { ui.clientId = res.id; ui.view = 'client'; }
          done(isNew ? 'Kunde angelegt' : 'Gespeichert');
        });
      });
  }

  function projectDialog(presetClient) {
    listAll('/api/clients', { sort: 'name' }).then(function (clients) {
      if (!clients.length) return toast('Lege zuerst einen Kunden an.');
      modal('Neues Projekt', '<div class="form">' + field('pname', 'Projektname *', '', 'text', { full: true }) + selectField('pcl', 'Kunde', clientOptions(clients), presetClient || (ui.view === 'client' ? ui.clientId : clients[0].id)) + field('pbudget', 'Budget (€)', '', 'number', { attrs: 'min="0" step="50"' }) + field('prate', 'Stundensatz (€)', '90', 'number', { attrs: 'min="0" step="5"' }) + field('pdue', 'Fällig am', '', 'date') + '</div>', 'Anlegen',
        function (f) {
          if (!v(f, 'pname')) return bad('Bitte einen Projektnamen eingeben.');
          return api('POST', '/api/projects', clean({ clientId: v(f, 'pcl'), name: v(f, 'pname'), budget: num(f, 'pbudget'), hourlyRate: num(f, 'prate'), dueDate: v(f, 'pdue') })).then(function (p) { ui.projectId = p.id; ui.view = 'projects'; done('Projekt angelegt'); });
        });
    }).catch(function (e) { toast(e.message); });
  }

  function taskDialog() {
    modal('Neue Aufgabe', '<div class="form">' + field('ttitle', 'Titel *', '', 'text', { full: true }) + selectField('tprio', 'Priorität', opts(PRIORITY), 'MEDIUM') + field('tdue', 'Fällig am', '', 'date') + '</div>', 'Hinzufügen',
      function (f) {
        if (!v(f, 'ttitle')) return bad('Bitte einen Titel eingeben.');
        return api('POST', '/api/projects/' + ui.projectId + '/tasks', clean({ title: v(f, 'ttitle'), priority: v(f, 'tprio'), dueDate: v(f, 'tdue') })).then(function () { done('Aufgabe hinzugefügt'); });
      });
  }

  function timeDialog() {
    listAll('/api/projects').then(function (projects) {
      if (!projects.length) return toast('Lege zuerst ein Projekt an.');
      modal('Zeit buchen', '<div class="form">' + selectField('eproj', 'Projekt', projects.map(function (p) { return [p.id, p.name]; }), ui.projectId || projects[0].id, true) + field('edesc', 'Tätigkeit', '', 'text', { full: true }) + field('ehours', 'Dauer (Stunden)', '1', 'number', { attrs: 'min="0.25" step="0.25"' }) + field('edate', 'Datum', today(), 'date') +
        '<label class="full" style="flex-direction:row;align-items:center;gap:8px"><input id="ebill" type="checkbox" checked> Abrechenbar</label></div>', 'Buchen',
        function (f) {
          var h = num(f, 'ehours');
          if (!(h > 0)) return bad('Bitte eine Dauer über 0 eingeben.');
          return api('POST', '/api/time-entries', clean({ projectId: v(f, 'eproj'), description: v(f, 'edesc'), minutes: Math.round(h * 60), billable: f.querySelector('#ebill').checked, date: noon(v(f, 'edate') || today()) })).then(function () { done('Zeit gebucht'); });
        });
    }).catch(function (e) { toast(e.message); });
  }

  function noteDialog(clientId) {
    modal('Neue Notiz', '<div class="form"><label class="full" for="nbody">Notiz *<textarea id="nbody" rows="4"></textarea></label><label class="full" style="flex-direction:row;align-items:center;gap:8px"><input id="npin" type="checkbox"> Oben anheften</label></div>', 'Speichern',
      function (f) {
        if (!v(f, 'nbody')) return bad('Bitte einen Text eingeben.');
        return api('POST', '/api/notes', { clientId: clientId, body: v(f, 'nbody'), pinned: f.querySelector('#npin').checked }).then(function () { done('Notiz gespeichert'); });
      });
  }

  function contractDialog(clientId) {
    modal('Neuer Vertrag', '<div class="form">' + field('ktitle', 'Titel *', '', 'text', { full: true }) + field('kvalue', 'Wert (€)', '', 'number', { attrs: 'min="0" step="50"' }) + selectField('kstat', 'Status', opts(CONTRACT_STATUS), 'DRAFT') + field('kstart', 'Beginn', '', 'date') + field('kend', 'Ende', '', 'date') + '</div>', 'Anlegen',
      function (f) {
        if (!v(f, 'ktitle')) return bad('Bitte einen Titel eingeben.');
        return api('POST', '/api/contracts', clean({ clientId: clientId, title: v(f, 'ktitle'), value: num(f, 'kvalue'), status: v(f, 'kstat'), startDate: v(f, 'kstart'), endDate: v(f, 'kend') })).then(function () { done('Vertrag angelegt'); });
      });
  }

  function paymentDialog() {
    api('GET', '/api/invoices/' + ui.invoiceId).then(function (i) {
      modal('Zahlung erfassen', '<div class="form">' + field('pamt', 'Betrag (€)', i.totals.balance.toFixed(2), 'number', { attrs: 'min="0.01" step="0.01"' }) + selectField('pmeth', 'Zahlungsart', [['Überweisung', 'Überweisung'], ['PayPal', 'PayPal'], ['Bar', 'Bar']], 'Überweisung') + field('pdate', 'Eingang am', today(), 'date', { full: true }) + '</div><div class="sub">Offen: ' + eur(i.totals.balance) + '. Bei vollständiger Zahlung wird die Rechnung automatisch auf „Bezahlt“ gesetzt.</div>', 'Erfassen',
        function (f) {
          var amt = num(f, 'pamt');
          if (!(amt > 0)) return bad('Bitte einen Betrag über 0 eingeben.');
          return api('POST', '/api/invoices/' + i.id + '/payments', { amount: amt, method: v(f, 'pmeth'), paidAt: noon(v(f, 'pdate') || today()) }).then(function () { done('Zahlung erfasst'); });
        });
    }).catch(function (e) { toast(e.message); });
  }

  function userDialog() {
    modal('Neuer Benutzer', '<div class="form">' + field('uname', 'Name *', '') + field('uemail', 'E-Mail *', '', 'email') + field('upass', 'Passwort * (mind. 8 Zeichen)', '', 'password') + selectField('urole', 'Rolle', [['MEMBER', 'Mitarbeiter'], ['ADMIN', 'Admin']], 'MEMBER') + '</div>', 'Anlegen',
      function (f) {
        return api('POST', '/api/users', { name: v(f, 'uname'), email: v(f, 'uemail'), password: f.querySelector('#upass').value, role: v(f, 'urole') }).then(function () { done('Benutzer angelegt'); });
      });
  }

  function invoiceDialog(presetClient) {
    Promise.all([listAll('/api/clients', { sort: 'name' }), listAll('/api/projects')]).then(function (r) {
      var clients = r[0], projects = r[1];
      if (!clients.length) return toast('Lege zuerst einen Kunden an.');
      var rows = [{ description: '', quantity: 1, unitPrice: 0 }];
      var cid = presetClient || (ui.view === 'client' ? ui.clientId : clients[0].id);
      var projOpts = function (clientId) { return [['', '– kein Projekt –']].concat(projects.filter(function (p) { return p.client.id === clientId; }).map(function (p) { return [p.id, p.name]; })); };
      var collect = function (f) {
        return Array.prototype.map.call(f.querySelectorAll('.item-row'), function (row) { return { description: row.querySelector('.d').value.trim(), quantity: parseFloat(row.querySelector('.q').value) || 0, unitPrice: parseFloat(row.querySelector('.p').value) || 0 }; });
      };
      var sums = function (f) {
        var items = collect(f), rate = parseFloat(f.querySelector('#irate').value) || 0, disc = parseFloat(f.querySelector('#idisc').value) || 0;
        var t = previewTotals(items, rate, disc);
        f.querySelector('#sums').innerHTML = '<div><span>Netto</span><span>' + eur(t.sub) + '</span></div><div><span>MwSt. ' + rate + ' %</span><span>' + eur(t.tax) + '</span></div><div class="tot"><span>Gesamt</span><span>' + eur(t.total) + '</span></div>';
        f.querySelectorAll('.item-row').forEach(function (row, idx) { row.querySelector('.amt').textContent = eur(r2(items[idx].quantity * items[idx].unitPrice)); });
      };
      var draw = function (f) {
        f.querySelector('#items').innerHTML = rows.map(function (x, i) {
          return '<div class="item-row"><input class="d" placeholder="Leistung" aria-label="Beschreibung Position ' + (i + 1) + '" value="' + esc(x.description) + '"><input class="q" type="number" min="0.01" step="0.25" aria-label="Menge" value="' + x.quantity + '"><input class="p" type="number" step="0.01" aria-label="Einzelpreis in Euro" value="' + x.unitPrice + '"><span class="amt"></span><button type="button" class="btn sm ghost" data-row-del="' + i + '" aria-label="Position entfernen">✕</button></div>';
        }).join('');
        sums(f);
      };
      modal('Neue Rechnung', '<div class="form">' + selectField('icl', 'Kunde', clientOptions(clients), cid) + selectField('ipr', 'Projekt', projOpts(cid), '') + field('idue', 'Fällig am', new Date(Date.now() + 14 * 864e5).toISOString().slice(0, 10), 'date') + field('irate', 'MwSt. (%)', '19', 'number', { attrs: 'min="0" step="1"' }) + '</div>' +
        '<div><div class="sub" style="margin-bottom:6px;font-weight:600">Positionen</div><div class="items" id="items"></div><button type="button" class="btn sm" data-row-add style="margin-top:8px">+ Position</button></div>' +
        '<div class="form">' + field('idisc', 'Rabatt (€)', '0', 'number', { attrs: 'min="0" step="1"' }) + '</div><div class="sums" id="sums"></div>', 'Rechnung erstellen',
        function (f) {
          var items = collect(f).filter(function (x) { return x.description; });
          if (!items.length || items.some(function (x) { return x.quantity <= 0; })) return bad('Mindestens eine Position mit Beschreibung und Menge nötig.');
          return api('POST', '/api/invoices', clean({ clientId: v(f, 'icl'), projectId: v(f, 'ipr'), dueDate: v(f, 'idue') ? noon(v(f, 'idue')) : '', taxRate: num(f, 'irate'), discount: num(f, 'idisc'), items: items })).then(function (inv) {
            ui.invoiceId = inv.id; ui.view = 'invoice'; done('Rechnung ' + inv.number + ' erstellt');
          });
        }, function (f) {
          draw(f);
          f.addEventListener('input', function (e) { if (e.target.closest('.item-row')) rows = collect(f); sums(f); });
          f.querySelector('#icl').addEventListener('change', function (e) { f.querySelector('#ipr').innerHTML = projOpts(e.target.value).map(function (o) { return '<option value="' + esc(o[0]) + '">' + esc(o[1]) + '</option>'; }).join(''); });
          f.addEventListener('click', function (e) {
            var add = e.target.closest('[data-row-add]'), del = e.target.closest('[data-row-del]');
            if (add) { rows = collect(f); rows.push({ description: '', quantity: 1, unitPrice: 0 }); draw(f); var d = f.querySelectorAll('.item-row .d'); d[d.length - 1].focus(); }
            if (del) { rows = collect(f); if (rows.length > 1) rows.splice(+del.dataset.rowDel, 1); draw(f); }
          });
        });
    }).catch(function (e) { toast(e.message); });
  }

  /* ---------- Ereignisse ---------- */
  var fail = function (err) { toast(err.message); };
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-act]'); if (!el) return;
    var act = el.dataset.act, id = el.dataset.id;
    if (act === 'overlay') { if (e.target === el) closeModal(); return; }
    if (act === 'close') return closeModal();
    if (el.tagName === 'TR' && e.target.closest('button,a')) return;
    var del = function (path, msg) { return api('DELETE', path).then(function () { done(msg); }).catch(fail); };
    switch (act) {
      case 'logout': return logout();
      case 'retry': return render();
      case 'nav': return go(el.dataset.v);
      case 'open-client': return go('client', { clientId: id });
      case 'open-project': return go('projects', { projectId: id });
      case 'open-invoice': return go('invoice', { invoiceId: id });
      case 'new-client': return clientDialog();
      case 'edit-client': return api('GET', '/api/clients/' + id).then(clientDialog).catch(fail);
      case 'del-client': return confirmDialog('Der Kunde samt Projekten, Rechnungen, Verträgen und Dokumenten wird dauerhaft gelöscht.', 'Endgültig löschen', function () { return api('DELETE', '/api/clients/' + id).then(function () { go('clients'); toast('Kunde gelöscht'); }); });
      case 'new-project': return projectDialog(id);
      case 'new-task': return taskDialog();
      case 'new-time': return timeDialog();
      case 'new-note': return noteDialog(id);
      case 'new-contract': return contractDialog(id);
      case 'new-invoice': return invoiceDialog(id);
      case 'new-payment': return paymentDialog();
      case 'new-user': return userDialog();
      case 'cfilter': ui.cstatus = el.dataset.v; return render();
      case 'ifilter': ui.istatus = el.dataset.v; return render();
      case 'del-note': return del('/api/notes/' + id, 'Notiz gelöscht');
      case 'del-task': return del('/api/tasks/' + id, 'Aufgabe gelöscht');
      case 'del-time': return del('/api/time-entries/' + id, 'Eintrag gelöscht');
      case 'del-contract': return del('/api/contracts/' + id, 'Vertrag gelöscht');
      case 'del-doc': return del('/api/documents/' + id, 'Dokument gelöscht');
      case 'del-payment': return del('/api/invoices/' + ui.invoiceId + '/payments/' + id, 'Zahlung gelöscht');
      case 'del-user': return confirmDialog('Der Benutzer verliert sofort den Zugang.', 'Entfernen', function () { return api('DELETE', '/api/users/' + id).then(function () { done('Benutzer entfernt'); }); });
      case 'del-invoice': return confirmDialog('Die Rechnung wird dauerhaft gelöscht. Die Nummer wird nicht erneut vergeben, solange eine höhere existiert.', 'Endgültig löschen', function () { return api('DELETE', '/api/invoices/' + ui.invoiceId).then(function () { go('invoices'); toast('Rechnung gelöscht'); }); });
      case 'inv-status': return api('PATCH', '/api/invoices/' + ui.invoiceId, { status: el.dataset.v }).then(function () { done(el.dataset.v === 'SENT' ? 'Als versendet markiert' : 'Rechnung storniert'); }).catch(fail);
      case 'move':
        var tasks = Array.prototype.map.call(document.querySelectorAll('.task'), function (n) { return n.dataset.task; });
        if (tasks.indexOf(id) < 0) return;
        var col = el.closest('.col').dataset.col, idx = COLS.map(function (c) { return c[0]; }).indexOf(col) + (+el.dataset.d);
        if (COLS[idx]) api('PATCH', '/api/tasks/' + id, { status: COLS[idx][0] }).then(function () { render(); }).catch(fail);
        return;
      case 'pick-file': var inp = $('#fup'); if (inp) inp.click(); return;
    }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && $('#layer').firstChild) closeModal(); });

  var searchTimer = null;
  document.addEventListener('input', function (e) {
    if (e.target.id !== 'q') return;
    ui.q = e.target.value;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function () {
      fetchClients().then(function (res) { var t = $('#tbl'); if (t) t.innerHTML = clientRows(res.items, res.meta.total); }).catch(fail);
    }, 250);
  });
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t.id === 'psel') { ui.projectId = t.value; render(); }
    else if (t.id === 'pstatus') api('PATCH', '/api/projects/' + ui.projectId, { status: t.value }).then(function () { done('Status geändert'); }).catch(fail);
    else if (t.dataset && t.dataset.user) api('PATCH', '/api/users/' + t.dataset.user, { role: t.value }).then(function () { done('Rolle geändert'); }).catch(function (err) { toast(err.message); render(); });
    else if (t.id === 'fup' && t.files[0]) {
      var file = t.files[0];
      if (file.size > 25 * 1024 * 1024) { toast('Datei zu groß (maximal 25 MB).'); t.value = ''; return; }
      var fd = new FormData(); fd.append('file', file); fd.append('clientId', ui.clientId);
      toast('Lade hoch …');
      api('POST', '/api/documents', fd, true).then(function () { done('Datei hochgeladen'); }).catch(fail);
    }
  });

  /* Drag & Drop im Kanban-Board */
  var dragId = null;
  document.addEventListener('dragstart', function (e) { var t = e.target.closest && e.target.closest('.task'); if (!t) return; dragId = t.dataset.task; t.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', dragId); } catch (x) { /* ignorieren */ } });
  document.addEventListener('dragend', function () { dragId = null; document.querySelectorAll('.dragging,.over').forEach(function (n) { n.classList.remove('dragging', 'over'); }); });
  document.addEventListener('dragover', function (e) { var c = e.target.closest && e.target.closest('.col'); if (c && dragId) { e.preventDefault(); document.querySelectorAll('.col.over').forEach(function (n) { if (n !== c) n.classList.remove('over'); }); c.classList.add('over'); } });
  document.addEventListener('drop', function (e) {
    var c = e.target.closest && e.target.closest('.col'); if (!c || !dragId) return; e.preventDefault();
    var id = dragId; dragId = null;
    api('PATCH', '/api/tasks/' + id, { status: c.dataset.col }).then(function () { render(); }).catch(fail);
  });

  /* ---------- Start ---------- */
  if (token) {
    api('GET', '/api/auth/me').then(function (u) { me = u; render(); }).catch(function () { token = null; lsDel(TOKEN_KEY); renderLogin(); });
  } else {
    renderLogin();
  }
})();
