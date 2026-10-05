(function () {
  'use strict';

  var $ = function (s, el) { return (el || document).querySelector(s); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var eur = function (n) { return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(n || 0); };
  var fdate = function (iso) { return iso ? new Date(iso).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '–'; };
  var r2 = function (n) { return Math.round(n * 100) / 100; };

  var INVOICE = { SENT: ['Offen', 'info'], OVERDUE: ['Überfällig', 'bad'], PAID: ['Bezahlt', 'good'] };
  var QUOTE = { SENT: ['Wartet auf Ihre Antwort', 'info'], ACCEPTED: ['Angenommen', 'good'], DECLINED: ['Abgelehnt', 'neutral'], EXPIRED: ['Abgelaufen', 'warn'] };
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

  var state = { me: null, invoices: [], quotes: [], tab: 'invoices', open: {} };

  function api(method, path) {
    return fetch(path, { method: method, headers: { 'X-Portal-Token': token || '' } }).catch(function () { throw new Error('Keine Verbindung zum Server'); }).then(function (res) {
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

  function render() {
    var me = state.me, co = me.company;
    var first = (me.client.name || '').split(/\s+/)[0];
    var openInvoices = state.invoices.filter(function (i) { return i.status !== 'PAID'; });
    var list = state.tab === 'invoices'
      ? (state.invoices.length ? state.invoices.map(invoiceCard).join('') : '<div class="card empty">Noch keine Rechnungen vorhanden.</div>')
      : (state.quotes.length ? state.quotes.map(quoteCard).join('') : '<div class="card empty">Aktuell liegen keine Angebote für Sie vor.</div>');
    var waiting = state.quotes.filter(function (q) { return q.status === 'SENT'; }).length;

    $('#root').innerHTML = '<div class="portal">' +
      '<div class="p-top"><i>' + esc((co.name || 'K').charAt(0).toUpperCase()) + '</i><span>' + esc(co.name) + '</span></div>' +
      '<div class="p-hero"><h1>Hallo ' + esc(first) + '</h1><p>Hier finden Sie Ihre Rechnungen und Angebote' + (me.client.company ? ' für ' + esc(me.client.company) : '') + '. Alles können Sie als PDF herunterladen.</p></div>' +
      '<div class="grid kpis"><div class="card kpi"><span class="l">Offener Betrag</span><span class="v">' + eur(me.summary.open) + '</span><span class="s">' + openInvoices.length + (openInvoices.length === 1 ? ' offene Rechnung' : ' offene Rechnungen') + '</span></div>' +
      (me.summary.overdue > 0 ? '<div class="card kpi bad"><span class="l">Davon überfällig</span><span class="v">' + eur(me.summary.overdue) + '</span><span class="s">Bitte zeitnah überweisen</span></div>' : '<div class="card kpi"><span class="l">Überfällig</span><span class="v">' + eur(0) + '</span><span class="s">Alles im Zeitplan</span></div>') + '</div>' +
      (me.summary.open > 0 && co.iban ? '<div class="pay-box"><b>So bezahlen Sie</b><dl>' + (co.bank ? '<dt>Bank</dt><dd>' + esc(co.bank) + '</dd>' : '') + '<dt>Empfänger</dt><dd>' + esc(co.name) + '</dd><dt>IBAN</dt><dd><span class="mono">' + esc(co.iban) + '</span> <button class="btn sm" data-act="copy" data-text="' + esc(co.iban) + '">Kopieren</button></dd>' + (co.bic ? '<dt>BIC</dt><dd class="mono">' + esc(co.bic) + '</dd>' : '') + '<dt>Verwendungszweck</dt><dd>die Rechnungsnummer, z. B. ' + esc((openInvoices[0] || {}).number || 'RE-…') + '</dd></dl></div>' : '') +
      '<div class="tabs" role="tablist"><button role="tab" data-act="tab" data-tab="invoices" aria-selected="' + (state.tab === 'invoices') + '">Rechnungen (' + state.invoices.length + ')</button>' +
      '<button role="tab" data-act="tab" data-tab="quotes" aria-selected="' + (state.tab === 'quotes') + '">Angebote (' + state.quotes.length + ')' + (waiting ? ' <span class="pill info">' + waiting + ' neu</span>' : '') + '</button></div>' +
      '<div class="stack">' + list + '</div>' +
      '<div class="p-foot">Fragen? ' + [co.email ? esc(co.email) : '', co.phone ? esc(co.phone) : ''].filter(Boolean).join(' · ') + '<br>' + esc(co.name) + (co.address.length ? ' · ' + co.address.map(esc).join(', ') : '') + '</div></div>';
  }

  function load() {
    return Promise.all([api('GET', '/api/portal/me'), api('GET', '/api/portal/invoices'), api('GET', '/api/portal/quotes')]).then(function (r) {
      state.me = r[0]; state.invoices = r[1]; state.quotes = r[2];
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

  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-act]'); if (!el) return;
    var act = el.dataset.act, id = el.dataset.id;
    if (act === 'overlay') { if (e.target === el) $('#layer').innerHTML = ''; return; }
    if (act === 'close') { $('#layer').innerHTML = ''; return; }
    if (act === 'tab') { state.tab = el.dataset.tab; render(); return; }
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
