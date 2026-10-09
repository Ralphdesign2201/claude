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

  // Seitenleiste (mobil) ein-/ausblenden
  var burger = document.getElementById('burger');
  if (burger) {
    burger.addEventListener('click', function () { document.body.classList.toggle('nav-open'); });
    document.addEventListener('click', function (e) { if (document.body.classList.contains('nav-open') && !e.target.closest('#sidebar') && e.target !== burger) document.body.classList.remove('nav-open'); });
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
  // ---- Katalog-Auswahl direkt im Beschreibungsfeld ----
  var catEl = document.getElementById('catdata'), catalog = [];
  try { if (catEl) catalog = JSON.parse(catEl.getAttribute('data-catalog') || '[]'); } catch (err) { catalog = []; }
  var catPrice = catEl && catEl.getAttribute('data-price') === '1';
  var drop = null, dropTa = null, dropItems = [], dropIdx = -1;
  function norm(s) { return String(s || '').toLowerCase(); }
  function closeDrop() { if (drop) { drop.style.display = 'none'; drop.innerHTML = ''; } dropTa = null; dropItems = []; dropIdx = -1; if (document.activeElement && document.activeElement.removeAttribute) document.activeElement.removeAttribute('aria-activedescendant'); }
  function search(q) {
    q = norm(q).split('\n')[0].trim();
    var res = [];
    for (var i = 0; i < catalog.length; i++) {
      var c = catalog[i], n = norm(c.n), nm = norm(c.name), score = -1;
      if (q === '') score = 5;
      else if (n === q) score = 0;
      else if (n.indexOf(q) === 0) score = 1;
      else if (nm.indexOf(q) === 0) score = 2;
      else if (nm.indexOf(q) >= 0 || n.indexOf(q) >= 0) score = 3;
      else if (norm(c.d).indexOf(q) >= 0) score = 4;
      if (score >= 0) res.push([score, i]);
    }
    res.sort(function (a, b) { return a[0] - b[0] || a[1] - b[1]; });
    return res.slice(0, 8).map(function (r) { return catalog[r[1]]; });
  }
  function showDrop(ta) {
    if (!catalog.length) return;
    var items = search(ta.value);
    if (!drop) { drop = document.createElement('div'); drop.className = 'catdrop'; drop.id = 'catdrop'; drop.setAttribute('role', 'listbox'); document.body.appendChild(drop); }
    if (!items.length) { closeDrop(); return; }
    dropTa = ta; dropItems = items; dropIdx = -1; drop.innerHTML = '';
    items.forEach(function (c, i) {
      var d = document.createElement('div'); d.className = 'catopt'; d.id = 'catopt' + i; d.setAttribute('role', 'option');
      var k = document.createElement('span'); k.className = 'badge ' + (c.k === 'Artikel' ? 'article' : 'service'); k.textContent = c.k;
      var t = document.createElement('span'); t.className = 'cat-t'; t.textContent = (c.n ? c.n + ' · ' : '') + c.name;
      var m = document.createElement('span'); m.className = 'cat-m'; m.textContent = (catPrice ? c.p + ' €' : '') + (c.u ? (catPrice ? ' / ' : '') + c.u : '');
      d.appendChild(k); d.appendChild(t); d.appendChild(m);
      d.addEventListener('mousedown', function (e) { e.preventDefault(); chooseCatalog(i); });
      drop.appendChild(d);
    });
    var r = ta.getBoundingClientRect();
    drop.style.left = (r.left + window.pageXOffset) + 'px'; drop.style.top = (r.bottom + window.pageYOffset + 2) + 'px'; drop.style.minWidth = Math.max(r.width, 320) + 'px'; drop.style.display = 'block';
  }
  function markDrop(i) {
    if (!drop) return;
    var els = drop.querySelectorAll('.catopt'); if (!els.length) return;
    dropIdx = (i + els.length) % els.length;
    els.forEach(function (el, k) { el.classList.toggle('on', k === dropIdx); });
    if (dropTa) dropTa.setAttribute('aria-activedescendant', 'catopt' + dropIdx);
  }
  function chooseCatalog(i) {
    var c = dropItems[i], ta = dropTa; if (!c || !ta) return;
    var tr = ta.closest('tr');
    ta.value = c.name + (c.d ? '\n' + c.d : ''); grow(ta);
    var q = tr.querySelector('.qty'); if (q && num(q.value) === 0) q.value = '1';
    var u = tr.querySelector('.unit'); if (u) u.value = c.u;
    if (hasPrice && catPrice) {
      tr.querySelector('.price').value = c.p;
      var v = tr.querySelector('.vat'); if (v) {
        var want = small ? '0' : c.v, has = false;
        for (var k = 0; k < v.options.length; k++) if (v.options[k].value === want) has = true;
        if (!has) { var o = document.createElement('option'); o.value = want; o.textContent = want; v.appendChild(o); }
        v.value = want;
      }
    }
    closeDrop(); recalc();
    if (q) { q.focus(); if (q.select) q.select(); }
  }
  if (catalog.length) {
    form.addEventListener('input', function (e) { if (e.target.tagName === 'TEXTAREA' && e.target.name === 'description[]') showDrop(e.target); });
    form.addEventListener('focusin', function (e) { if (e.target.tagName === 'TEXTAREA' && e.target.name === 'description[]' && e.target.value.trim() === '') showDrop(e.target); });
    form.addEventListener('keydown', function (e) {
      if (!drop || drop.style.display !== 'block' || e.target !== dropTa) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); markDrop(dropIdx + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); markDrop(dropIdx < 0 ? dropItems.length - 1 : dropIdx - 1); }
      else if ((e.key === 'Enter' || e.key === 'Tab') && dropIdx >= 0) { e.preventDefault(); chooseCatalog(dropIdx); }
      else if (e.key === 'Escape') { e.preventDefault(); closeDrop(); }
    });
    form.addEventListener('focusout', function () { setTimeout(function () { if (dropTa && document.activeElement !== dropTa) closeDrop(); }, 120); });
    document.addEventListener('mousedown', function (e) { if (drop && !e.target.closest('#catdrop') && e.target !== dropTa) closeDrop(); });
    window.addEventListener('resize', closeDrop);
  }
  document.getElementById('addrow').addEventListener('click', function () {
    var rows = body.querySelectorAll('tr.item'), tr = rows[rows.length - 1].cloneNode(true);
    tr.querySelectorAll('textarea').forEach(function (t) { t.value = ''; t.style.height = ''; });
    tr.querySelector('.qty').value = '1'; if (hasPrice) tr.querySelector('.price').value = '0,00';
    body.appendChild(tr); tr.querySelector('textarea').focus(); recalc();
  });
  body.querySelectorAll('textarea').forEach(grow);
  recalc();
})();
