(function () {
  'use strict';

  /* ---------- Hilfsfunktionen ---------- */
  var $ = function (s, el) { return (el || document).querySelector(s); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var eur = function (n) { return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(n || 0); };
  var fdate = function (iso) { return iso ? new Date(iso).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '–'; };
  var r2 = function (n) { return Math.round(n * 100) / 100; };
  var hrs = function (min) { return (min / 60).toLocaleString('de-DE', { maximumFractionDigits: 2 }) + ' Std.'; };
  var fdateUTC = function (iso) { return iso ? new Date(iso).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'UTC' }) : '–'; };
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
  var QUOTE_STATUS = { DRAFT: ['Entwurf', 'neutral'], SENT: ['Versendet', 'info'], ACCEPTED: ['Angenommen', 'good'], DECLINED: ['Abgelehnt', 'bad'], EXPIRED: ['Abgelaufen', 'warn'] };
  var INTERVALS = { MONTHLY: 'monatlich', QUARTERLY: 'vierteljährlich', HALF_YEARLY: 'halbjährlich', YEARLY: 'jährlich' };
  var LEVELS = { 1: 'Zahlungserinnerung', 2: '1. Mahnung', 3: 'Letzte Mahnung' };
  var TYPES = { ONE_TIME: ['Einmalig', 'info'], RENTAL: ['Miete', 'good'], HOURLY: ['Nach Stunden', 'warn'] };
  var PERIOD = { MONTHLY: 'Monat', QUARTERLY: 'Quartal', HALF_YEARLY: 'Halbjahr', YEARLY: 'Jahr' };
  var ORDER_STATUS = { PENDING: ['Neu', 'warn'], ACCEPTED: ['Angenommen', 'good'], REJECTED: ['Abgelehnt', 'bad'], CANCELLED: ['Storniert', 'neutral'] };
  var priceText = function (p) {
    var base = eur(p.price);
    if (p.type === 'RENTAL') return base + ' / ' + PERIOD[p.intervalUnit];
    if (p.type === 'HOURLY') return base + ' / ' + (p.unit || 'Std.');
    return base + (p.unit ? ' / ' + p.unit : '');
  };
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
  var ui = { view: 'dashboard', clientId: null, projectId: null, invoiceId: null, quoteId: null, q: '', cstatus: '', istatus: '', qstatus: '', pcat: '', ostatus: '', orderId: null, pending: 0 };

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
    var n = [['dashboard', 'Dashboard'], ['clients', 'Kunden'], ['products', 'Produkte'], ['orders', 'Bestellungen'], ['projects', 'Projekte'], ['times', 'Zeiten'], ['quotes', 'Angebote'], ['invoices', 'Rechnungen'], ['reminders', 'Mahnwesen'], ['recurring', 'Abos']];
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
    drawNav();
  }
  function drawNav() {
    $('#nav').innerHTML = navItems().map(function (n) {
      var cur = ui.view === n[0] || (n[0] === 'clients' && ui.view === 'client') || (n[0] === 'invoices' && ui.view === 'invoice') || (n[0] === 'quotes' && ui.view === 'quote') || (n[0] === 'orders' && ui.view === 'order');
      var badge = n[0] === 'orders' && ui.pending > 0 ? '<span class="count" aria-label="' + ui.pending + ' neue">' + ui.pending + '</span>' : '';
      return '<button data-act="nav" data-v="' + n[0] + '"' + (cur ? ' aria-current="page"' : '') + '>' + n[1] + badge + '</button>';
    }).join('');
  }
  /** Zählt offene Bestellungen für das Menü, ohne die Ansicht zu blockieren. */
  function refreshBadge() {
    api('GET', '/api/settings').then(function (st) {
      if (st.pendingOrders !== ui.pending && $('#nav')) { ui.pending = st.pendingOrders; drawNav(); }
    }).catch(function () { /* Badge ist optional */ });
  }
  var head = function (title, sub, actions) {
    return '<div class="head"><div><h1>' + title + '</h1>' + (sub ? '<p>' + sub + '</p>' : '') + '</div><div class="row">' + (actions || '') + '</div></div>';
  };
  var kpi = function (l, v, s, cls) { return '<div class="card kpi ' + (cls || '') + '"><span class="l">' + l + '</span><span class="v">' + v + '</span><span class="s">' + s + '</span></div>'; };

  /* ---------- Ansichten ---------- */
  function vDashboard() {
    return Promise.all([api('GET', '/api/dashboard/summary'), listAll('/api/invoices'), api('GET', '/api/settings')]).then(function (r) {
      var s = r[0], invoices = r[1], pendingOrders = r[2].pendingOrders;
      var openCount = invoices.filter(function (i) { return i.totals.balance > 0 && i.status !== 'CANCELLED'; }).length;
      return head('Guten Tag, ' + esc(me.name.split(' ')[0]), 'Das ist heute los in deinem Studio.', '<button class="btn primary" data-act="new-invoice">+ Rechnung</button><button class="btn" data-act="new-client">+ Kunde</button>') +
        (pendingOrders > 0 ? '<div class="demo"><span><b>' + pendingOrders + (pendingOrders === 1 ? ' neue Bestellung wartet' : ' neue Bestellungen warten') + '</b> auf deine Bestätigung.</span><button class="btn sm primary" data-act="nav" data-v="orders">Bestellungen ansehen</button></div>' : '') +
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
    return Promise.all([api('GET', '/api/clients/' + ui.clientId), listAll('/api/invoices', { clientId: ui.clientId }), listAll('/api/quotes', { clientId: ui.clientId }), api('GET', '/api/recurring' + qs({ clientId: ui.clientId })), api('GET', '/api/clients/' + ui.clientId + '/portal')]).then(function (r) {
      var c = r[0], invoices = r[1], quotes = r[2], recs = r[3].items, portal = r[4];
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
        '<div class="card"><h2>Kundenportal ' + (portal.active || portal.accounts.some(function (a) { return a.active; }) ? '<span class="pill good">Aktiv</span>' : '<span class="pill neutral">Kein Zugang</span>') + '</h2>' +
        '<p class="sub" style="margin:0 0 12px">Im Portal sieht ' + esc(c.name) + ' die eigenen Rechnungen und Angebote, lädt sie als PDF herunter und kann Angebote annehmen. Der Zugang läuft über einen persönlichen Link oder über ein Konto, das sich der Kunde selbst im Portal anlegt.</p>' +
        (portal.accounts.length ? '<div class="list" style="margin-bottom:12px">' + portal.accounts.map(function (a) {
          return '<div><div class="grow"><b>' + esc(a.email) + '</b><div class="sub">Konto' + (a.verifiedAt ? ' · bestätigt' : ' · unbestätigt') + ' · zuletzt angemeldet ' + (a.lastLoginAt ? fdate(a.lastLoginAt) : 'noch nie') + '</div></div>' + (a.active ? '<span class="pill good">Aktiv</span>' : '<span class="pill neutral">Gesperrt</span>') +
            '<button class="btn sm" data-act="acct-toggle" data-id="' + esc(a.id) + '" data-active="' + (a.active ? '1' : '0') + '">' + (a.active ? 'Sperren' : 'Entsperren') + '</button>' + (portal.mailConfigured ? '<button class="btn sm ghost" data-act="acct-reset" data-id="' + esc(a.id) + '">Passwort-Link senden</button>' : '') + '<button class="btn sm ghost danger" data-act="acct-del" data-id="' + esc(a.id) + '" aria-label="Konto löschen">Löschen</button></div>';
        }).join('') + '</div>' : '') +
        (portal.active ? '<dl class="kv" style="margin-bottom:12px"><dt>Erstellt</dt><dd>' + fdate(portal.createdAt) + '</dd><dt>Gültig bis</dt><dd>' + (portal.expiresAt ? fdate(portal.expiresAt) : 'unbegrenzt') + '</dd><dt>Zuletzt genutzt</dt><dd>' + (portal.lastUsedAt ? fdate(portal.lastUsedAt) : 'noch nie') + '</dd></dl>' : '') +
        '<div class="row"><button class="btn primary" data-act="portal-issue" data-id="' + esc(c.id) + '">' + (portal.active ? 'Neuen Link erstellen' : 'Zugang erstellen') + '</button>' + (portal.active ? '<button class="btn danger" data-act="portal-revoke" data-id="' + esc(c.id) + '">Zugang sperren</button>' : '') + '</div></div>' +
        '<div class="grid two-eq"><div class="card"><h2>Angebote <button class="btn sm" data-act="new-quote" data-id="' + esc(c.id) + '">+ Angebot</button></h2><div class="list">' + (quotes.length ? quotes.map(function (q) {
          return '<div><div class="grow"><button class="link mono" data-act="open-quote" data-id="' + esc(q.id) + '">' + esc(q.number) + '</button><div class="sub">' + fdate(q.issueDate) + '</div></div><span class="num">' + eur(q.totals.total) + '</span>' + pill(QUOTE_STATUS, q.status) + '</div>';
        }).join('') : '<div class="empty">Noch keine Angebote.</div>') + '</div></div>' +
        '<div class="card"><h2>Abos <button class="btn sm" data-act="new-recurring" data-id="' + esc(c.id) + '">+ Abo</button></h2><div class="list">' + (recs.length ? recs.map(function (x) {
          return '<div><div class="grow"><b>' + esc(x.title) + '</b><div class="sub">' + INTERVALS[x.intervalUnit] + ' · ' + eur(x.totals.total) + (x.active ? ' · nächste Abrechnung ' + fdateUTC(x.nextRunDate) : '') + '</div></div>' + (x.active ? '<span class="pill good">Aktiv</span>' : '<span class="pill neutral">Pausiert</span>') + '</div>';
        }).join('') : '<div class="empty">Noch keine Abos (Domain, Hosting, Wartung …).</div>') + '</div></div></div>' +
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

  var emailLog = function (list) {
    return list.length ? list.map(function (m) {
      return '<div><div class="grow"><b>' + esc(m.subject) + '</b><div class="sub">an ' + esc(m.toEmail) + ' · ' + fdate(m.createdAt) + (m.error ? ' · ' + esc(m.error) : '') + '</div></div>' +
        (m.status === 'SENT' ? '<span class="pill good">Gesendet</span>' : '<span class="pill bad">Fehlgeschlagen</span>') + '</div>';
    }).join('') : '<div class="empty">Noch nichts per E-Mail versendet.</div>';
  };

  function vInvoice() {
    return api('GET', '/api/invoices/' + ui.invoiceId).then(function (i) {
      var t = i.totals, st = shownStatus(i), remindable = (i.status === 'SENT' || i.status === 'OVERDUE') && t.balance > 0;
      return '<button class="btn ghost sm back" data-act="nav" data-v="invoices" style="align-self:flex-start">← Alle Rechnungen</button>' +
        head('<span class="mono">' + esc(i.number) + '</span>', pill(INVOICE_STATUS, st) + (i.recurringId ? ' <span class="tag">aus Abo</span>' : '') + ' &nbsp;<button class="link" data-act="open-client" data-id="' + esc(i.client.id) + '">' + esc(i.client.company || i.client.name) + '</button>' + (i.project ? ' · ' + esc(i.project.name) : ''),
          '<button class="btn" data-act="pdf" data-url="/api/invoices/' + esc(i.id) + '/pdf">PDF herunterladen</button>' +
          (i.status !== 'CANCELLED' ? '<button class="btn' + (i.status === 'DRAFT' ? ' primary' : '') + '" data-act="mail-invoice">Per E-Mail senden</button>' : '') +
          (i.status === 'DRAFT' ? '<button class="btn" data-act="inv-status" data-v="SENT">Als versendet markieren</button>' : '') +
          (remindable ? '<button class="btn" data-act="remind" data-id="' + esc(i.id) + '">Mahnung erstellen</button>' : '') +
          (t.balance > 0 && i.status !== 'CANCELLED' ? '<button class="btn primary" data-act="new-payment">Zahlung erfassen</button>' : '') +
          (i.status !== 'CANCELLED' && i.status !== 'PAID' ? '<button class="btn danger" data-act="inv-status" data-v="CANCELLED">Stornieren</button>' : '') +
          (i.status === 'DRAFT' || i.status === 'CANCELLED' ? '<button class="btn danger" data-act="del-invoice">Löschen</button>' : '')) +
        '<div class="grid two"><div class="card"><h2>Positionen</h2><div class="tablewrap"><table><thead><tr><th>Beschreibung</th><th class="r">Menge</th><th class="r">Einzelpreis</th><th class="r">Summe</th></tr></thead><tbody>' + i.items.map(function (x) {
          return '<tr><td>' + esc(x.description) + '</td><td class="r num">' + x.quantity.toLocaleString('de-DE') + '</td><td class="r num">' + eur(x.unitPrice) + '</td><td class="r num">' + eur(r2(x.quantity * x.unitPrice)) + '</td></tr>';
        }).join('') + '</tbody></table></div><div class="sums" style="margin-top:12px"><div><span>Netto</span><span>' + eur(t.subtotal) + '</span></div>' + (i.discount ? '<div><span>Rabatt</span><span>− ' + eur(i.discount) + '</span></div>' : '') + '<div><span>MwSt. ' + i.taxRate + ' %</span><span>' + eur(t.tax) + '</span></div><div class="tot"><span>Gesamt</span><span>' + eur(t.total) + '</span></div></div>' + (i.notes ? '<p class="sub" style="margin-top:12px">' + esc(i.notes) + '</p>' : '') + '</div>' +
        '<div class="card"><h2>Zahlungen</h2><dl class="kv" style="margin-bottom:12px"><dt>Rechnungsdatum</dt><dd>' + fdate(i.issueDate) + '</dd><dt>Fällig am</dt><dd>' + fdate(i.dueDate) + '</dd><dt>Bezahlt</dt><dd class="num">' + eur(t.paid) + '</dd><dt>Offen</dt><dd class="num"><b>' + eur(t.balance) + '</b></dd></dl><div class="list">' + (i.payments.length ? i.payments.map(function (pm) {
          return '<div><div class="grow"><b class="num">' + eur(pm.amount) + '</b><div class="sub">' + fdate(pm.paidAt) + (pm.method ? ' · ' + esc(pm.method) : '') + '</div></div><button class="btn sm ghost" data-act="del-payment" data-id="' + esc(pm.id) + '" aria-label="Zahlung löschen">✕</button></div>';
        }).join('') : '<div class="empty">Noch keine Zahlung eingegangen.</div>') + '</div></div></div>' +
        '<div class="grid two-eq"><div class="card"><h2>Mahnungen</h2><div class="list">' + (i.reminders.length ? i.reminders.map(function (rm) {
          return '<div><div class="grow"><b>' + LEVELS[rm.level] + '</b><div class="sub">' + fdate(rm.createdAt) + ' · neue Frist ' + fdate(rm.dueDate) + (rm.fee > 0 ? ' · Gebühr ' + eur(rm.fee) : '') + '</div></div>' +
            (rm.emailedAt ? '<span class="pill good">Gesendet</span>' : '<span class="pill neutral">Nicht gesendet</span>') +
            '<button class="btn sm" data-act="pdf" data-url="/api/reminders/' + esc(rm.id) + '/pdf">PDF</button><button class="btn sm ghost" data-act="del-reminder" data-id="' + esc(rm.id) + '" aria-label="Mahnung löschen">✕</button></div>';
        }).join('') : '<div class="empty">Keine Mahnungen.</div>') + '</div></div>' +
        '<div class="card"><h2>E-Mail-Verlauf</h2><div class="list">' + emailLog(i.emails) + '</div></div></div>';
    });
  }

  function vQuotes() {
    return listAll('/api/quotes').then(function (all) {
      var rows = all.filter(function (q) { return !ui.qstatus || q.status === ui.qstatus; });
      var chips = [['', 'Alle']].concat(opts(QUOTE_STATUS));
      return head('Angebote', 'Angenommene Angebote wandelst du mit einem Klick in eine Rechnung um.', '<button class="btn primary" data-act="new-quote">+ Neues Angebot</button>') +
        '<div class="chips">' + chips.map(function (c) { return '<button class="chip" data-act="qfilter" data-v="' + c[0] + '" aria-pressed="' + (ui.qstatus === c[0]) + '">' + c[1] + '</button>'; }).join('') + '</div>' +
        '<div class="card" style="padding:6px"><div class="tablewrap"><table><thead><tr><th>Nummer</th><th>Kunde</th><th>Datum</th><th>Gültig bis</th><th>Status</th><th class="r">Betrag</th></tr></thead><tbody>' + (rows.length ? rows.map(function (q) {
          return '<tr class="click" data-act="open-quote" data-id="' + esc(q.id) + '"><td><button class="link mono" data-act="open-quote" data-id="' + esc(q.id) + '">' + esc(q.number) + '</button></td><td>' + esc(q.client.company || q.client.name) + '</td><td class="num">' + fdate(q.issueDate) + '</td><td class="num">' + fdate(q.validUntil) + '</td><td>' + pill(QUOTE_STATUS, q.status) + '</td><td class="r num">' + eur(q.totals.total) + '</td></tr>';
        }).join('') : '<tr><td colspan="6" class="empty">' + (ui.qstatus ? 'Keine Angebote mit diesem Status.' : 'Noch keine Angebote. Erstelle dein erstes Angebot.') + '</td></tr>') + '</tbody></table></div></div>';
    });
  }

  function vQuote() {
    return api('GET', '/api/quotes/' + ui.quoteId).then(function (q) {
      var t = q.totals, real = q.status === 'EXPIRED' ? 'SENT' : q.status;
      return '<button class="btn ghost sm back" data-act="nav" data-v="quotes" style="align-self:flex-start">← Alle Angebote</button>' +
        head('<span class="mono">' + esc(q.number) + '</span>', pill(QUOTE_STATUS, q.status) + ' &nbsp;<button class="link" data-act="open-client" data-id="' + esc(q.client.id) + '">' + esc(q.client.company || q.client.name) + '</button>' + (q.project ? ' · ' + esc(q.project.name) : ''),
          '<button class="btn" data-act="pdf" data-url="/api/quotes/' + esc(q.id) + '/pdf">PDF herunterladen</button>' +
          '<button class="btn' + (q.status === 'DRAFT' ? ' primary' : '') + '" data-act="mail-quote">Per E-Mail senden</button>' +
          (!q.invoice && q.status !== 'DECLINED' ? '<button class="btn primary" data-act="convert-quote">In Rechnung umwandeln</button>' : '') +
          '<button class="btn danger" data-act="del-quote">Löschen</button>') +
        '<div class="grid two"><div class="card"><h2>Positionen</h2><div class="tablewrap"><table><thead><tr><th>Beschreibung</th><th class="r">Menge</th><th class="r">Einzelpreis</th><th class="r">Summe</th></tr></thead><tbody>' + q.items.map(function (x) {
          return '<tr><td>' + esc(x.description) + '</td><td class="r num">' + x.quantity.toLocaleString('de-DE') + '</td><td class="r num">' + eur(x.unitPrice) + '</td><td class="r num">' + eur(r2(x.quantity * x.unitPrice)) + '</td></tr>';
        }).join('') + '</tbody></table></div><div class="sums" style="margin-top:12px"><div><span>Netto</span><span>' + eur(t.subtotal) + '</span></div>' + (q.discount ? '<div><span>Rabatt</span><span>− ' + eur(q.discount) + '</span></div>' : '') + '<div><span>MwSt. ' + q.taxRate + ' %</span><span>' + eur(t.tax) + '</span></div><div class="tot"><span>Gesamt</span><span>' + eur(t.total) + '</span></div></div>' + (q.notes ? '<p class="sub" style="margin-top:12px">' + esc(q.notes) + '</p>' : '') + '</div>' +
        '<div class="card"><h2>Details</h2><dl class="kv"><dt>Angebotsdatum</dt><dd>' + fdate(q.issueDate) + '</dd><dt>Gültig bis</dt><dd>' + fdate(q.validUntil) + '</dd><dt>Status</dt><dd><select id="qstat" aria-label="Angebotsstatus">' +
          ['DRAFT', 'SENT', 'ACCEPTED', 'DECLINED'].map(function (k) { return '<option value="' + k + '"' + (k === real ? ' selected' : '') + '>' + QUOTE_STATUS[k][0] + '</option>'; }).join('') + '</select></dd>' +
          '<dt>Rechnung</dt><dd>' + (q.invoice ? '<button class="link mono" data-act="open-invoice" data-id="' + esc(q.invoice.id) + '">' + esc(q.invoice.number) + '</button>' : '<span class="sub">noch nicht erstellt</span>') + '</dd></dl></div></div>' +
        '<div class="card"><h2>E-Mail-Verlauf</h2><div class="list">' + emailLog(q.emails) + '</div></div>';
    });
  }

  function vReminders() {
    return api('GET', '/api/reminders/overview').then(function (res) {
      var rows = res.items;
      return head('Mahnwesen', 'Überfällige Rechnungen und der nächste sinnvolle Schritt.', '') +
        (res.mailConfigured ? '' : '<div class="demo"><span>E-Mail-Versand ist noch nicht eingerichtet. Mahnungen kannst du trotzdem erstellen und als PDF herunterladen.</span></div>') +
        '<div class="grid kpis">' + kpi('Überfällig', String(res.summary.count), res.summary.count === 1 ? 'Rechnung' : 'Rechnungen', res.summary.count ? 'bad' : '') + kpi('Offener Betrag', eur(res.summary.balance), 'davon überfällig') + '</div>' +
        '<div class="card" style="padding:6px"><div class="tablewrap"><table><thead><tr><th>Rechnung</th><th>Kunde</th><th class="r">Überfällig</th><th class="r">Offen</th><th>Mahnstand</th><th></th></tr></thead><tbody>' + (rows.length ? rows.map(function (x) {
          return '<tr><td><button class="link mono" data-act="open-invoice" data-id="' + esc(x.id) + '">' + esc(x.number) + '</button><div class="sub">fällig ' + fdate(x.dueDate) + '</div></td><td>' + esc(x.client.company || x.client.name) + '</td><td class="r num"><span class="pill bad">' + x.daysOverdue + ' Tage</span></td><td class="r num">' + eur(x.totals.balance) + '</td><td>' +
            (x.lastLevel ? '<span class="pill warn">' + LEVELS[x.lastLevel] + '</span> <span class="sub">' + fdate(x.lastReminderAt) + '</span>' : '<span class="pill neutral">Noch keine</span>') + '</td><td class="r"><button class="btn sm primary" data-act="remind" data-id="' + esc(x.id) + '" data-level="' + x.nextLevel + '">' + LEVELS[x.nextLevel] + '</button></td></tr>';
        }).join('') : '<tr><td colspan="6" class="empty">Keine überfälligen Rechnungen. Alles im grünen Bereich.</td></tr>') + '</tbody></table></div></div>' +
        '<p class="sub">Die Texte, Gebühren und Fristen sind Vorschläge und vor dem Versand änderbar. Bitte lass die Formulierungen einmal von deinem Steuerberater oder Anwalt prüfen.</p>';
    });
  }

  function vRecurring() {
    return api('GET', '/api/recurring').then(function (res) {
      return head('Abos', 'Wiederkehrende Rechnungen für Domains, Hosting, Wartung und Homepage-Miete.', '<button class="btn" data-act="run-due">Fällige jetzt abrechnen</button><button class="btn primary" data-act="new-recurring">+ Neues Abo</button>') +
        '<div class="grid kpis">' + kpi('Aktive Abos', String(res.summary.active), 'laufen automatisch weiter') + kpi('Pro Monat', eur(res.summary.monthlyRevenue), 'netto, alle aktiven Abos') + kpi('Pro Jahr', eur(res.summary.yearlyRevenue), 'netto, hochgerechnet') + '</div>' +
        '<div class="card" style="padding:6px"><div class="tablewrap"><table><thead><tr><th>Abo</th><th>Rhythmus</th><th>Nächste Abrechnung</th><th class="r">Betrag</th><th>Status</th><th></th></tr></thead><tbody>' + (res.items.length ? res.items.map(function (x) {
          return '<tr><td><b>' + esc(x.title) + '</b>' + (x.autoSend ? ' <span class="tag">sendet automatisch</span>' : '') + '<div class="sub">' + esc(x.client.company || x.client.name) + ' · ' + x.invoiceCount + ' Rechnungen</div></td><td>' + INTERVALS[x.intervalUnit] + '</td><td class="num">' + (x.active ? fdateUTC(x.nextRunDate) : '–') + (x.endDate ? '<div class="sub">bis ' + fdateUTC(x.endDate) + '</div>' : '') + '</td><td class="r num">' + eur(x.totals.total) + '<div class="sub">netto ' + eur(x.totals.subtotal - x.discount) + '</div></td><td>' + (x.active ? '<span class="pill good">Aktiv</span>' : '<span class="pill neutral">Pausiert</span>') + '</td><td class="r"><div class="row" style="justify-content:flex-end">' +
            (x.active ? '<button class="btn sm" data-act="rec-run" data-id="' + esc(x.id) + '">Jetzt abrechnen</button>' : '') + '<button class="btn sm" data-act="rec-edit" data-id="' + esc(x.id) + '">Bearbeiten</button><button class="btn sm ghost" data-act="rec-toggle" data-id="' + esc(x.id) + '" data-active="' + (x.active ? '1' : '0') + '">' + (x.active ? 'Pausieren' : 'Fortsetzen') + '</button><button class="btn sm ghost danger" data-act="rec-del" data-id="' + esc(x.id) + '">Löschen</button></div></td></tr>';
        }).join('') : '<tr><td colspan="6" class="empty">Noch keine Abos. Lege z. B. „Domain beispiel.de“ jährlich oder „Hosting“ monatlich an.</td></tr>') + '</tbody></table></div></div>' +
        '<div class="card"><h2>So funktioniert es</h2><div class="sub" style="line-height:1.7">Fällige Abos erzeugt der tägliche Cron-Lauf (<span class="mono">php bin/cron.php</span>) oder du klickst auf „Fällige jetzt abrechnen“. Neue Rechnungen sind Entwürfe, außer beim Abo ist „sendet automatisch“ aktiv.<br>In den Positionstexten ersetzt das System <span class="mono">{monat}</span>, <span class="mono">{jahr}</span>, <span class="mono">{zeitraum}</span>, <span class="mono">{von}</span> und <span class="mono">{bis}</span> durch den Abrechnungszeitraum, z. B. „Hosting Paket M, {zeitraum}“.</div></div>';
    });
  }

  function vProducts() {
    return Promise.all([api('GET', '/api/categories'), api('GET', '/api/products')]).then(function (r) {
      var cats = r[0], all = r[1], sel = ui.pcat;
      if (sel && sel !== 'none' && !cats.some(function (c) { return c.id === sel; })) sel = ui.pcat = '';
      var rows = all.filter(function (p) { return !sel || (sel === 'none' ? !p.categoryId : p.categoryId === sel); });
      var uncat = all.filter(function (p) { return !p.categoryId; }).length;
      var chips = [['', 'Alle (' + all.length + ')']].concat(cats.map(function (c) { return [c.id, c.name + ' (' + c.productCount + ')' + (c.active ? '' : ' · verborgen')]; }));
      if (uncat) chips.push(['none', 'Ohne Kategorie (' + uncat + ')']);
      var selCat = cats.filter(function (c) { return c.id === sel; })[0];
      return head('Produkte', 'Katalog für Angebote, Rechnungen und Bestellungen im Kundenportal.', '<button class="btn" data-act="new-category">+ Kategorie</button><button class="btn primary" data-act="new-product">+ Produkt</button>') +
        '<div class="chips">' + chips.map(function (c) { return '<button class="chip" data-act="pfilter" data-v="' + esc(c[0]) + '" aria-pressed="' + (sel === c[0]) + '">' + esc(c[1]) + '</button>'; }).join('') + '</div>' +
        (selCat ? '<div class="card row" style="justify-content:space-between"><div><b>' + esc(selCat.name) + '</b>' + (selCat.description ? '<div class="sub">' + esc(selCat.description) + '</div>' : '') + '</div><div class="row"><button class="btn sm" data-act="new-product" data-cat="' + esc(selCat.id) + '">+ Produkt hier</button><button class="btn sm" data-act="edit-category" data-id="' + esc(selCat.id) + '">Kategorie bearbeiten</button><button class="btn sm ghost danger" data-act="del-category" data-id="' + esc(selCat.id) + '">Löschen</button></div></div>' : '') +
        '<div class="card" style="padding:6px"><div class="tablewrap"><table><thead><tr><th>Produkt</th><th>Art</th><th class="r">Preis (netto)</th><th>Kategorie</th><th>Status</th><th></th></tr></thead><tbody>' + (rows.length ? rows.map(function (p) {
          return '<tr><td><b>' + esc(p.name) + '</b>' + (p.description ? '<div class="sub">' + esc(p.description.length > 90 ? p.description.slice(0, 90) + '…' : p.description) + '</div>' : '') + '</td><td>' + pill(TYPES, p.type) + (p.type === 'RENTAL' ? '<div class="sub">' + INTERVALS[p.intervalUnit] + '</div>' : '') + '</td><td class="r num">' + priceText(p) + (p.setupFee > 0 ? '<div class="sub">+ ' + eur(p.setupFee) + ' Einrichtung</div>' : '') + '</td><td>' + esc(p.category ? p.category.name : '–') + '</td><td>' + (p.active ? '<span class="pill good">Aktiv</span>' : '<span class="pill neutral">Inaktiv</span>') + '</td><td class="r"><div class="row" style="justify-content:flex-end"><button class="btn sm" data-act="prod-edit" data-id="' + esc(p.id) + '">Bearbeiten</button><button class="btn sm ghost" data-act="prod-toggle" data-id="' + esc(p.id) + '" data-active="' + (p.active ? '1' : '0') + '">' + (p.active ? 'Deaktivieren' : 'Aktivieren') + '</button><button class="btn sm ghost" data-act="prod-dup" data-id="' + esc(p.id) + '">Kopieren</button><button class="btn sm ghost danger" data-act="prod-del" data-id="' + esc(p.id) + '">Löschen</button></div></td></tr>';
        }).join('') : '<tr><td colspan="6" class="empty">' + (all.length ? 'In dieser Kategorie gibt es noch keine Produkte.' : 'Noch keine Produkte. Lege eine Kategorie und dein erstes Produkt an, z. B. „Webseitenerstellung einmalig“, „Webhosting“ oder „Projektarbeit nach Stunden“.') + '</td></tr>') + '</tbody></table></div></div>' +
        (all.length === 0 ? '<div class="card stack"><b>Schnellstart</b><p class="sub" style="margin:0">Lege einen Beispielkatalog an: Einmalleistungen (Webseite, Skripte, Druckaufträge), Mietprodukte (Webhosting, Domain, Miethomepage, Wartung, SEO) und Projektarbeit nach Stunden. Preise und Texte sind Vorschläge, alles ist zunächst inaktiv, bis du es prüfst und aktivierst.</p><div><button class="btn primary" data-act="catalog-examples">Beispielkatalog anlegen</button></div></div>' : '') +
        '<p class="sub">Aktive Produkte können Kunden im Kundenportal bestellen. Inaktive Produkte und Produkte in verborgenen Kategorien sind dort nicht sichtbar, du kannst sie aber weiter in Angebote und Rechnungen einfügen.</p>';
    });
  }

  function vOrders() {
    return api('GET', '/api/orders' + qs({ pageSize: 100, status: ui.ostatus })).then(function (res) {
      var chips = [['', 'Alle']].concat(opts(ORDER_STATUS));
      return head('Bestellungen', 'Bestellungen aus dem Kundenportal und von dir erfasste Bestellungen.', '<button class="btn primary" data-act="new-order">+ Bestellung erfassen</button>') +
        '<div class="chips">' + chips.map(function (c) { return '<button class="chip" data-act="ofilter" data-v="' + c[0] + '" aria-pressed="' + (ui.ostatus === c[0]) + '">' + c[1] + '</button>'; }).join('') + '</div>' +
        '<div class="card" style="padding:6px"><div class="tablewrap"><table><thead><tr><th>Bestellung</th><th>Kunde</th><th>Produkt</th><th class="r">Betrag (netto)</th><th>Status</th></tr></thead><tbody>' + (res.items.length ? res.items.map(function (o) {
          return '<tr class="click" data-act="open-order" data-id="' + esc(o.id) + '"><td><button class="link mono" data-act="open-order" data-id="' + esc(o.id) + '">' + esc(o.number) + '</button><div class="sub">' + fdate(o.createdAt) + (o.source === 'ADMIN' ? ' · von dir erfasst' : '') + '</div></td><td>' + esc(o.client.company || o.client.name) + '</td><td>' + qtyText(o) + ' ' + esc(o.productName) + '<div class="sub">' + pill(TYPES, o.productType) + '</div></td><td class="r num">' + eur(o.totals.net) + (o.productType === 'RENTAL' ? '<div class="sub">dann ' + eur(o.totals.recurringNet) + ' / ' + PERIOD[o.intervalUnit] + '</div>' : o.productType === 'HOURLY' ? '<div class="sub">geschätzt</div>' : '') + '</td><td>' + pill(ORDER_STATUS, o.status) + '</td></tr>';
        }).join('') : '<tr><td colspan="5" class="empty">' + (ui.ostatus ? 'Keine Bestellungen mit diesem Status.' : 'Noch keine Bestellungen. Sobald ein Kunde im Portal bestellt, erscheint sie hier.') + '</td></tr>') + '</tbody></table></div></div>';
    });
  }
  var qtyText = function (o) { return o.quantity.toLocaleString('de-DE') + (o.unit ? ' ' + esc(o.unit) : '×'); };

  function orderEffect(o) {
    if (o.productType === 'ONE_TIME') return 'Beim Annehmen wird eine <b>Rechnung (Entwurf)</b> über ' + eur(o.totals.net) + ' netto erstellt.';
    if (o.productType === 'RENTAL') return 'Beim Annehmen wird ein <b>Abo</b> (' + INTERVALS[o.intervalUnit] + ', ' + eur(o.totals.recurringNet) + ' netto) angelegt' + (o.setupFee > 0 ? ' und eine <b>Rechnung für die Einrichtung</b> (' + eur(o.setupFee) + ' netto) erstellt' : '') + '. Auf Wunsch entsteht gleich die erste Abo-Rechnung.';
    return 'Beim Annehmen wird ein <b>Projekt</b> mit Stundensatz ' + eur(o.unitPrice) + ' und Budget ' + eur(o.totals.net) + ' (' + o.quantity.toLocaleString('de-DE') + ' Std. geschätzt) angelegt. Abgerechnet wird später nach den gebuchten Zeiten.';
  }

  function vOrder() {
    return api('GET', '/api/orders/' + ui.orderId).then(function (o) {
      var t = o.totals, pending = o.status === 'PENDING';
      var made = [];
      if (o.invoice) made.push('<button class="link mono" data-act="open-invoice" data-id="' + esc(o.invoice.id) + '">Rechnung ' + esc(o.invoice.number) + '</button>');
      if (o.setupInvoice) made.push('<button class="link mono" data-act="open-invoice" data-id="' + esc(o.setupInvoice.id) + '">Einrichtungsrechnung ' + esc(o.setupInvoice.number) + '</button>');
      if (o.recurring) made.push('<button class="link" data-act="nav" data-v="recurring">Abo „' + esc(o.recurring.title) + '“</button>');
      if (o.project) made.push('<button class="link" data-act="open-project" data-id="' + esc(o.project.id) + '">Projekt „' + esc(o.project.name) + '“</button>');
      return '<button class="btn ghost sm back" data-act="nav" data-v="orders" style="align-self:flex-start">← Alle Bestellungen</button>' +
        head('<span class="mono">' + esc(o.number) + '</span>', pill(ORDER_STATUS, o.status) + ' &nbsp;<button class="link" data-act="open-client" data-id="' + esc(o.client.id) + '">' + esc(o.client.company || o.client.name) + '</button>' + (o.source === 'ADMIN' ? ' · von dir erfasst' : ' · über das Kundenportal'),
          pending ? '<button class="btn primary" data-act="order-accept">Annehmen</button><button class="btn danger" data-act="order-reject">Ablehnen</button>' : '') +
        '<div class="grid two"><div class="card"><h2>Bestellte Leistung</h2><dl class="kv"><dt>Produkt</dt><dd><b>' + esc(o.productName) + '</b> ' + pill(TYPES, o.productType) + '</dd><dt>Menge</dt><dd>' + qtyText(o) + (o.productType === 'HOURLY' ? ' (geschätzt)' : '') + '</dd><dt>Preis</dt><dd class="num">' + priceText({ type: o.productType, price: o.unitPrice, intervalUnit: o.intervalUnit, unit: o.unit }) + ' netto</dd>' +
          (o.setupFee > 0 ? '<dt>Einrichtung</dt><dd class="num">' + eur(o.setupFee) + ' netto, einmalig</dd>' : '') + '<dt>Bestellt am</dt><dd>' + fdate(o.createdAt) + '</dd>' + (o.decidedAt ? '<dt>Entschieden am</dt><dd>' + fdate(o.decidedAt) + '</dd>' : '') + '</dl>' +
          '<div class="sums" style="margin-top:12px"><div><span>Netto' + (o.productType === 'RENTAL' ? ' (erste Zahlung)' : '') + '</span><span>' + eur(t.net) + '</span></div><div><span>MwSt. ' + o.taxRate + ' %</span><span>' + eur(t.tax) + '</span></div><div class="tot"><span>Brutto</span><span>' + eur(t.gross) + '</span></div></div></div>' +
        '<div class="card"><h2>Anmerkung des Kunden</h2><p style="margin:0;white-space:pre-wrap">' + (o.note ? esc(o.note) : '<span class="sub">Keine Anmerkung.</span>') + '</p>' + (o.rejectReason ? '<h2 style="margin-top:16px">Grund der Ablehnung</h2><p style="margin:0">' + esc(o.rejectReason) + '</p>' : '') + '</div></div>' +
        (pending ? '<div class="demo"><span>' + orderEffect(o) + '</span></div>' : '') +
        (made.length ? '<div class="card"><h2>Daraus entstanden</h2><div class="row">' + made.join(' · ') + '</div></div>' : '');
    });
  }

  var fsize = function (b) { return b >= 1048576 ? (b / 1048576).toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' MB' : Math.max(1, Math.round(b / 1024)).toLocaleString('de-DE') + ' KB'; };
  var ftime = function (iso) { return iso ? new Date(iso).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '–'; };

  function vTeam() {
    return Promise.all([api('GET', '/api/users'), api('GET', '/api/backups')]).then(function (r) {
      var users = r[0], bk = r[1], st = bk.settings;
      var ageH = bk.lastBackupAt ? (Date.now() - new Date(bk.lastBackupAt).getTime()) / 36e5 : null;
      var warn = ageH === null ? 'Es gibt noch kein Backup. Erstelle jetzt eines und richte den täglichen Cron-Job ein.' : ageH > 48 ? 'Das letzte Backup ist ' + Math.floor(ageH / 24) + ' Tage alt. Läuft der tägliche Cron-Job?' : '';
      return head('Team', 'Benutzer, Rollen und Datensicherung.', '<button class="btn primary" data-act="new-user">+ Benutzer</button>') +
        '<div class="card" style="padding:6px"><div class="tablewrap"><table><thead><tr><th>Name</th><th>E-Mail</th><th>Rolle</th><th></th></tr></thead><tbody>' + users.map(function (u) {
          var self = u.id === me.id;
          return '<tr><td><b>' + esc(u.name) + '</b>' + (self ? ' <span class="tag">du</span>' : '') + '</td><td>' + esc(u.email) + '</td><td><select class="inline" data-user="' + esc(u.id) + '" aria-label="Rolle von ' + esc(u.name) + '"><option value="ADMIN"' + (u.role === 'ADMIN' ? ' selected' : '') + '>Admin</option><option value="MEMBER"' + (u.role === 'MEMBER' ? ' selected' : '') + '>Mitarbeiter</option></select></td><td class="r">' + (self ? '' : '<button class="btn sm ghost danger" data-act="del-user" data-id="' + esc(u.id) + '">Entfernen</button>') + '</td></tr>';
        }).join('') + '</tbody></table></div></div>' +
        '<div class="card"><h2>Datensicherung <button class="btn primary sm" data-act="backup-create">Backup jetzt erstellen</button></h2>' +
        (warn ? '<div class="errbox" style="margin-bottom:12px">' + esc(warn) + '</div>' : '') +
        '<p class="sub" style="margin:0 0 12px">Ein Backup enthält die komplette Datenbank und alle hochgeladenen Dokumente. ' + (st.auto ? 'Es entsteht automatisch (täglicher Cron-Aufruf, wenn das letzte älter als ' + st.intervalHours + ' Stunden ist). ' : 'Die automatische Sicherung ist abgeschaltet. ') +
        'Aufbewahrt werden die letzten ' + st.keep + ' Sicherungen und je Monat die neueste der letzten ' + st.keepMonths + ' Monate. ' + (st.encrypted ? '<span class="pill good">Verschlüsselt</span> ' : '<span class="pill warn">Nicht verschlüsselt</span> ') + (st.copyConfigured ? '<span class="pill good">Zweitkopie aktiv</span>' : '<span class="pill neutral">Keine Zweitkopie</span>') + '</p>' +
        '<div class="tablewrap"><table><thead><tr><th>Backup</th><th>Erstellt</th><th class="r">Größe</th><th></th></tr></thead><tbody>' + (bk.items.length ? bk.items.map(function (b) {
          return '<tr><td class="mono">' + esc(b.name) + '</td><td class="num">' + ftime(b.createdAt) + '</td><td class="r num">' + fsize(b.size) + '</td><td class="r"><div class="row" style="justify-content:flex-end"><button class="btn sm" data-act="backup-dl" data-name="' + esc(b.name) + '">Herunterladen</button><button class="btn sm ghost danger" data-act="backup-del" data-name="' + esc(b.name) + '">Löschen</button></div></td></tr>';
        }).join('') : '<tr><td colspan="4" class="empty">Noch kein Backup vorhanden.</td></tr>') + '</tbody></table></div>' +
        '<p class="sub" style="margin:12px 0 0">Wichtig: Speichere Backups zusätzlich an einem anderen Ort (anderer Server, Cloud-Ordner, externe Festplatte). Wiederherstellen: <span class="mono">php bin/restore.php &lt;Datei&gt; --yes</span></p></div>';
    });
  }

  /* ---------- Rendern ---------- */
  var VIEWS = { dashboard: vDashboard, clients: vClients, client: vClient, products: vProducts, orders: vOrders, order: vOrder, projects: vProjects, times: vTimes, quotes: vQuotes, quote: vQuote, invoices: vInvoices, invoice: vInvoice, reminders: vReminders, recurring: vRecurring, team: vTeam };
  var seq = 0;
  function render() {
    if (!token || !me) return;
    var mine = ++seq;
    renderShell();
    refreshBadge();
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
    var isNew = !c; c = c || { name: '', company: '', email: '', phone: '', address: '', zip: '', city: '', vatId: '', tags: '', status: 'LEAD' };
    modal(isNew ? 'Neuer Kunde' : 'Kunde bearbeiten',
      '<div class="form">' + field('cname', 'Ansprechpartner *', c.name) + field('ccompany', 'Firma', c.company) + field('cemail', 'E-Mail', c.email, 'email') + field('cphone', 'Telefon', c.phone) + field('cvat', 'USt-IdNr.', c.vatId) +
      field('caddr', 'Straße & Hausnummer', c.address, 'text', { full: true }) + field('czip', 'PLZ', c.zip) + field('ccity', 'Ort', c.city) +
      selectField('cstat', 'Status', opts(CLIENT_STATUS), c.status) + field('ctags', 'Tags (mit Komma getrennt)', c.tags, 'text', { full: true }) + '</div>', 'Speichern',
      function (f) {
        if (!v(f, 'cname')) return bad('Bitte einen Namen eingeben.');
        var data = { name: v(f, 'cname'), company: v(f, 'ccompany'), email: v(f, 'cemail'), phone: v(f, 'cphone'), vatId: v(f, 'cvat'), address: v(f, 'caddr'), zip: v(f, 'czip'), city: v(f, 'ccity'), tags: v(f, 'ctags'), status: v(f, 'cstat') };
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

  /** Gemeinsamer Dialog für Rechnung, Angebot und Abo (gleicher Positionseditor). */
  function docDialog(kind, preset) {
    preset = preset || {};
    var isRec = kind === 'recurring', isQuote = kind === 'quote';
    Promise.all([listAll('/api/clients', { sort: 'name' }), listAll('/api/projects'), api('GET', '/api/products?active=true')]).then(function (r) {
      var clients = r[0], projects = r[1], products = r[2];
      if (!clients.length) return toast('Lege zuerst einen Kunden an.');
      var rows = (preset.items || []).map(function (x) { return { description: x.description, quantity: x.quantity, unitPrice: x.unitPrice }; });
      if (!rows.length) rows = [{ description: '', quantity: 1, unitPrice: 0 }];
      var cid = preset.clientId || (ui.view === 'client' ? ui.clientId : clients[0].id);
      var days = function (n) { return new Date(Date.now() + n * 864e5).toISOString().slice(0, 10); };
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

      var top = selectField('icl', 'Kunde', clientOptions(clients), cid) + selectField('ipr', 'Projekt', projOpts(cid), preset.projectId || '');
      if (isRec) {
        top += field('rtitle', 'Bezeichnung *', preset.title || '', 'text', { full: true, attrs: 'placeholder="z. B. Hosting Paket M"' }) +
          selectField('rint', 'Rhythmus', Object.keys(INTERVALS).map(function (k) { return [k, INTERVALS[k]]; }), preset.intervalUnit || 'YEARLY') +
          field('rstart', 'Erste Abrechnung *', (preset.startDate || '').slice(0, 10) || today(), 'date') +
          field('rend', 'Ende (optional)', (preset.endDate || '').slice(0, 10), 'date') +
          field('rpay', 'Zahlungsziel (Tage)', preset.paymentDays != null ? preset.paymentDays : 14, 'number', { attrs: 'min="0" step="1"' }) +
          field('irate', 'MwSt. (%)', preset.taxRate != null ? preset.taxRate : 19, 'number', { attrs: 'min="0" step="1"' });
      } else {
        top += field('idue', isQuote ? 'Gültig bis' : 'Fällig am', days(isQuote ? 30 : 14), 'date') + field('irate', 'MwSt. (%)', '19', 'number', { attrs: 'min="0" step="1"' });
      }
      var title = isRec ? (preset.id ? 'Abo bearbeiten' : 'Neues Abo') : isQuote ? 'Neues Angebot' : 'Neue Rechnung';
      var body = '<div class="form">' + top + '</div>' +
        (products.length ? '<label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;font-weight:600;color:var(--muted)" for="iprod">Produkt aus dem Katalog einfügen<select id="iprod" style="font-weight:400;color:var(--fg)"><option value="">– Produkt wählen –</option>' + products.map(function (p) { return '<option value="' + esc(p.id) + '">' + esc((p.category ? p.category.name + ' › ' : '') + p.name + ' – ' + priceText(p)) + '</option>'; }).join('') + '</select></label>' : '') +
        '<div><div class="sub" style="margin-bottom:6px;font-weight:600">Positionen' + (isRec ? ' <span style="font-weight:400">(Platzhalter: {monat} {jahr} {zeitraum})</span>' : '') + '</div><div class="items" id="items"></div><button type="button" class="btn sm" data-row-add style="margin-top:8px">+ Position</button></div>' +
        '<div class="form">' + field('idisc', 'Rabatt (€)', preset.discount != null ? preset.discount : '0', 'number', { attrs: 'min="0" step="1"' }) +
        (isRec ? '<label class="full" style="flex-direction:row;align-items:center;gap:8px"><input id="rauto" type="checkbox"' + (preset.autoSend ? ' checked' : '') + '> Rechnung bei Fälligkeit automatisch per E-Mail senden</label>' : '') + '</div><div class="sums" id="sums"></div>';

      modal(title, body, isRec ? 'Speichern' : (isQuote ? 'Angebot erstellen' : 'Rechnung erstellen'), function (f) {
        var items = collect(f).filter(function (x) { return x.description; });
        if (!items.length || items.some(function (x) { return x.quantity <= 0; })) return bad('Mindestens eine Position mit Beschreibung und Menge nötig.');
        var common = { clientId: v(f, 'icl'), projectId: v(f, 'ipr'), taxRate: num(f, 'irate'), discount: num(f, 'idisc'), items: items };
        if (isRec) {
          if (!v(f, 'rtitle')) return bad('Bitte eine Bezeichnung eingeben.');
          if (!v(f, 'rstart')) return bad('Bitte das Datum der ersten Abrechnung angeben.');
          var rec = clean(Object.assign(common, { title: v(f, 'rtitle'), intervalUnit: v(f, 'rint'), startDate: v(f, 'rstart'), paymentDays: num(f, 'rpay'), autoSend: f.querySelector('#rauto').checked }));
          rec.endDate = v(f, 'rend'); // leer = kein Ende
          return (preset.id ? api('PATCH', '/api/recurring/' + preset.id, rec) : api('POST', '/api/recurring', rec)).then(function () { ui.view = 'recurring'; done(preset.id ? 'Abo gespeichert' : 'Abo angelegt'); });
        }
        if (isQuote) {
          return api('POST', '/api/quotes', clean(Object.assign(common, { validUntil: v(f, 'idue') ? noon(v(f, 'idue')) : '' }))).then(function (q) { ui.quoteId = q.id; ui.view = 'quote'; done('Angebot ' + q.number + ' erstellt'); });
        }
        return api('POST', '/api/invoices', clean(Object.assign(common, { dueDate: v(f, 'idue') ? noon(v(f, 'idue')) : '' }))).then(function (inv) { ui.invoiceId = inv.id; ui.view = 'invoice'; done('Rechnung ' + inv.number + ' erstellt'); });
      }, function (f) {
        draw(f);
        f.addEventListener('input', function (e) { if (e.target.closest('.item-row')) rows = collect(f); sums(f); });
        var picker = f.querySelector('#iprod');
        if (picker) picker.addEventListener('change', function (e) {
          var prod = products.filter(function (x) { return x.id === e.target.value; })[0];
          e.target.value = '';
          if (!prod) return;
          var row = { description: isRec ? prod.name + ' ({zeitraum})' : prod.name + (prod.type === 'RENTAL' && !isRec ? ' (' + INTERVALS[prod.intervalUnit] + ')' : ''), quantity: prod.minQuantity || 1, unitPrice: prod.price };
          rows = collect(f);
          var blank = rows.length === 1 && !rows[0].description && !rows[0].unitPrice;
          if (blank) { rows[0] = row; f.querySelector('#irate').value = prod.taxRate; } else rows.push(row);
          if (isRec && prod.type === 'RENTAL') { if (!v(f, 'rtitle')) f.querySelector('#rtitle').value = prod.name; f.querySelector('#rint').value = prod.intervalUnit; }
          draw(f);
        });
        f.querySelector('#icl').addEventListener('change', function (e) { f.querySelector('#ipr').innerHTML = projOpts(e.target.value).map(function (o) { return '<option value="' + esc(o[0]) + '">' + esc(o[1]) + '</option>'; }).join(''); });
        f.addEventListener('click', function (e) {
          var add = e.target.closest('[data-row-add]'), del = e.target.closest('[data-row-del]');
          if (add) { rows = collect(f); rows.push({ description: '', quantity: 1, unitPrice: 0 }); draw(f); var d = f.querySelectorAll('.item-row .d'); d[d.length - 1].focus(); }
          if (del) { rows = collect(f); if (rows.length > 1) rows.splice(+del.dataset.rowDel, 1); draw(f); }
        });
      });
    }).catch(function (e) { toast(e.message); });
  }
  var invoiceDialog = function (presetClient) { docDialog('invoice', { clientId: presetClient }); };

  function categoryDialog(cat) {
    cat = cat || { name: '', description: '', sortOrder: 0, active: true };
    modal(cat.id ? 'Kategorie bearbeiten' : 'Neue Kategorie',
      '<div class="form">' + field('catname', 'Name *', cat.name, 'text', { full: true }) + '<label class="full" for="catdesc">Beschreibung (im Portal sichtbar)<textarea id="catdesc" rows="3">' + esc(cat.description || '') + '</textarea></label>' +
      field('catsort', 'Reihenfolge', cat.sortOrder, 'number', { attrs: 'step="1"' }) + '<label style="flex-direction:row;align-items:center;gap:8px;align-self:end"><input id="catactive" type="checkbox"' + (cat.active ? ' checked' : '') + '> Im Portal anzeigen</label></div>', 'Speichern',
      function (f) {
        if (!v(f, 'catname')) return bad('Bitte einen Namen eingeben.');
        var body = { name: v(f, 'catname'), description: f.querySelector('#catdesc').value.trim(), sortOrder: parseInt(v(f, 'catsort'), 10) || 0, active: f.querySelector('#catactive').checked };
        return (cat.id ? api('PATCH', '/api/categories/' + cat.id, body) : api('POST', '/api/categories', body)).then(function (c) { if (!cat.id) ui.pcat = c.id; done(cat.id ? 'Kategorie gespeichert' : 'Kategorie angelegt'); });
      });
  }

  function productDialog(prod, presetCat) {
    api('GET', '/api/categories').then(function (cats) {
      prod = prod || { name: '', description: '', type: 'ONE_TIME', price: '', taxRate: 19, unit: '', intervalUnit: 'YEARLY', setupFee: 0, minQuantity: 1, active: true, sortOrder: 0, categoryId: presetCat || (ui.pcat && ui.pcat !== 'none' ? ui.pcat : '') };
      var typeOpts = [['ONE_TIME', 'Einmaliger Kauf (z. B. Webseite, Skript, Druckauftrag)'], ['RENTAL', 'Mietprodukt, wiederkehrend (z. B. Hosting, Domain, Wartung)'], ['HOURLY', 'Zeitprodukt, nach Stunden (Projekt auf Stundenbasis)']];
      modal(prod.id ? 'Produkt bearbeiten' : 'Neues Produkt',
        '<div class="form">' + field('pname', 'Name *', prod.name, 'text', { full: true }) +
        selectField('pcat', 'Kategorie', [['', '– ohne Kategorie –']].concat(cats.map(function (c) { return [c.id, c.name]; })), prod.categoryId || '') +
        selectField('ptype', 'Art', typeOpts, prod.type) +
        '<label for="pprice"><span id="plabel">Preis (netto) in €</span><input id="pprice" type="number" min="0" step="0.01" value="' + esc(prod.price) + '"></label>' + field('ptax', 'MwSt. (%)', prod.taxRate, 'number', { attrs: 'min="0" max="100" step="1"' }) +
        '<div class="form full" id="rentalFields" style="padding:0">' + selectField('pint', 'Abrechnung', Object.keys(PERIOD).map(function (k) { return [k, INTERVALS[k]]; }), prod.intervalUnit || 'YEARLY') + field('psetup', 'Einrichtungsgebühr (netto, einmalig)', prod.setupFee || 0, 'number', { attrs: 'min="0" step="0.01"' }) + '</div>' +
        '<div class="form full" id="unitFields" style="padding:0">' + field('punit', 'Einheit (z. B. Stück, Seite)', prod.unit, 'text', { attrs: 'maxlength="50"' }) + field('pmin', 'Mindestmenge', prod.minQuantity, 'number', { attrs: 'min="0.01" step="any"' }) + '</div>' +
        '<label class="full" for="pdesc">Beschreibung (im Portal sichtbar)<textarea id="pdesc" rows="3">' + esc(prod.description || '') + '</textarea></label>' +
        field('psort', 'Reihenfolge', prod.sortOrder, 'number', { attrs: 'step="1"' }) + '<label style="flex-direction:row;align-items:center;gap:8px;align-self:end"><input id="pactive" type="checkbox"' + (prod.active ? ' checked' : '') + '> Im Portal bestellbar</label></div>', 'Speichern',
        function (f) {
          if (!v(f, 'pname')) return bad('Bitte einen Namen eingeben.');
          var price = parseFloat(v(f, 'pprice'));
          if (isNaN(price) || price < 0) return bad('Bitte einen Preis ab 0 eingeben.');
          var body = { name: v(f, 'pname'), categoryId: v(f, 'pcat'), type: v(f, 'ptype'), price: price, taxRate: num(f, 'ptax'), description: f.querySelector('#pdesc').value.trim(), unit: v(f, 'punit'), minQuantity: num(f, 'pmin'), sortOrder: parseInt(v(f, 'psort'), 10) || 0, active: f.querySelector('#pactive').checked };
          if (body.type === 'RENTAL') { body.intervalUnit = v(f, 'pint'); body.setupFee = num(f, 'psetup') || 0; }
          return (prod.id ? api('PATCH', '/api/products/' + prod.id, body) : api('POST', '/api/products', body)).then(function () {
            // Das gespeicherte Produkt soll sichtbar sein: den Filter auf seine Kategorie umstellen, falls ein anderer aktiv ist
            if (ui.pcat) ui.pcat = body.categoryId || 'none';
            done(prod.id ? 'Produkt gespeichert' : 'Produkt angelegt');
          });
        }, function (f) {
          var sync = function () {
            var t = f.querySelector('#ptype').value;
            f.querySelector('#plabel').textContent = t === 'RENTAL' ? 'Preis je Abrechnungszeitraum (netto) in €' : t === 'HOURLY' ? 'Stundensatz (netto) in €' : 'Preis (netto) in €';
            f.querySelector('#rentalFields').hidden = t !== 'RENTAL';
            f.querySelector('#unitFields').hidden = t === 'HOURLY' ? true : false;
          };
          f.querySelector('#ptype').addEventListener('change', sync); sync();
        });
    }).catch(fail);
  }

  /** Bestellung für einen Kunden erfassen (z. B. nach einem Telefonat). */
  function newOrderDialog() {
    Promise.all([listAll('/api/clients', { sort: 'name' }), api('GET', '/api/products')]).then(function (r) {
      var clients = r[0], products = r[1];
      if (!clients.length) return toast('Lege zuerst einen Kunden an.');
      if (!products.length) return toast('Lege zuerst Produkte im Katalog an.');
      modal('Bestellung erfassen',
        '<div class="form">' + selectField('okc', 'Kunde', clientOptions(clients), clients[0].id, true) +
        selectField('okp', 'Produkt', products.map(function (p) { return [p.id, (p.category ? p.category.name + ' › ' : '') + p.name + ' – ' + priceText(p) + (p.active ? '' : ' (inaktiv)')]; }), products[0].id, true) +
        field('okq', 'Menge', 1, 'number', { attrs: 'min="0.01" step="any"' }) + '<label class="full" for="okn">Anmerkung<textarea id="okn" rows="3"></textarea></label></div>', 'Bestellung anlegen',
        function (f) {
          var q = num(f, 'okq');
          if (!(q > 0)) return bad('Bitte eine Menge über 0 eingeben.');
          return api('POST', '/api/orders', { clientId: v(f, 'okc'), productId: v(f, 'okp'), quantity: q, note: f.querySelector('#okn').value.trim() }).then(function (o) { ui.orderId = o.id; ui.view = 'order'; done('Bestellung ' + o.number + ' angelegt'); });
        });
    }).catch(fail);
  }

  function acceptOrderDialog(o) {
    var rental = o.productType === 'RENTAL';
    modal('Bestellung ' + esc(o.number) + ' annehmen',
      '<div class="demo" style="margin:0"><span>' + orderEffect(o) + '</span></div>' +
      (rental ? '<div class="form">' + field('astart', 'Erste Abrechnung am', today(), 'date') + '<label class="full" style="flex-direction:row;align-items:center;gap:8px"><input id="abill" type="checkbox" checked> Erste Abo-Rechnung gleich erzeugen (wenn der Start heute oder früher ist)</label>' +
        '<label class="full" style="flex-direction:row;align-items:center;gap:8px"><input id="aauto" type="checkbox"> Abo-Rechnungen automatisch per E-Mail an den Kunden senden</label></div>' : '') +
      '<div class="sub">Der Kunde bekommt eine Bestätigung per E-Mail (wenn E-Mail eingerichtet ist). Die Rechnungen bleiben Entwürfe, bis du sie versendest.</div>', 'Annehmen',
      function (f) {
        var body = rental ? { startDate: v(f, 'astart') ? v(f, 'astart') : '', billNow: f.querySelector('#abill').checked, autoSend: f.querySelector('#aauto').checked } : {};
        return api('POST', '/api/orders/' + o.id + '/accept', body).then(function () { done('Bestellung angenommen'); });
      });
  }

  function rejectOrderDialog(o) {
    modal('Bestellung ' + esc(o.number) + ' ablehnen',
      '<div class="form"><label class="full" for="rreason">Grund (der Kunde sieht ihn im Portal und in der E-Mail)<textarea id="rreason" rows="4" placeholder="optional"></textarea></label></div>', 'Ablehnen',
      function (f) { return api('POST', '/api/orders/' + o.id + '/reject', { reason: f.querySelector('#rreason').value.trim() }).then(function () { done('Bestellung abgelehnt'); }); }, null, true);
  }

  function copyText(text, okMsg) {
    var ok = function () { toast(okMsg || 'Kopiert'); };
    var fallback = function () {
      var inp = $('#plink'); if (inp) { inp.focus(); inp.select(); }
      toast('Bitte den Text markieren und mit Strg+C kopieren');
    };
    try { (navigator.clipboard && navigator.clipboard.writeText ? navigator.clipboard.writeText(text) : Promise.reject()).then(ok, fallback); } catch (e) { fallback(); }
  }

  /** Portalzugang erstellen: optional per E-Mail senden, danach den Link zum Kopieren anzeigen. */
  function portalDialog(clientId) {
    api('GET', '/api/clients/' + clientId + '/portal').then(function (st) {
      var canMail = st.mailConfigured;
      modal(st.active ? 'Neuen Zugangslink erstellen' : 'Portalzugang erstellen',
        (st.active ? notice('Der bisherige Link wird dadurch ungültig.') : '') +
        '<div class="form">' + (canMail ? '<label class="full" style="flex-direction:row;align-items:center;gap:8px"><input id="psend" type="checkbox"' + (st.recipient ? ' checked' : '') + '> Link per E-Mail an den Kunden senden</label>' + field('pto', 'Empfänger', st.recipient || '', 'email', { full: true }) : '<div class="full sub">E-Mail-Versand ist nicht eingerichtet. Du bekommst den Link gleich zum Kopieren und kannst ihn selbst schicken.</div>') + '</div>' +
        '<div class="sub">Der Link wird nur ein Mal angezeigt. Wer ihn hat, sieht die Rechnungen und Angebote dieses Kunden.</div>', 'Link erstellen',
        function (f) {
          var send = canMail && f.querySelector('#psend').checked;
          if (send && !v(f, 'pto')) return bad('Bitte eine Empfängeradresse eingeben.');
          return api('POST', '/api/clients/' + clientId + '/portal', send ? { send: true, to: v(f, 'pto') } : {}).then(function (res) {
            render();
            modal('Portalzugang bereit',
              (res.emailed ? '<div class="pill good" style="align-self:flex-start">Per E-Mail an ' + esc(res.to) + ' gesendet</div>' : '') + (res.emailError ? notice('E-Mail konnte nicht gesendet werden: ' + esc(res.emailError) + '. Du kannst den Link unten kopieren.') : '') +
              '<label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;font-weight:600;color:var(--muted)" for="plink">Zugangslink<input id="plink" readonly value="' + esc(res.link) + '" style="font-weight:400;color:var(--fg)"></label>' +
              '<div class="row"><button type="button" class="btn primary" data-act="copy-text" data-text="' + esc(res.link) + '">Link kopieren</button></div>' +
              '<div class="sub">' + (res.expiresAt ? 'Gültig bis ' + fdate(res.expiresAt) + '. ' : '') + 'Dieser Link wird nicht erneut angezeigt.</div>', 'Fertig', function () { return true; });
            return false; // der Ergebnis-Dialog bleibt offen
          });
        });
    }).catch(fail);
  }

  var notice = function (html) { return '<div class="errbox" style="margin:0">' + html + '</div>'; };

  /** E-Mail-Dialog für Rechnung oder Angebot: Vorschlag laden, bearbeiten, senden. */
  function emailDialog(kind, id) {
    var base = (kind === 'quote' ? '/api/quotes/' : '/api/invoices/') + id;
    api('GET', base + '/email-draft').then(function (d) {
      modal('Per E-Mail senden',
        (d.mailConfigured ? '' : notice('E-Mail-Versand ist noch nicht eingerichtet. Trage SMTP_HOST und die Zugangsdaten in die .env ein (siehe README).')) +
        '<div class="form">' + field('mto', 'Empfänger', d.to, 'email', { full: true }) + field('msub', 'Betreff', d.subject, 'text', { full: true }) +
        '<label class="full" for="mmsg">Nachricht<textarea id="mmsg" rows="9"></textarea></label></div>' +
        '<div class="sub">Anhang: ' + esc(d.attachment) + '. Deine Grußformel wird automatisch angehängt.</div>', 'Jetzt senden',
        function (f) {
          if (!v(f, 'mto')) return bad('Bitte eine Empfängeradresse eingeben.');
          return api('POST', base + '/send', { to: v(f, 'mto'), subject: v(f, 'msub'), message: f.querySelector('#mmsg').value.trim() }).then(function () { done('E-Mail gesendet'); });
        }, function (f) {
          f.querySelector('#mmsg').value = d.message;
          if (!d.mailConfigured) f.querySelector('button[type=submit]').disabled = true;
        });
    }).catch(fail);
  }

  /** Dialog für Zahlungserinnerung / Mahnung mit stufenweisem Textvorschlag. */
  function reminderDialog(invoiceId, level) {
    api('GET', '/api/invoices/' + invoiceId + '/reminder-draft' + qs({ level: level })).then(function (d) {
      var canSend = d.mailConfigured && d.to;
      modal(esc(d.levelName) + ' erstellen',
        (d.mailConfigured ? '' : notice('E-Mail-Versand ist nicht eingerichtet. Du kannst die Mahnung speichern und als PDF herunterladen.')) +
        '<div class="form">' + selectField('rlevel', 'Stufe', [['1', LEVELS[1]], ['2', LEVELS[2]], ['3', LEVELS[3]]], String(d.level)) + field('rfee', 'Mahngebühr (€)', d.fee, 'number', { attrs: 'min="0" step="0.5"' }) +
        field('rdue', 'Neue Zahlungsfrist', d.dueDate.slice(0, 10), 'date') + field('rto', 'Empfänger', d.to, 'email') + field('rsub', 'Betreff', d.subject, 'text', { full: true }) +
        '<label class="full" for="rmsg">Text<textarea id="rmsg" rows="10"></textarea></label>' +
        '<label class="full" style="flex-direction:row;align-items:center;gap:8px"><input id="rsend" type="checkbox"' + (canSend ? ' checked' : '') + (d.mailConfigured ? '' : ' disabled') + '> Per E-Mail senden (Mahnung und Rechnung als PDF im Anhang)</label></div>' +
        '<div class="sub">Offener Betrag: ' + eur(d.balance) + '. Bei einem Stufenwechsel wird der Text neu vorgeschlagen.</div>', 'Mahnung erstellen',
        function (f) {
          var send = f.querySelector('#rsend').checked;
          if (send && !v(f, 'rto')) return bad('Für den Versand wird eine Empfängeradresse benötigt.');
          if (!f.querySelector('#rmsg').value.trim()) return bad('Bitte einen Text eingeben.');
          return api('POST', '/api/invoices/' + invoiceId + '/reminders', clean({ level: parseInt(v(f, 'rlevel'), 10), fee: num(f, 'rfee') || 0, dueDate: noon(v(f, 'rdue')), subject: v(f, 'rsub'), message: f.querySelector('#rmsg').value.trim(), send: send, to: v(f, 'rto') })).then(function () {
            done(send ? LEVELS[parseInt(v(f, 'rlevel'), 10)] + ' gesendet' : 'Mahnung gespeichert');
          });
        }, function (f) {
          f.querySelector('#rmsg').value = d.message;
          f.querySelector('#rlevel').addEventListener('change', function (e) {
            api('GET', '/api/invoices/' + invoiceId + '/reminder-draft' + qs({ level: e.target.value })).then(function (n) {
              f.querySelector('#rfee').value = n.fee; f.querySelector('#rdue').value = n.dueDate.slice(0, 10);
              f.querySelector('#rsub').value = n.subject; f.querySelector('#rmsg').value = n.message;
            }).catch(fail);
          });
        });
    }).catch(fail);
  }

  function downloadPdf(btn, url) {
    btn.disabled = true;
    fetch(url, { headers: { Authorization: 'Bearer ' + token } }).then(function (res) {
      if (res.status === 401) { logout(); throw new Error('Bitte erneut anmelden'); }
      if (!res.ok) throw new Error('PDF konnte nicht erstellt werden');
      var name = (/filename="([^"]+)"/.exec(res.headers.get('Content-Disposition') || '') || [])[1] || 'Dokument.pdf';
      return res.blob().then(function (blob) { return { blob: blob, name: name }; });
    }).then(function (r) {
      var a = document.createElement('a');
      a.href = URL.createObjectURL(r.blob); a.download = r.name; document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
      toast('Datei heruntergeladen');
    }).catch(function (err) { toast(err.message); }).then(function () { btn.disabled = false; });
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
      case 'pdf': return downloadPdf(el, el.dataset.url);
      case 'pfilter': ui.pcat = el.dataset.v; return render();
      case 'ofilter': ui.ostatus = el.dataset.v; return render();
      case 'open-order': return go('order', { orderId: id });
      case 'catalog-examples': el.disabled = true; return api('POST', '/api/catalog/examples', {}).then(function (r) { done(r.products + ' Produkte in ' + r.categories + ' Kategorien angelegt. Bitte Preise prüfen und aktivieren.'); }).catch(function (err) { el.disabled = false; toast(err.message); });
      case 'new-category': return categoryDialog();
      case 'edit-category': return api('GET', '/api/categories').then(function (list) { categoryDialog(list.filter(function (c) { return c.id === id; })[0]); }).catch(fail);
      case 'del-category': return confirmDialog('Die Kategorie wird gelöscht. Ihre Produkte bleiben erhalten und stehen danach unter „Ohne Kategorie“.', 'Kategorie löschen', function () { return api('DELETE', '/api/categories/' + id).then(function () { ui.pcat = ''; done('Kategorie gelöscht'); }); });
      case 'new-product': return productDialog(null, el.dataset.cat);
      case 'prod-edit': return api('GET', '/api/products/' + id).then(function (p) { productDialog(p); }).catch(fail);
      case 'prod-toggle': return api('PATCH', '/api/products/' + id, { active: el.dataset.active !== '1' }).then(function () { done(el.dataset.active === '1' ? 'Produkt deaktiviert' : 'Produkt aktiviert'); }).catch(fail);
      case 'prod-dup': return api('POST', '/api/products/' + id + '/duplicate').then(function () { done('Kopie angelegt (inaktiv)'); }).catch(fail);
      case 'prod-del': return confirmDialog('Das Produkt wird aus dem Katalog gelöscht. Bereits eingegangene Bestellungen behalten Name und Preis.', 'Produkt löschen', function () { return api('DELETE', '/api/products/' + id).then(function () { done('Produkt gelöscht'); }); });
      case 'new-order': return newOrderDialog();
      case 'order-accept': return api('GET', '/api/orders/' + ui.orderId).then(acceptOrderDialog).catch(fail);
      case 'order-reject': return api('GET', '/api/orders/' + ui.orderId).then(rejectOrderDialog).catch(fail);
      case 'copy-text': return copyText(el.dataset.text, 'Link kopiert');
      case 'portal-issue': return portalDialog(id);
      case 'acct-toggle': return api('POST', '/api/portal-accounts/' + id + '/active', { active: el.dataset.active !== '1' }).then(function () { done(el.dataset.active === '1' ? 'Konto gesperrt' : 'Konto entsperrt'); }).catch(fail);
      case 'acct-reset': return api('POST', '/api/portal-accounts/' + id + '/reset', {}).then(function () { toast('Link zum Zurücksetzen gesendet'); }).catch(fail);
      case 'acct-del': return confirmDialog('Das Konto wird gelöscht. Der Kunde und seine Daten bleiben erhalten, er kann sich aber nicht mehr mit diesem Konto anmelden.', 'Konto löschen', function () { return api('DELETE', '/api/portal-accounts/' + id).then(function () { done('Konto gelöscht'); }); });
      case 'portal-revoke': return confirmDialog('Der Kunde kann das Portal danach nicht mehr öffnen. Du kannst jederzeit einen neuen Link erstellen.', 'Zugang sperren', function () { return api('DELETE', '/api/clients/' + id + '/portal').then(function () { done('Portalzugang gesperrt'); }); });
      case 'backup-create': el.disabled = true; return api('POST', '/api/backups', {}).then(function (b) { done('Backup erstellt (' + fsize(b.size) + ')'); }).catch(function (err) { el.disabled = false; toast(err.message); });
      case 'backup-dl': return downloadPdf(el, '/api/backups/' + encodeURIComponent(el.dataset.name));
      case 'backup-del': return confirmDialog('Das Backup <b class="mono">' + esc(el.dataset.name) + '</b> wird dauerhaft gelöscht.', 'Löschen', function () { return api('DELETE', '/api/backups/' + encodeURIComponent(el.dataset.name)).then(function () { done('Backup gelöscht'); }); });
      case 'open-quote': return go('quote', { quoteId: id });
      case 'new-quote': return docDialog('quote', { clientId: id });
      case 'qfilter': ui.qstatus = el.dataset.v; return render();
      case 'mail-invoice': return emailDialog('invoice', ui.invoiceId);
      case 'mail-quote': return emailDialog('quote', ui.quoteId);
      case 'convert-quote': return confirmDialog('Aus dem Angebot wird eine Rechnung (Entwurf) mit denselben Positionen erstellt. Das Angebot gilt danach als angenommen.', 'Rechnung erstellen', function () { return api('POST', '/api/quotes/' + ui.quoteId + '/convert').then(function (inv) { ui.invoiceId = inv.id; ui.view = 'invoice'; done('Rechnung ' + inv.number + ' erstellt'); }); });
      case 'del-quote': return confirmDialog('Das Angebot wird dauerhaft gelöscht.', 'Endgültig löschen', function () { return api('DELETE', '/api/quotes/' + ui.quoteId).then(function () { go('quotes'); toast('Angebot gelöscht'); }); });
      case 'remind': return reminderDialog(id, el.dataset.level);
      case 'del-reminder': return del('/api/reminders/' + id, 'Mahnung gelöscht');
      case 'new-recurring': return docDialog('recurring', { clientId: id });
      case 'rec-edit': return api('GET', '/api/recurring/' + id).then(function (rec) { docDialog('recurring', rec); }).catch(fail);
      case 'rec-run': return confirmDialog('Die nächste Rechnung dieses Abos wird jetzt als Entwurf erstellt und der Zeitplan rückt einen Zeitraum weiter.', 'Jetzt abrechnen', function () { return api('POST', '/api/recurring/' + id + '/run').then(function (inv) { ui.invoiceId = inv.id; ui.view = 'invoice'; done('Rechnung ' + inv.number + ' erstellt'); }); });
      case 'rec-toggle': return api('PATCH', '/api/recurring/' + id, { active: el.dataset.active !== '1' }).then(function () { done(el.dataset.active === '1' ? 'Abo pausiert' : 'Abo fortgesetzt'); }).catch(fail);
      case 'rec-del': return confirmDialog('Das Abo wird gelöscht. Bereits erzeugte Rechnungen bleiben erhalten.', 'Endgültig löschen', function () { return api('DELETE', '/api/recurring/' + id).then(function () { done('Abo gelöscht'); }); });
      case 'run-due': return api('POST', '/api/recurring/run-due').then(function (res) { done(res.created ? res.created + (res.created === 1 ? ' Rechnung erzeugt' : ' Rechnungen erzeugt') : 'Nichts fällig'); }).catch(fail);
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
    else if (t.id === 'qstat') api('PATCH', '/api/quotes/' + ui.quoteId, { status: t.value }).then(function () { done('Status geändert'); }).catch(fail);
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
