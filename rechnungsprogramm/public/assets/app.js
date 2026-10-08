(function () {
  'use strict';
  // Bestätigungsdialog für Formulare mit data-confirm
  document.addEventListener('submit', function (e) {
    var m = e.target.getAttribute && e.target.getAttribute('data-confirm');
    if (m && !window.confirm(m)) e.preventDefault();
  });

  // Mahngebühr je nach Stufe vorbelegen
  var lv = document.getElementById('remlevel');
  if (lv) lv.addEventListener('change', function () {
    var f = document.getElementById('remform');
    document.getElementById('remfee').value = this.value === '2' ? f.getAttribute('data-fee2') : this.value === '3' ? f.getAttribute('data-fee3') : '0,00';
  });

  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('button[data-confirm]');
    if (b && !window.confirm(b.getAttribute('data-confirm'))) e.preventDefault();
  });

  // Firmen-ID aus dem Firmennamen vorschlagen (Registrierung)
  var suC = document.getElementById('su_company'), suS = document.getElementById('su_slug');
  if (suC && suS) {
    var touched = suS.value !== '';
    suS.addEventListener('input', function () { touched = true; });
    suC.addEventListener('input', function () {
      if (touched) return;
      suS.value = suC.value.toLowerCase().replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40);
    });
  }

  var form = document.getElementById('invform') || document.getElementById('delform');
  if (!form) return;
  var body = document.querySelector('#items tbody');
  var small = form.getAttribute('data-small') === '1';
  var hasPrice = !!form.querySelector('.price');

  function num(s) {
    s = String(s || '').replace(/[€\s]/g, '');
    if (s.indexOf(',') >= 0) s = s.replace(/\./g, '');
    s = s.replace(',', '.');
    var n = parseFloat(s);
    return isNaN(n) ? 0 : n;
  }
  function fmt(c) { return (c / 100).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function grow(t) { t.style.height = 'auto'; t.style.height = Math.max(36, t.scrollHeight) + 'px'; }

  function recalc() {
    if (!hasPrice) return;
    var net = 0, byRate = {};
    body.querySelectorAll('tr.item').forEach(function (tr) {
      var q = num(tr.querySelector('.qty').value), p = Math.round(num(tr.querySelector('.price').value) * 100);
      var line = Math.round(q * p), v = small ? 0 : num(tr.querySelector('.vat').value);
      tr.querySelector('.line').textContent = fmt(line);
      net += line; byRate[v] = (byRate[v] || 0) + line;
    });
    var vat = 0;
    Object.keys(byRate).forEach(function (r) { vat += Math.round(byRate[r] * parseFloat(r) / 100); });
    document.getElementById('t-net').textContent = fmt(net) + ' €';
    var tv = document.getElementById('t-vat'); if (tv) tv.textContent = fmt(vat) + ' €';
    document.getElementById('t-gross').textContent = fmt(net + vat) + ' €';
  }

  form.addEventListener('input', function (e) { if (e.target.tagName === 'TEXTAREA' && e.target.name === 'description[]') grow(e.target); recalc(); });
  form.addEventListener('change', recalc);
  form.addEventListener('click', function (e) {
    if (e.target.classList.contains('rm')) {
      if (body.querySelectorAll('tr.item').length > 1) { e.target.closest('tr').remove(); recalc(); }
    }
  });
  document.getElementById('addrow').addEventListener('click', function () {
    var rows = body.querySelectorAll('tr.item'), tr = rows[rows.length - 1].cloneNode(true);
    tr.querySelectorAll('textarea').forEach(function (t) { t.value = ''; t.style.height = ''; });
    tr.querySelector('.qty').value = '1'; if (hasPrice) tr.querySelector('.price').value = '0,00';
    body.appendChild(tr); tr.querySelector('textarea').focus(); recalc();
  });
  body.querySelectorAll('textarea').forEach(grow);
  recalc();
})();
