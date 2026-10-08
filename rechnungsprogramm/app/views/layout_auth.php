<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?> – <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(asset_url('app.css')) ?>">
</head>
<body class="auth">
<div class="split">
  <section class="promo">
    <div class="promo-in">
<?php $sa = ($GLOBALS['__auth_variant'] ?? '') === 'sa'; $brand = is_saas() ? csetting('brand_name', APP_NAME) : APP_NAME; ?>
      <div class="logo-mark" aria-hidden="true"><svg viewBox="0 0 48 48" width="44" height="44"><rect width="48" height="48" rx="12" fill="currentColor" opacity=".18"/><path d="M14 12h14l8 8v16a2 2 0 0 1-2 2H14a2 2 0 0 1-2-2V14a2 2 0 0 1 2-2z" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linejoin="round"/><path d="M28 12v8h8M17 27h14M17 32h9" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" fill="none"/></svg><span><?= e($brand) ?></span><span class="ver">v<?= e(app_version()) ?></span></div>
<?php if ($sa): ?>
      <h2>Plattform-<br>Verwaltung</h2>
      <p class="lead">Mandanten, Tarife, Zahlungen und Updates – alles an einem Ort. Zugriff nur für Superadmins, geschützt durch Zwei-Faktor-Anmeldung.</p>
<?php else: ?>
      <h2><?= e(APP_TAGLINE) ?>.</h2>
      <p class="lead"><?= e($brand) ?> – Angebote, Rechnungen, Lieferscheine und Mahnwesen in einem Programm. Fertig für die E-Rechnung, mit DATEV-Export und PDF direkt per E-Mail.</p>
      <ul class="feat">
        <li><b>Angebot → Rechnung</b> in einem Klick, dazu Lieferschein und Mahnwesen</li>
        <li><b>E-Rechnung</b> ZUGFeRD &amp; XRechnung – bereit für die Pflicht</li>
        <li><b>DATEV-Export</b> für den Steuerberater, PDF per E-Mail raus</li>
        <li><b>Sicher</b>: Rollen &amp; Rechte, Zwei-Faktor, automatische Backups</li>
      </ul>
      <div class="mock" aria-hidden="true">
        <div class="mock-top"><span>Rechnung</span><i>Beispiel</i></div>
        <div class="mock-row"><span class="bar w60"></span><span class="bar w20"></span></div>
        <div class="mock-row"><span class="bar w50"></span><span class="bar w20"></span></div>
        <div class="mock-row"><span class="bar w70"></span><span class="bar w20"></span></div>
        <div class="mock-sum"><span>Gesamt</span><strong>bezahlt ✓</strong></div>
      </div>
<?php if (is_saas() && csetting('signup_open', '1') === '1'): ?><p class="promo-cta"><a class="btn big ghost-w" href="<?= e(url('signup')) ?>"><?= (int)csetting('trial_days', '14') ?> Tage kostenlos testen</a></p><?php endif; ?>
<?php endif; ?>
    </div>
  </section>
  <section class="panel">
    <div class="panel-in">
      <?php foreach (flash() ?? [] as [$t, $m]): ?><div class="msg <?= e($t) ?>"><?= e($m) ?></div><?php endforeach; ?>
      <?= $content ?>
      <p class="foot muted"><?= e(is_saas() ? csetting('brand_name', APP_NAME) : APP_NAME) ?> · Version <?= e(app_version()) ?></p>
    </div>
  </section>
</div>
</body>
</html>
