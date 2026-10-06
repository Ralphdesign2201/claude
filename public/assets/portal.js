(function () {
  'use strict';

  var $ = function (s, el) { return (el || document).querySelector(s); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var eur = function (n) { return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(n || 0); };
  var fdate = function (iso) { return iso ? new Date(iso).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '–'; };
  var r2 = function (n) { return Math.round(n * 100) / 100; };

  var INVOICE = { SENT: ['Offen', 'info'], OVERDUE: ['Überfällig', 'bad'], PAID: ['Bezahlt', 'good'] };
  var QUOTE = { SENT: ['Wartet auf Ihre Antwort', 'info'], ACCEPTED: ['Angenommen', 'good'], DECLINED: ['Abgelehnt', 'neutral'], EXPIRED: ['Abgelaufen', 'warn'] };
  var ORDER = { PENDING: ['Eingegangen', 'info'], ACCEPTED: ['Bestätigt', 'good'], REJECTED: ['Abgelehnt', 'bad'], CANCELLED: ['Storniert', 'neutral'] };
  var TYPE = { ONE_TIME: 'Einmalig', RENTAL: 'Mietprodukt', HOURLY: 'Nach Stunden' };
  var PERIOD = { MONTHLY: 'Monat', QUARTERLY: 'Quartal', HALF_YEARLY: 'Halbjahr', YEARLY: 'Jahr' };
  var RHYTHM = { MONTHLY: 'monatlich', QUARTERLY: 'vierteljährlich', HALF_YEARLY: 'halbjährlich', YEARLY: 'jährlich' };
  var LSTATUS = { PENDING: ['Wartet auf Zahlung', 'warn'], ACTIVE: ['Aktiv', 'good'], SUSPENDED: ['Gesperrt', 'bad'], REVOKED: ['Widerrufen', 'bad'], EXPIRED: ['Abgelaufen', 'warn'] };
  var qty = function (n) { return Number(n).toLocaleString('de-DE', { maximumFractionDigits: 2 }); };
  var pill = function (map, key) { var m = map[key] || [key, 'neutral']; return '<span class="pill ' + m[1] + '">' + esc(m[0]) + '</span>'; };

  /* Der Zugangsschlüssel steht im Fragment (#…) der Adresse und wird nie an den Server als URL gesendet. */
  var KEY = 'portal-token';           // sessionStorage: nur für diesen Tab
  var SESSION_KEY = 'portal-session'; // localStorage: „angemeldet bleiben“
  var token = null;
  var pending = null;                 // Link aus einer E-Mail: Konto bestätigen oder Passwort zurücksetzen
  var frag = '';
  try { frag = decodeURIComponent((location.hash || '').replace(/^#/, '')).trim(); } catch (e) { frag = (location.hash || '').replace(/^#/, ''); }
  var m = /^(verify|reset)=([A-Za-z0-9_-]{20,128})$/.exec(frag);
  if (m) pending = { kind: m[1], token: m[2] };
  else if (/^[A-Za-z0-9_-]{20,128}$/.test(frag)) { token = frag; try { sessionStorage.setItem(KEY, frag); } catch (e) { /* Speicher nicht verfügbar */ } }
  if (!token && !pending) { try { token = localStorage.getItem(SESSION_KEY) || sessionStorage.getItem(KEY); } catch (e) { /* Speicher nicht verfügbar */ } }
  if (location.hash) { try { history.replaceState(null, '', location.pathname); } catch (e) { /* ignorieren */ } }

  // Wird ein Link aus einer E-Mail in einem bereits geöffneten Portal-Tab angeklickt, ändert sich nur das Fragment: neu laden
  window.addEventListener('hashchange', function () { if (location.hash) location.reload(); });

  function storeToken(value, remember) {
    token = value;
    try { sessionStorage.removeItem(KEY); localStorage.removeItem(SESSION_KEY); (remember ? localStorage : sessionStorage).setItem(remember ? SESSION_KEY : KEY, value); } catch (e) { /* Speicher nicht verfügbar */ }
  }
  function clearToken() {
    token = null;
    try { sessionStorage.removeItem(KEY); localStorage.removeItem(SESSION_KEY); } catch (e) { /* Speicher nicht verfügbar */ }
  }

  var state = { me: null, invoices: [], quotes: [], products: [], orders: [], licenses: [], tab: 'invoices', open: {}, shopCat: '' };

  function api(method, path, body) {
    var headers = { 'X-Portal-Token': token || '' };
    var init = { method: method, headers: headers };
    if (body !== undefined) { headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(body); }
    return fetch(path, init).catch(function () { throw new Error('Keine Verbindung zum Server'); }).then(function (res) {
      if (res.status === 204) return null;
      return res.json().catch(function () { return null; }).then(function (data) {
        if (!res.ok) { var err = new Error((data && data.error) || 'Fehler ' + res.status); err.status = res.status; throw err; }
        return data;
      });
    });
  }

  function toast(msg) {
    document.querySelectorAll('.toast').forEach(function (n) { n.remove(); });
    var el = document.createElement('div'); el.className = 'toast'; el.setAttribute('role', 'status'); el.textContent = msg; document.body.appendChild(el);
    setTimeout(function () { el.remove(); }, 2600);
  }

  function showError(title, text) {
    $('#root').innerHTML = '<div class="portal"><div class="card stack" style="margin-top:12vh"><h1 style="font-size:22px">' + esc(title) + '</h1><p style="margin:0;color:var(--muted)">' + esc(text) + '</p></div></div>';
  }

  /* ---------- Anmeldung und Registrierung ---------- */
  var cfg = { registration: false, company: 'Kundenportal', termsUrl: null, privacyUrl: null, minPassword: 10 };
  function loadConfig() { return api('GET', '/api/portal/config').then(function (c) { cfg = c; }).catch(function () { /* Standardwerte */ }); }

  var inp = function (id, label, type, extra) {
    return '<label for="' + id + '">' + label + '<input id="' + id + '" type="' + type + '" ' + (extra || '') + '></label>';
  };
  var notice = function (msg, kind) { return msg ? '<div class="note ' + (kind || 'err') + '" role="' + (kind === 'ok' ? 'status' : 'alert') + '">' + esc(msg) + '</div>' : ''; };
  function authShell(inner) {
    return '<div class="portal"><div class="p-top"><i>' + esc((cfg.company || 'K').charAt(0).toUpperCase()) + '</i><span>' + esc(cfg.company) + '</span></div><div class="card auth">' + inner + '</div></div>';
  }
  function termsHtml() {
    if (!cfg.termsUrl && !cfg.privacyUrl) return '';
    var parts = [];
    if (cfg.privacyUrl) parts.push('die <a href="' + esc(cfg.privacyUrl) + '" target="_blank" rel="noopener">Datenschutzerklärung</a> gelesen');
    if (cfg.termsUrl) parts.push('die <a href="' + esc(cfg.termsUrl) + '" target="_blank" rel="noopener">AGB</a> akzeptiert');
    return '<label class="check"><input id="a-terms" type="checkbox"> <span>Ich habe ' + parts.join(' und ') + '.</span></label>';
  }
  function showAuth(mode, msg, kind) {
    var tabs = '<div class="tabs" role="tablist"><button role="tab" data-act="auth-mode" data-mode="login" aria-selected="' + (mode === 'login') + '">Anmelden</button>' +
      (cfg.registration ? '<button role="tab" data-act="auth-mode" data-mode="register" aria-selected="' + (mode === 'register') + '">Registrieren</button>' : '') + '</div>';
    var body;
    if (mode === 'register') {
      body = '<form id="f-register" class="stack" novalidate><p class="sub" style="margin:0">Lege dein Kundenkonto an, um Produkte zu bestellen und deine Rechnungen einzusehen. Wir schicken dir eine E-Mail mit einem Link, über den du dein Passwort festlegst.</p>' +
        inp('a-name', 'Name', 'text', 'autocomplete="name" maxlength="200"') + inp('a-company', 'Firma (optional)', 'text', 'autocomplete="organization" maxlength="200"') + inp('a-email', 'E-Mail', 'email', 'autocomplete="email" inputmode="email"') +
        '<div class="hp" aria-hidden="true"><label>Website<input id="a-website" type="text" tabindex="-1" autocomplete="off"></label></div>' + termsHtml() +
        '<button class="btn primary" type="submit" style="justify-content:center">Registrieren</button></form>';
    } else if (mode === 'forgot') {
      body = '<form id="f-forgot" class="stack" novalidate><p class="sub" style="margin:0">Gib deine E-Mail-Adresse ein. Wir schicken dir einen Link, mit dem du ein neues Passwort festlegst.</p>' + inp('a-email', 'E-Mail', 'email', 'autocomplete="username" inputmode="email"') +
        '<button class="btn primary" type="submit" style="justify-content:center">Link senden</button><button type="button" class="link-btn" data-act="auth-mode" data-mode="login">Zurück zur Anmeldung</button></form>';
    } else {
      body = '<form id="f-login" class="stack" novalidate>' + inp('a-email', 'E-Mail', 'email', 'autocomplete="username" inputmode="email"') + inp('a-pass', 'Passwort', 'password', 'autocomplete="current-password"') +
        '<label class="check"><input id="a-remember" type="checkbox"> <span>Angemeldet bleiben</span></label>' +
        '<button class="btn primary" type="submit" style="justify-content:center">Anmelden</button><button type="button" class="link-btn" data-act="auth-mode" data-mode="forgot">Passwort vergessen?</button></form>';
    }
    $('#root').innerHTML = authShell('<h1 style="font-size:22px">' + (mode === 'register' ? 'Konto erstellen' : mode === 'forgot' ? 'Passwort vergessen' : 'Anmelden') + '</h1>' + (mode === 'forgot' ? '' : tabs) + notice(msg, kind) + body);
    var first = $('#a-name') || $('#a-email'); if (first) first.focus();
  }

  function showVerify(info) {
    $('#root').innerHTML = authShell('<h1 style="font-size:22px">Konto aktivieren</h1><p class="sub" style="margin:0">Deine E-Mail-Adresse ' + esc(info.email) + ' ist bestätigt, sobald du jetzt ein Passwort festlegst.</p>' +
      '<form id="f-verify" class="stack" novalidate><div id="a-msg"></div>' + inp('a-name', 'Name', 'text', 'autocomplete="name" maxlength="200" value="' + esc(info.name) + '"') +
      inp('a-pass', 'Passwort (mindestens ' + cfg.minPassword + ' Zeichen)', 'password', 'autocomplete="new-password"') + inp('a-pass2', 'Passwort wiederholen', 'password', 'autocomplete="new-password"') +
      '<label class="check"><input id="a-remember" type="checkbox"> <span>Angemeldet bleiben</span></label><button class="btn primary" type="submit" style="justify-content:center">Konto aktivieren</button></form>');
    $('#a-pass').focus();
  }
  function showReset() {
    $('#root').innerHTML = authShell('<h1 style="font-size:22px">Neues Passwort festlegen</h1><form id="f-reset" class="stack" novalidate><div id="a-msg"></div>' + inp('a-pass', 'Neues Passwort (mindestens ' + cfg.minPassword + ' Zeichen)', 'password', 'autocomplete="new-password"') +
      inp('a-pass2', 'Passwort wiederholen', 'password', 'autocomplete="new-password"') + '<button class="btn primary" type="submit" style="justify-content:center">Passwort speichern</button></form>');
    $('#a-pass').focus();
  }
  function passwordProblem(p1, p2) {
    if (p1.length < cfg.minPassword) return 'Das Passwort braucht mindestens ' + cfg.minPassword + ' Zeichen.';
    if (p1 !== p2) return 'Die beiden Passwörter sind nicht gleich.';
    return null;
  }
  function authError(msg) { var box = $('#a-msg'); if (box) box.innerHTML = notice(msg); else toast(msg); }

  document.addEventListener('submit', function (e) {
    var f = e.target, id = f.id;
    if (!/^f-(login|register|forgot|verify|reset)$/.test(id)) return;
    e.preventDefault();
    var btn = f.querySelector('button[type=submit]'), val = function (n) { var el = $('#' + n); return el ? el.value.trim() : ''; };
    var done = function () { btn.disabled = false; };
    btn.disabled = true;
    if (id === 'f-login') {
      api('POST', '/api/portal/login', { email: val('a-email'), password: $('#a-pass').value }).then(function (res) {
        storeToken(res.token, $('#a-remember').checked); return load();
      }).catch(function (err) { done(); showAuth('login', err.message); $('#a-email').value = val('a-email'); });
    } else if (id === 'f-register') {
      if (!val('a-name') || !val('a-email')) { done(); return toast('Bitte Name und E-Mail-Adresse eingeben.'); }
      var needTerms = cfg.termsUrl || cfg.privacyUrl;
      if (needTerms && !$('#a-terms').checked) { done(); return toast('Bitte bestätige die Datenschutzerklärung bzw. die AGB.'); }
      api('POST', '/api/portal/register', { name: val('a-name'), company: val('a-company'), email: val('a-email'), terms: needTerms ? $('#a-terms').checked : true, website: val('a-website') }).then(function (res) {
        $('#root').innerHTML = authShell('<h1 style="font-size:22px">Bitte E-Mails prüfen</h1>' + notice(res.message, 'ok') + '<p class="sub" style="margin:0">Keine E-Mail erhalten? Schau im Spam-Ordner nach oder registriere dich noch einmal mit derselben Adresse, dann senden wir einen neuen Link.</p><button class="btn" data-act="auth-mode" data-mode="login">Zur Anmeldung</button>');
      }).catch(function (err) { done(); toast(err.message); });
    } else if (id === 'f-forgot') {
      api('POST', '/api/portal/forgot', { email: val('a-email') }).then(function (res) { showAuth('login', res.message, 'ok'); }).catch(function (err) { done(); toast(err.message); });
    } else if (id === 'f-verify') {
      var problem = passwordProblem($('#a-pass').value, $('#a-pass2').value);
      if (problem) { done(); return authError(problem); }
      api('POST', '/api/portal/verify', { token: pending.token, password: $('#a-pass').value, name: val('a-name') }).then(function (res) {
        storeToken(res.token, $('#a-remember').checked); pending = null; return load();
      }).catch(function (err) { done(); authError(err.message); });
    } else if (id === 'f-reset') {
      var problem2 = passwordProblem($('#a-pass').value, $('#a-pass2').value);
      if (problem2) { done(); return authError(problem2); }
      api('POST', '/api/portal/reset', { token: pending.token, password: $('#a-pass').value }).then(function () {
        pending = null; clearToken(); showAuth('login', 'Dein Passwort wurde geändert. Bitte melde dich jetzt an.', 'ok');
      }).catch(function (err) { done(); authError(err.message); });
    }
  });

  function passwordDialog() {
    $('#layer').innerHTML = '<div class="overlay" data-act="overlay"><form class="modal" id="pwform" role="dialog" aria-modal="true" aria-labelledby="mt" novalidate><h2 id="mt">Passwort ändern</h2><div id="pw-msg"></div>' +
      '<div class="form"><label class="full" for="pw-cur">Aktuelles Passwort<input id="pw-cur" type="password" autocomplete="current-password"></label>' +
      '<label class="full" for="pw-new">Neues Passwort (mindestens ' + cfg.minPassword + ' Zeichen)<input id="pw-new" type="password" autocomplete="new-password"></label>' +
      '<label class="full" for="pw-new2">Neues Passwort wiederholen<input id="pw-new2" type="password" autocomplete="new-password"></label></div>' +
      '<div class="sub">Aus Sicherheitsgründen werden danach alle anderen Anmeldungen beendet.</div><div class="actions"><button type="button" class="btn" data-act="close">Abbrechen</button><button type="submit" class="btn primary">Speichern</button></div></form></div>';
    $('#pw-cur').focus();
    $('#pwform').addEventListener('submit', function (e) {
      e.preventDefault();
      var problem = passwordProblem($('#pw-new').value, $('#pw-new2').value);
      if (problem) { $('#pw-msg').innerHTML = notice(problem); return; }
      var btn = $('#pwform button[type=submit]'); btn.disabled = true;
      api('POST', '/api/portal/password', { current: $('#pw-cur').value, password: $('#pw-new').value }).then(function () { $('#layer').innerHTML = ''; toast('Passwort geändert'); })
        .catch(function (err) { btn.disabled = false; $('#pw-msg').innerHTML = notice(err.message); });
    });
  }

  function totalsBlock(x) {
    var t = x.totals;
    return '<div class="sums" style="margin-left:auto"><div><span>Netto</span><span>' + eur(t.subtotal) + '</span></div>' + (x.discount ? '<div><span>Rabatt</span><span>- ' + eur(x.discount) + '</span></div>' : '') +
      '<div><span>MwSt. ' + x.taxRate + ' %</span><span>' + eur(t.tax) + '</span></div><div class="tot"><span>Gesamt</span><span>' + eur(t.total) + '</span></div></div>';
  }
  function itemsTable(x) {
    return '<div class="tablewrap"><table><thead><tr><th>Leistung</th><th class="r">Menge</th><th class="r">Einzelpreis</th><th class="r">Summe</th></tr></thead><tbody>' + x.items.map(function (i) {
      return '<tr><td>' + esc(i.description) + '</td><td class="r num">' + i.quantity.toLocaleString('de-DE') + '</td><td class="r num">' + eur(i.unitPrice) + '</td><td class="r num">' + eur(r2(i.quantity * i.unitPrice)) + '</td></tr>';
    }).join('') + '</tbody></table></div>';
  }

  function invoiceCard(inv) {
    var open = state.open[inv.id], t = inv.totals;
    return '<div class="card doc"><div class="doc-row"><div class="doc-main"><b class="mono">' + esc(inv.number) + '</b> ' + pill(INVOICE, inv.status) +
      '<div class="sub">vom ' + fdate(inv.issueDate) + (inv.status === 'PAID' ? '' : ' · fällig am ' + fdate(inv.dueDate)) + '</div></div>' +
      '<div style="text-align:right"><div class="doc-amount">' + eur(t.total) + '</div>' + (t.balance > 0 && t.paid > 0 ? '<div class="sub">noch offen: ' + eur(t.balance) + '</div>' : '') + '</div>' +
      '<div class="doc-actions"><button class="btn sm" data-act="toggle" data-id="' + esc(inv.id) + '" aria-expanded="' + !!open + '">' + (open ? 'Weniger' : 'Details') + '</button><button class="btn sm primary" data-act="pdf" data-url="/api/portal/invoices/' + esc(inv.id) + '/pdf">PDF herunterladen</button></div></div>' +
      (open ? '<div class="doc-detail">' + itemsTable(inv) + totalsBlock(inv) + (inv.notes ? '<p class="sub" style="margin:0">' + esc(inv.notes) + '</p>' : '') +
        (inv.payments.length ? '<div class="sub"><b>Zahlungen:</b> ' + inv.payments.map(function (p) { return eur(p.amount) + ' am ' + fdate(p.paidAt); }).join(' · ') + '</div>' : '') + '</div>' : '') + '</div>';
  }

  function quoteCard(q) {
    var open = state.open[q.id];
    return '<div class="card doc"><div class="doc-row"><div class="doc-main"><b class="mono">' + esc(q.number) + '</b> ' + pill(QUOTE, q.status) +
      '<div class="sub">vom ' + fdate(q.issueDate) + (q.status === 'SENT' || q.status === 'EXPIRED' ? ' · gültig bis ' + fdate(q.validUntil) : '') + (q.respondedAt ? ' · beantwortet am ' + fdate(q.respondedAt) : '') + '</div></div>' +
      '<div class="doc-amount">' + eur(q.totals.total) + '</div>' +
      '<div class="doc-actions"><button class="btn sm" data-act="toggle" data-id="' + esc(q.id) + '" aria-expanded="' + !!open + '">' + (open ? 'Weniger' : 'Details') + '</button><button class="btn sm" data-act="pdf" data-url="/api/portal/quotes/' + esc(q.id) + '/pdf">PDF herunterladen</button></div></div>' +
      (q.status === 'SENT' ? '<div class="doc-actions"><button class="btn primary" data-act="respond" data-id="' + esc(q.id) + '" data-answer="accept">Angebot annehmen</button><button class="btn" data-act="respond" data-id="' + esc(q.id) + '" data-answer="decline">Ablehnen</button></div>' : '') +
      (open ? '<div class="doc-detail">' + itemsTable(q) + totalsBlock(q) + (q.notes ? '<p class="sub" style="margin:0">' + esc(q.notes) + '</p>' : '') + '</div>' : '') + '</div>';
  }

  /* Preis in der Anzeige: netto, bei Miete je Zeitraum, bei Stundenprodukten je Stunde */
  function priceHtml(p) {
    var unit = p.type === 'RENTAL' ? ' / ' + PERIOD[p.intervalUnit] : p.type === 'HOURLY' ? ' / ' + (p.unit || 'Std.') : (p.unit ? ' / ' + p.unit : '');
    return '<div class="price">' + eur(p.price) + '<small>' + esc(unit) + '</small></div><div class="sub">zzgl. ' + p.taxRate + ' % MwSt.' + (p.setupFee > 0 ? ' · einmalig ' + eur(p.setupFee) + ' Einrichtung' : '') + '</div>';
  }
  function productCard(p) {
    return '<div class="card product"><div><span class="tag">' + TYPE[p.type] + (p.type === 'RENTAL' ? ', ' + RHYTHM[p.intervalUnit] : '') + '</span>' + (p.license ? ' <span class="tag">mit Lizenz</span>' : '') + '</div><h3>' + esc(p.name) + '</h3>' +
      (p.description ? '<div class="desc">' + esc(p.description) + '</div>' : '<div class="desc"></div>') + priceHtml(p) +
      '<button class="btn primary" data-act="order" data-id="' + esc(p.id) + '">Bestellen</button></div>';
  }
  function shopHtml() {
    if (!state.products.length) return '<div class="card empty">Aktuell sind keine Produkte zur Bestellung freigegeben.</div>';
    var cats = state.products, sel = state.shopCat;
    var chips = '<div class="chips"><button class="chip" data-act="shopcat" data-v="" aria-pressed="' + (sel === '') + '">Alle</button>' + cats.map(function (c, i) {
      return '<button class="chip" data-act="shopcat" data-v="' + i + '" aria-pressed="' + (sel === String(i)) + '">' + esc(c.name) + '</button>';
    }).join('') + '</div>';
    var shown = cats.filter(function (c, i) { return sel === '' || sel === String(i); });
    return chips + shown.map(function (c) {
      return '<div class="stack"><div class="cat-head">' + esc(c.name) + '</div>' + (c.description ? '<div class="sub" style="margin-top:-6px">' + esc(c.description) + '</div>' : '') + '<div class="shop-grid">' + c.products.map(productCard).join('') + '</div></div>';
    }).join('');
  }
  function orderCard(o) {
    var t = o.totals, rental = o.productType === 'RENTAL';
    return '<div class="card doc"><div class="doc-row"><div class="doc-main"><b class="mono">' + esc(o.number) + '</b> ' + pill(ORDER, o.status) +
      '<div class="sub">' + qty(o.quantity) + (o.unit ? ' ' + esc(o.unit) : '×') + ' ' + esc(o.productName) + (o.domain ? ' · Domain ' + esc(o.domain) : '') + ' · bestellt am ' + fdate(o.createdAt) + (o.decidedAt && o.status !== 'PENDING' ? ' · ' + (o.status === 'CANCELLED' ? 'storniert' : 'entschieden') + ' am ' + fdate(o.decidedAt) : '') + '</div></div>' +
      '<div style="text-align:right"><div class="doc-amount">' + eur(t.gross) + '</div><div class="sub">brutto' + (rental ? ', erste Zahlung' : o.productType === 'HOURLY' ? ', geschätzt' : '') + '</div></div>' +
      (o.status === 'PENDING' ? '<div class="doc-actions"><button class="btn sm" data-act="order-cancel" data-id="' + esc(o.id) + '">Zurückziehen</button></div>' : '') + '</div>' +
      (rental ? '<div class="sub">Danach ' + eur(t.recurringNet) + ' netto pro ' + PERIOD[o.intervalUnit] + '.</div>' : '') +
      (o.note ? '<div class="sub" style="white-space:pre-wrap"><b>Ihre Anmerkung:</b> ' + esc(o.note) + '</div>' : '') +
      (o.status === 'REJECTED' && o.rejectReason ? '<div class="pay-box"><b>Begründung</b><span>' + esc(o.rejectReason) + '</span></div>' : '') +
      (o.status === 'ACCEPTED' ? '<div class="sub">Wir haben Ihre Bestellung bestätigt. Rechnung und weitere Informationen erhalten Sie von uns.</div>' : '') + '</div>';
  }

  function licenseCard(l) {
    var shown = l.licenseKey ? '<div class="pay-box"><b>Lizenzschlüssel</b><dl><dd><span class="mono" style="font-size:15px">' + esc(l.licenseKey) + '</span> <button class="btn sm" data-act="copy-key" data-text="' + esc(l.licenseKey) + '">Kopieren</button></dd></dl></div>' : '<div class="sub">Ihr Schlüssel erscheint hier, sobald die Rechnung bezahlt ist. Sie bekommen ihn dann auch per E-Mail.</div>';
    var until = l.rental ? (l.status === 'ACTIVE' ? 'läuft, solange Ihre Abo-Rechnungen bezahlt werden' : '') : l.validUntil ? 'gültig bis ' + fdate(l.validUntil) : 'unbefristet';
    return '<div class="card doc"><div class="doc-row"><div class="doc-main"><b>' + esc(l.productName) + '</b> ' + pill(LSTATUS, l.status) +
      '<div class="sub">Domain: <b class="mono">' + esc(l.domain) + '</b>' + (l.subdomains ? ' (inkl. Subdomains)' : '') + (until ? ' · ' + until : '') + '</div></div>' +
      (l.status !== 'REVOKED' ? '<div class="doc-actions"><button class="btn sm" data-act="lic-domain" data-id="' + esc(l.id) + '">Domain ändern</button></div>' : '') + '</div>' + shown + '</div>';
  }
  function licensesHtml() {
    return state.licenses.map(licenseCard).join('') + '<div class="card"><b>So binden Sie die Lizenz ein</b><div class="sub" style="line-height:1.6">Tragen Sie den Lizenzschlüssel in der Einstellung Ihrer Software ein (bzw. wie in deren Anleitung beschrieben). Die Software prüft ihn gelegentlich bei uns. Sie benötigt dafür eine Internetverbindung; kurze Ausfälle überbrückt sie automatisch. Die Lizenz gilt für die genannte Domain; Entwicklungs-Adressen wie <span class="mono">localhost</span> sind erlaubt.</div></div>';
  }
  function domainDialog(l) {
    $('#layer').innerHTML = '<div class="overlay" data-act="overlay"><form class="modal" id="dform" role="dialog" aria-modal="true" aria-labelledby="mt" novalidate><h2 id="mt">Domain ändern</h2>' +
      '<div class="sub">Aktuelle Domain: <b class="mono">' + esc(l.domain) + '</b>. Sie können die Domain noch <b>' + l.changesLeft + 'x</b> selbst ändern' + (l.changesLeft ? '' : ' – danach melden Sie sich bitte bei uns') + '.</div>' +
      '<div class="form"><label class="full" for="ldom">Neue Domain<input id="ldom" type="text" placeholder="meine-neue-seite.de" autocapitalize="off" spellcheck="false" autocomplete="off"></label></div>' +
      '<div id="lmsg"></div><div class="actions"><button type="button" class="btn" data-act="close">Abbrechen</button><button type="submit" class="btn primary"' + (l.changesLeft ? '' : ' disabled') + '>Domain ändern</button></div></form></div>';
    $('#ldom').focus();
    $('#dform').addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = $('#dform button[type=submit]'); btn.disabled = true;
      api('POST', '/api/portal/licenses/' + l.id + '/domain', { domain: $('#ldom').value.trim() }).then(function () { $('#layer').innerHTML = ''; toast('Domain geändert'); return load(); })
        .catch(function (err) { btn.disabled = false; $('#lmsg').innerHTML = notice(err.message); });
    });
  }

  function render() {
    var me = state.me, co = me.company;
    var first = (me.client.name || '').split(/\s+/)[0];
    var openInvoices = state.invoices.filter(function (i) { return i.status !== 'PAID'; });
    var list = state.tab === 'invoices'
      ? (state.invoices.length ? state.invoices.map(invoiceCard).join('') : '<div class="card empty">Noch keine Rechnungen vorhanden.</div>')
      : state.tab === 'quotes'
        ? (state.quotes.length ? state.quotes.map(quoteCard).join('') : '<div class="card empty">Aktuell liegen keine Angebote für Sie vor.</div>')
        : state.tab === 'shop' ? shopHtml()
          : state.tab === 'licenses' ? licensesHtml()
          : (state.orders.length ? state.orders.map(orderCard).join('') : '<div class="card empty">Sie haben noch nichts bestellt. Im Reiter „Produkte“ finden Sie unser Angebot.</div>');
    var pendingOrders = state.orders.filter(function (o) { return o.status === 'PENDING'; }).length;
    var productCount = state.products.reduce(function (n, c) { return n + c.products.length; }, 0);
    var waiting = state.quotes.filter(function (q) { return q.status === 'SENT'; }).length;

    $('#root').innerHTML = '<div class="portal">' +
      '<div class="p-top"><i>' + esc((co.name || 'K').charAt(0).toUpperCase()) + '</i><span>' + esc(co.name) + '</span><span class="p-user">' + (me.account ? '<span class="sub">' + esc(me.account.email) + '</span><button class="btn sm ghost" data-act="password">Passwort ändern</button>' : '') + '<button class="btn sm" data-act="logout">Abmelden</button></span></div>' +
      '<div class="p-hero"><h1>Hallo ' + esc(first) + '</h1><p>Hier finden Sie Ihre Rechnungen und Angebote' + (me.client.company ? ' für ' + esc(me.client.company) : '') + ', laden alles als PDF herunter und bestellen neue Leistungen.</p></div>' +
      '<div class="grid kpis"><div class="card kpi"><span class="l">Offener Betrag</span><span class="v">' + eur(me.summary.open) + '</span><span class="s">' + openInvoices.length + (openInvoices.length === 1 ? ' offene Rechnung' : ' offene Rechnungen') + '</span></div>' +
      (me.summary.overdue > 0 ? '<div class="card kpi bad"><span class="l">Davon überfällig</span><span class="v">' + eur(me.summary.overdue) + '</span><span class="s">Bitte zeitnah überweisen</span></div>' : '<div class="card kpi"><span class="l">Überfällig</span><span class="v">' + eur(0) + '</span><span class="s">Alles im Zeitplan</span></div>') + '</div>' +
      (me.summary.open > 0 && co.iban ? '<div class="pay-box"><b>So bezahlen Sie</b><dl>' + (co.bank ? '<dt>Bank</dt><dd>' + esc(co.bank) + '</dd>' : '') + '<dt>Empfänger</dt><dd>' + esc(co.name) + '</dd><dt>IBAN</dt><dd><span class="mono">' + esc(co.iban) + '</span> <button class="btn sm" data-act="copy" data-text="' + esc(co.iban) + '">Kopieren</button></dd>' + (co.bic ? '<dt>BIC</dt><dd class="mono">' + esc(co.bic) + '</dd>' : '') + '<dt>Verwendungszweck</dt><dd>die Rechnungsnummer, z. B. ' + esc((openInvoices[0] || {}).number || 'RE-…') + '</dd></dl></div>' : '') +
      '<div class="tabs" role="tablist" style="overflow-x:auto"><button role="tab" data-act="tab" data-tab="invoices" aria-selected="' + (state.tab === 'invoices') + '">Rechnungen (' + state.invoices.length + ')</button>' +
      '<button role="tab" data-act="tab" data-tab="quotes" aria-selected="' + (state.tab === 'quotes') + '">Angebote (' + state.quotes.length + ')' + (waiting ? ' <span class="pill info">' + waiting + ' neu</span>' : '') + '</button>' +
      '<button role="tab" data-act="tab" data-tab="shop" aria-selected="' + (state.tab === 'shop') + '">Produkte' + (productCount ? ' (' + productCount + ')' : '') + '</button>' +
      '<button role="tab" data-act="tab" data-tab="orders" aria-selected="' + (state.tab === 'orders') + '">Bestellungen (' + state.orders.length + ')' + (pendingOrders ? ' <span class="pill info">' + pendingOrders + ' offen</span>' : '') + '</button>' +
      (state.licenses.length ? '<button role="tab" data-act="tab" data-tab="licenses" aria-selected="' + (state.tab === 'licenses') + '">Lizenzen (' + state.licenses.length + ')</button>' : '') + '</div>' +
      '<div class="stack">' + list + '</div>' +
      '<div class="p-foot">Fragen? ' + [co.email ? esc(co.email) : '', co.phone ? esc(co.phone) : ''].filter(Boolean).join(' · ') + '<br>' + esc(co.name) + (co.address.length ? ' · ' + co.address.map(esc).join(', ') : '') + '</div></div>';
  }

  function load() {
    return Promise.all([api('GET', '/api/portal/me'), api('GET', '/api/portal/invoices'), api('GET', '/api/portal/quotes'), api('GET', '/api/portal/products'), api('GET', '/api/portal/orders'), api('GET', '/api/portal/licenses')]).then(function (r) {
      state.me = r[0]; state.invoices = r[1]; state.quotes = r[2]; state.products = r[3]; state.orders = r[4]; state.licenses = r[5];
      if (state.tab === 'invoices' && !state.invoices.length && state.quotes.length) state.tab = 'quotes';
      render();
    });
  }

  function downloadPdf(btn, url) {
    btn.disabled = true;
    fetch(url, { headers: { 'X-Portal-Token': token || '' } }).then(function (res) {
      if (!res.ok) throw new Error('Das PDF konnte nicht geladen werden');
      var name = (/filename="([^"]+)"/.exec(res.headers.get('Content-Disposition') || '') || [])[1] || 'Dokument.pdf';
      return res.blob().then(function (blob) { return { blob: blob, name: name }; });
    }).then(function (r) {
      var a = document.createElement('a'); a.href = URL.createObjectURL(r.blob); a.download = r.name; document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
    }).catch(function (e) { toast(e.message); }).then(function () { btn.disabled = false; });
  }

  function confirmAnswer(id, answer) {
    var q = state.quotes.filter(function (x) { return x.id === id; })[0];
    var accept = answer === 'accept';
    $('#layer').innerHTML = '<div class="overlay" data-act="overlay"><div class="modal" role="dialog" aria-modal="true" aria-labelledby="mt"><h2 id="mt">' + (accept ? 'Angebot annehmen?' : 'Angebot ablehnen?') + '</h2>' +
      '<p style="margin:0">' + (accept ? 'Mit Ihrer Annahme beauftragen Sie ' + esc(state.me.company.name) + ' verbindlich mit den Leistungen aus Angebot <b class="mono">' + esc(q.number) + '</b> über <b>' + eur(q.totals.total) + '</b> (brutto).' : 'Möchten Sie das Angebot <b class="mono">' + esc(q.number) + '</b> wirklich ablehnen?') + '</p>' +
      '<div class="actions"><button class="btn" data-act="close">Zurück</button><button class="btn ' + (accept ? 'primary' : 'danger') + '" id="confirm" data-act="confirm" data-id="' + esc(id) + '" data-answer="' + answer + '">' + (accept ? 'Verbindlich annehmen' : 'Ablehnen') + '</button></div></div></div>';
    $('#confirm').focus();
  }

  function findProduct(id) {
    for (var i = 0; i < state.products.length; i++) {
      var hit = state.products[i].products.filter(function (p) { return p.id === id; })[0];
      if (hit) return hit;
    }
    return null;
  }
  /* Gleiche Rundung wie auf dem Server: Positionen auf Cent, Steuer auf Cent */
  function orderTotals(p, q) {
    var line = r2(p.price * q), setup = p.type === 'RENTAL' ? r2(p.setupFee) : 0, net = r2(line + setup), tax = r2(net * p.taxRate / 100);
    return { line: line, setup: setup, net: net, tax: tax, gross: r2(net + tax) };
  }
  function summaryHtml(p, q) {
    var t = orderTotals(p, q);
    if (!(q > 0)) return '';
    return (p.type === 'RENTAL' ? '<div><span>' + eur(p.price) + ' × ' + qty(q) + ' pro ' + PERIOD[p.intervalUnit] + '</span><span>' + eur(t.line) + '</span></div>' + (t.setup > 0 ? '<div><span>Einrichtung (einmalig)</span><span>' + eur(t.setup) + '</span></div>' : '') : '<div><span>' + eur(p.price) + ' × ' + qty(q) + (p.type === 'HOURLY' ? ' Std. (geschätzt)' : '') + '</span><span>' + eur(t.line) + '</span></div>') +
      '<div><span>MwSt. ' + p.taxRate + ' %</span><span>' + eur(t.tax) + '</span></div><div class="tot"><span>' + (p.type === 'RENTAL' ? 'Erste Zahlung (brutto)' : p.type === 'HOURLY' ? 'Geschätzt (brutto)' : 'Gesamt (brutto)') + '</span><span>' + eur(t.gross) + '</span></div>';
  }
  function orderDialog(id) {
    var p = findProduct(id); if (!p) return;
    var label = p.type === 'HOURLY' ? 'Geschätzter Umfang (Stunden)' : p.type === 'RENTAL' ? 'Anzahl' : 'Menge' + (p.unit ? ' (' + p.unit + ')' : '');
    var hint = p.type === 'RENTAL' ? 'Wiederkehrende Leistung, Abrechnung ' + RHYTHM[p.intervalUnit] + '. Wir legen Ihr Abo nach der Bestätigung an.' : p.type === 'HOURLY' ? 'Abgerechnet wird nach tatsächlichem Aufwand zum Stundensatz. Die Stundenzahl ist nur eine Schätzung für die Planung.' : 'Einmaliger Auftrag. Sie erhalten nach der Bestätigung eine Rechnung.';
    $('#layer').innerHTML = '<div class="overlay" data-act="overlay"><form class="modal" id="oform" role="dialog" aria-modal="true" aria-labelledby="mt" novalidate><h2 id="mt">' + esc(p.name) + ' bestellen</h2>' +
      (p.description ? '<p style="margin:0;color:var(--muted);white-space:pre-line">' + esc(p.description) + '</p>' : '') + '<div class="sub">' + esc(hint) + '</div>' +
      '<div class="form"><label for="oqty">' + esc(label) + '<input id="oqty" type="number" inputmode="decimal" min="' + p.minQuantity + '" step="any" value="' + p.minQuantity + '"></label>' +
      (p.license ? '<label class="full" for="odom">Domain für die Lizenz *<input id="odom" type="text" placeholder="meine-seite.de" autocapitalize="off" spellcheck="false" autocomplete="off"></label><div class="sub full">Die Lizenz gilt für diese Domain' + (p.licenseSubdomains ? ' und ihre Subdomains' : '') + '. ' + (p.licensePayFirst && p.price > 0 ? 'Sie wird nach Bezahlung der Rechnung freigeschaltet. ' : '') + 'Sie können die Domain später im Portal ändern.</div>' : '') + '</div>' +
      '<label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;font-weight:600;color:var(--muted)" for="onote">Ihre Wünsche und Anmerkungen (optional)<textarea id="onote" rows="3" maxlength="2000" placeholder="z. B. Wunsch-Domain, Format, Termin" style="font-weight:400;color:var(--fg)"></textarea></label>' +
      '<div class="order-sum" id="osum">' + summaryHtml(p, p.minQuantity) + '</div>' +
      '<div class="sub">Mit „Verbindlich bestellen“ geben Sie eine Bestellung ab. Wir prüfen sie und bestätigen Sie Ihnen per E-Mail. Alle Preise netto zzgl. gesetzlicher MwSt.</div>' +
      '<div class="actions"><button type="button" class="btn" data-act="close">Abbrechen</button><button type="submit" class="btn primary">Verbindlich bestellen</button></div></form></div>';
    $('#oqty').focus();
    $('#oqty').addEventListener('input', function () { $('#osum').innerHTML = summaryHtml(p, parseFloat($('#oqty').value)); });
    $('#oform').addEventListener('submit', function (e) {
      e.preventDefault();
      var q = parseFloat($('#oqty').value);
      if (!(q >= p.minQuantity)) { toast('Mindestmenge: ' + qty(p.minQuantity)); return; }
      if (p.license && !$('#odom').value.trim()) { toast('Bitte geben Sie die Domain für die Lizenz an.'); return; }
      var btn = $('#oform button[type=submit]'); btn.disabled = true;
      api('POST', '/api/portal/orders', { productId: p.id, quantity: q, note: $('#onote').value.trim(), domain: p.license ? $('#odom').value.trim() : undefined }).then(function (o) {
        $('#layer').innerHTML = '';
        state.tab = 'orders';
        toast('Danke! Ihre Bestellung ' + o.number + ' ist eingegangen.');
        return load();
      }).catch(function (err) { btn.disabled = false; toast(err.message); });
    });
  }

  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-act]'); if (!el) return;
    var act = el.dataset.act, id = el.dataset.id;
    if (act === 'overlay') { if (e.target === el) $('#layer').innerHTML = ''; return; }
    if (act === 'close') { $('#layer').innerHTML = ''; return; }
    if (act === 'tab') { state.tab = el.dataset.tab; render(); return; }
    if (act === 'auth-mode') { loadConfig().then(function () { showAuth(el.dataset.mode); }); return; }
    if (act === 'password') { passwordDialog(); return; }
    if (act === 'logout') {
      var done = function () { clearToken(); state = { me: null, invoices: [], quotes: [], products: [], orders: [], licenses: [], tab: 'invoices', open: {}, shopCat: '' }; loadConfig().then(function () { showAuth('login', 'Du bist abgemeldet.', 'ok'); }); };
      api('POST', '/api/portal/logout', {}).then(done, done);
      return;
    }
    if (act === 'shopcat') { state.shopCat = el.dataset.v; render(); return; }
    if (act === 'order') { orderDialog(id); return; }
    if (act === 'lic-domain') { var lic = state.licenses.filter(function (x) { return x.id === id; })[0]; if (lic) domainDialog(lic); return; }
    if (act === 'copy-key') {
      var key = el.dataset.text;
      (navigator.clipboard && navigator.clipboard.writeText ? navigator.clipboard.writeText(key) : Promise.reject()).then(function () { toast('Schlüssel kopiert'); }, function () { toast('Bitte den Schlüssel von Hand markieren und kopieren'); });
      return;
    }
    if (act === 'order-cancel') {
      $('#layer').innerHTML = '<div class="overlay" data-act="overlay"><div class="modal" role="dialog" aria-modal="true" aria-labelledby="mt"><h2 id="mt">Bestellung zurückziehen?</h2><p style="margin:0">Die Bestellung wird storniert. Das können Sie nur tun, solange wir sie noch nicht bestätigt haben.</p><div class="actions"><button class="btn" data-act="close">Zurück</button><button class="btn danger" id="confirm" data-act="cancel-confirm" data-id="' + esc(id) + '">Zurückziehen</button></div></div></div>';
      $('#confirm').focus();
      return;
    }
    if (act === 'cancel-confirm') {
      el.disabled = true;
      api('POST', '/api/portal/orders/' + id + '/cancel').then(function () { $('#layer').innerHTML = ''; toast('Bestellung storniert'); return load(); }).catch(function (err) { $('#layer').innerHTML = ''; toast(err.message); load().catch(function () {}); });
      return;
    }
    if (act === 'toggle') { state.open[id] = !state.open[id]; render(); return; }
    if (act === 'pdf') { downloadPdf(el, el.dataset.url); return; }
    if (act === 'respond') { confirmAnswer(id, el.dataset.answer); return; }
    if (act === 'copy') {
      var text = el.dataset.text;
      (navigator.clipboard && navigator.clipboard.writeText ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () { toast('IBAN kopiert'); }, function () { toast('Bitte die IBAN von Hand markieren und kopieren'); });
      return;
    }
    if (act === 'confirm') {
      el.disabled = true;
      api('POST', '/api/portal/quotes/' + id + '/' + el.dataset.answer).then(function () {
        $('#layer').innerHTML = '';
        toast(el.dataset.answer === 'accept' ? 'Vielen Dank! Das Angebot wurde angenommen.' : 'Das Angebot wurde abgelehnt.');
        return load();
      }).catch(function (err) { $('#layer').innerHTML = ''; toast(err.message); load().catch(function () {}); });
    }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') $('#layer').innerHTML = ''; });

  if (pending) {
    loadConfig().then(function () {
      if (pending.kind === 'reset') return showReset();
      return api('POST', '/api/portal/verify-info', { token: pending.token }).then(showVerify).catch(function (err) {
        showAuth(cfg.registration ? 'register' : 'login', err.message);
      });
    });
  } else if (!token) {
    loadConfig().then(function () { showAuth('login'); });
  } else {
    load().catch(function (err) {
      if (err.status === 401) {
        clearToken();
        loadConfig().then(function () { showAuth('login', 'Dein Zugang ist abgelaufen oder der Link ist nicht mehr gültig. Bitte melde dich mit deinem Konto an oder fordere bei uns einen neuen Link an.'); });
      } else if (err.status === 429) showError('Bitte einen Moment warten', err.message);
      else showError('Das hat nicht geklappt', err.message);
    });
  }
})();
