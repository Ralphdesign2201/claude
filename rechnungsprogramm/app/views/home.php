<?php $brand = csetting('brand_name', APP_NAME);
$feat = [
  ['Rechnungen in 2 Minuten', 'Positionen, Mengen, Steuersätze – Nummer, PDF und Zahlungsstatus laufen automatisch.'],
  ['Angebot → Rechnung', 'Aus dem Angebot wird mit einem Klick die Rechnung. Dazu Lieferscheine und Mahnwesen.'],
  ['E-Rechnung inklusive', 'ZUGFeRD und XRechnung – geprüft und bereit für die E-Rechnungspflicht.'],
  ['Steuerberater-Export', 'DATEV-Buchungsstapel und CSV auf Knopfdruck, PDF direkt per E-Mail an den Kunden.'],
  ['Team & Rechte', 'Mehrere Benutzer mit eigenen Rollen: Büro darf alles, Monteure nur lesen.'],
  ['Ihre Daten, Ihre Kontrolle', 'Jeder Betrieb hat eine eigene, getrennte Datenbank. Backups jederzeit als Download.'],
]; ?>
<section class="hero"><div class="pub-wrap hero-in">
  <div>
    <span class="pill">Version <?= e(app_version()) ?> · <?= e($trial) ?> Tage kostenlos testen</span>
    <h1><?= e(csetting('landing_headline') ?: 'Rechnungen schreiben, wo andere noch Excel öffnen.') ?></h1>
    <p class="lead"><?= e(csetting('landing_sub') ?: $brand . ' ist das schlanke Rechnungsprogramm für Handwerksbetriebe: Angebote, Rechnungen, E-Rechnung, Mahnwesen und DATEV-Export – direkt im Browser, ohne Installation.') ?></p>
    <div class="cta"><?php if ($signup): ?><a class="btn primary big" href="<?= e(url('signup')) ?>"><?= $trial ? e($trial) . ' Tage kostenlos testen' : 'Jetzt starten' ?></a><?php endif; ?><a class="btn big ghost-w" href="<?= e(url('login')) ?>">Anmelden</a></div>
    <p class="muted small">Keine Kreditkarte für den Test nötig. Jederzeit kündbar.</p>
  </div>
  <div class="mock big" aria-hidden="true">
    <div class="mock-top"><span>Rechnung</span><i>Beispiel</i></div>
    <div class="mock-row"><span class="bar w60"></span><span class="bar w20"></span></div><div class="mock-row"><span class="bar w50"></span><span class="bar w20"></span></div>
    <div class="mock-row"><span class="bar w70"></span><span class="bar w20"></span></div><div class="mock-row"><span class="bar w50"></span><span class="bar w20"></span></div>
    <div class="mock-sum"><span>Gesamt</span><strong>bezahlt ✓</strong></div>
  </div>
</div></section>

<section class="pub-wrap sect"><h2>Alles, was ein Handwerksbetrieb braucht</h2>
<div class="fgrid"><?php foreach ($feat as [$t, $d]): ?><div class="fcard"><h3><?= e($t) ?></h3><p><?= e($d) ?></p></div><?php endforeach; ?></div></section>

<section class="pub-wrap sect" id="preise"><h2>Einfache Preise</h2>
<p class="center muted">Alle Tarife mit <?= e($trial) ?> Tagen Testphase. Monatlich kündbar.</p>
<div class="pgrid"><?php foreach ($plans as $p): ?>
<div class="pcard"><h3><?= e($p['name']) ?></h3><p class="muted"><?= e($p['description']) ?></p>
<div class="price"><?= e(money_c((int)$p['price_cents'], $p['currency'])) ?><small> / <?= $p['interval_unit'] === 'year' ? 'Jahr' : 'Monat' ?></small></div>
<ul class="plist"><li><?= (int)$p['max_users'] > 0 ? e((int)$p['max_users']) . ' Benutzer' : 'Unbegrenzt Benutzer' ?></li><li><?= (int)$p['max_invoices'] > 0 ? 'bis ' . e((int)$p['max_invoices']) . ' Rechnungen / Monat' : 'Unbegrenzt Rechnungen' ?></li><li>Angebote, Lieferscheine, Mahnwesen</li><li>E-Rechnung &amp; DATEV-Export</li></ul>
<?php if ($signup): ?><a class="btn primary" href="<?= e(url('signup')) ?>">Kostenlos testen</a><?php endif; ?></div>
<?php endforeach; ?></div>
<p class="center muted small">Bezahlung per Kreditkarte oder PayPal. Preise <?= e(csetting('price_note', 'inkl. gesetzlicher MwSt., soweit anfallend')) ?>.</p></section>
