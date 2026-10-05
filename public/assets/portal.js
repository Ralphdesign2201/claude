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
  var qty = function (n) { return Number(n).toLocaleString('de-DE', { maximumFractionDigits: 2 }); };
  var pill = function (map, key) { var m = map[key] || [key, 'neutral']; return '<span class="pill ' + m[1] + '">' + esc(m[0]) + '</span>'; };

  /* Der Zugangsschlüssel steht im Fragment (#…) der Adresse und wird nie an den Server als URL gesendet. */
  var KEY = 'portal-token';
  var token = null;
  try {
    var hash = decodeURIComponent((location.hash || '').replace(/^#/, '')).trim();
    if (/^[A-Za-z0-9_-]{20,128}$/.test(hash)) { sessionStorage.setItem(KEY, hash); token = hash; }
    else token = sessionStorage.getItem(KEY);
  } catch (e) { /* Speicher nicht verfügbar */ }
  if (!token) {
    var h = (location.hash || '').replace(/^#/, '');
    if (/^[A-Za-z0-9_-]{20,128}$/.test(h)) token = h;
  }
  if (location.hash) { try { history.replaceState(null, '', location.pathname); } catch (e) { /* ignorieren */ } }

  var state = { me: null, invoices: [], quotes: [], products: [], orders: [], tab: 'invoices', open: {}, shopCat: '' };

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
    return '<div class="card product"><div><span class="tag">' + TYPE[p.type] + (p.type === 'RENTAL' ? ', ' + RHYTHM[p.intervalUnit] : '') + '</span></div><h3>' + esc(p.name) + '</h3>' +
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
      '<div class="sub">' + qty(o.quantity) + (o.unit ? ' ' + esc(o.unit) : '×') + ' ' + esc(o.productName) + ' · bestellt am ' + fdate(o.createdAt) + (o.decidedAt && o.status !== 'PENDING' ? ' · ' + (o.status === 'CANCELLED' ? 'storniert' : 'entschieden') + ' am ' + fdate(o.decidedAt) : '') + '</div></div>' +
      '<div style="text-align:right"><div class="doc-amount">' + eur(t.gross) + '</div><div class="sub">brutto' + (rental ? ', erste Zahlung' : o.productType === 'HOURLY' ? ', geschätzt' : '') + '</div></div>' +
      (o.status === 'PENDING' ? '<div class="doc-actions"><button class="btn sm" data-act="order-cancel" data-id="' + esc(o.id) + '">Zurückziehen</button></div>' : '') + '</div>' +
      (rental ? '<div class="sub">Danach ' + eur(t.recurringNet) + ' netto pro ' + PERIOD[o.intervalUnit] + '.</div>' : '') +
      (o.note ? '<div class="sub" style="white-space:pre-wrap"><b>Ihre Anmerkung:</b> ' + esc(o.note) + '</div>' : '') +
      (o.status === 'REJECTED' && o.rejectReason ? '<div class="pay-box"><b>Begründung</b><span>' + esc(o.rejectReason) + '</span></div>' : '') +
      (o.status === 'ACCEPTED' ? '<div class="sub">Wir haben Ihre Bestellung bestätigt. Rechnung und weitere Informationen erhalten Sie von uns.</div>' : '') + '</div>';
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
          : (state.orders.length ? state.orders.map(orderCard).join('') : '<div class="card empty">Sie haben noch nichts bestellt. Im Reiter „Produkte“ finden Sie unser Angebot.</div>');
    var pendingOrders = state.orders.filter(function (o) { return o.status === 'PENDING'; }).length;
    var productCount = state.products.reduce(function (n, c) { return n + c.products.length; }, 0);
    var waiting = state.quotes.filter(function (q) { return q.status === 'SENT'; }).length;

    $('#root').innerHTML = '<div class="portal">' +
      '<div class="p-top"><i>' + esc((co.name || 'K').charAt(0).toUpperCase()) + '</i><span>' + esc(co.name) + '</span></div>' +
      '<div class="p-hero"><h1>Hallo ' + esc(first) + '</h1><p>Hier finden Sie Ihre Rechnungen und Angebote' + (me.client.company ? ' für ' + esc(me.client.company) : '') + ', laden alles als PDF herunter und bestellen neue Leistungen.</p></div>' +
      '<div class="grid kpis"><div class="card kpi"><span class="l">Offener Betrag</span><span class="v">' + eur(me.summary.open) + '</span><span class="s">' + openInvoices.length + (openInvoices.length === 1 ? ' offene Rechnung' : ' offene Rechnungen') + '</span></div>' +
      (me.summary.overdue > 0 ? '<div class="card kpi bad"><span class="l">Davon überfällig</span><span class="v">' + eur(me.summary.overdue) + '</span><span class="s">Bitte zeitnah überweisen</span></div>' : '<div class="card kpi"><span class="l">Überfällig</span><span class="v">' + eur(0) + '</span><span class="s">Alles im Zeitplan</span></div>') + '</div>' +
      (me.summary.open > 0 && co.iban ? '<div class="pay-box"><b>So bezahlen Sie</b><dl>' + (co.bank ? '<dt>Bank</dt><dd>' + esc(co.bank) + '</dd>' : '') + '<dt>Empfänger</dt><dd>' + esc(co.name) + '</dd><dt>IBAN</dt><dd><span class="mono">' + esc(co.iban) + '</span> <button class="btn sm" data-act="copy" data-text="' + esc(co.iban) + '">Kopieren</button></dd>' + (co.bic ? '<dt>BIC</dt><dd class="mono">' + esc(co.bic) + '</dd>' : '') + '<dt>Verwendungszweck</dt><dd>die Rechnungsnummer, z. B. ' + esc((openInvoices[0] || {}).number || 'RE-…') + '</dd></dl></div>' : '') +
      '<div class="tabs" role="tablist" style="overflow-x:auto"><button role="tab" data-act="tab" data-tab="invoices" aria-selected="' + (state.tab === 'invoices') + '">Rechnungen (' + state.invoices.length + ')</button>' +
      '<button role="tab" data-act="tab" data-tab="quotes" aria-selected="' + (state.tab === 'quotes') + '">Angebote (' + state.quotes.length + ')' + (waiting ? ' <span class="pill info">' + waiting + ' neu</span>' : '') + '</button>' +
      '<button role="tab" data-act="tab" data-tab="shop" aria-selected="' + (state.tab === 'shop') + '">Produkte' + (productCount ? ' (' + productCount + ')' : '') + '</button>' +
      '<button role="tab" data-act="tab" data-tab="orders" aria-selected="' + (state.tab === 'orders') + '">Bestellungen (' + state.orders.length + ')' + (pendingOrders ? ' <span class="pill info">' + pendingOrders + ' offen</span>' : '') + '</button></div>' +
      '<div class="stack">' + list + '</div>' +
      '<div class="p-foot">Fragen? ' + [co.email ? esc(co.email) : '', co.phone ? esc(co.phone) : ''].filter(Boolean).join(' · ') + '<br>' + esc(co.name) + (co.address.length ? ' · ' + co.address.map(esc).join(', ') : '') + '</div></div>';
  }

  function load() {
    return Promise.all([api('GET', '/api/portal/me'), api('GET', '/api/portal/invoices'), api('GET', '/api/portal/quotes'), api('GET', '/api/portal/products'), api('GET', '/api/portal/orders')]).then(function (r) {
      state.me = r[0]; state.invoices = r[1]; state.quotes = r[2]; state.products = r[3]; state.orders = r[4];
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
      '<div class="form"><label for="oqty">' + esc(label) + '<input id="oqty" type="number" inputmode="decimal" min="' + p.minQuantity + '" step="any" value="' + p.minQuantity + '"></label></div>' +
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
      var btn = $('#oform button[type=submit]'); btn.disabled = true;
      api('POST', '/api/portal/orders', { productId: p.id, quantity: q, note: $('#onote').value.trim() }).then(function (o) {
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
    if (act === 'shopcat') { state.shopCat = el.dataset.v; render(); return; }
    if (act === 'order') { orderDialog(id); return; }
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

  if (!token) {
    showError('Zugang nicht möglich', 'Bitte öffnen Sie den persönlichen Link aus Ihrer E-Mail. Ohne diesen Link ist kein Zugriff möglich.');
  } else {
    load().catch(function (err) {
      if (err.status === 401) showError('Dieser Link ist nicht mehr gültig', 'Der Zugangslink ist abgelaufen oder wurde ersetzt. Bitte fordern Sie bei uns einen neuen Link an.');
      else if (err.status === 429) showError('Bitte einen Moment warten', err.message);
      else showError('Das hat nicht geklappt', err.message);
    });
  }
})();
