(function () {
  'use strict';
  // Bestätigungsdialog für Formulare mit data-confirm
  document.addEventListener('submit', function (e) {
    var m = e.target.getAttribute && e.target.getAttribute('data-confirm');
    if (m && !window.confirm(m)) e.preventDefault();
  });

  var form = document.getElementById('invform');
  if (!form) return;
  var body = document.querySelector('#items tbody');
  var small = form.getAttribute('data-small') === '1';

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
    tr.querySelectorAll('textarea').forEach(function (t) { t.value = ''; });
    tr.querySelector('.qty').value = '1'; tr.querySelector('.price').value = '0,00';
    body.appendChild(tr); tr.querySelector('textarea').focus(); recalc();
  });
  body.querySelectorAll('textarea').forEach(grow);
  recalc();
})();
