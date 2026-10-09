<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title ? $title . ' – ' : '') ?><?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(asset_url('app.css')) ?>">
</head>
<?php $in = logged_in(); $side = $in && ui_layout() === 'side'; ?>
<body class="<?= $side ? 'layout-side' : 'layout-top' ?>">
<?php if ($in): $cur = $_GET['r'] ?? 'dashboard'; $u = current_user(); $tn = is_saas() ? current_tenant() : null; $groups = nav_groups();
  $adminFirst = null; foreach ($groups as $grp) if ($grp[0] === 'Verwaltung') $adminFirst = $grp[1][0][0];
  $brand = setting('company') ?: APP_NAME; $me = $u['display_name'] !== '' ? $u['display_name'] : $u['username']; ?>
<?php if ($side): ?>
<div class="shell">
<aside class="sidebar" id="sidebar">
  <a class="sb-brand" href="<?= e(url('dashboard')) ?>"><?= e($brand) ?></a>
  <nav aria-label="Module">
    <?php foreach ($groups as [$label, $items]): ?>
      <?php if ($label !== ''): ?><div class="sb-group"><?= e($label) ?></div><?php endif; ?>
      <?php foreach ($items as [$route, $text, $show, $prefixes]): ?><a href="<?= e(url($route)) ?>" class="<?= nav_active($cur, $prefixes) ? 'on' : '' ?>"><?= e($text) ?></a><?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <div class="sb-foot"><a href="<?= e(url('profile')) ?>"><?= e($me) ?></a>
    <form method="post" action="<?= e(url('logout')) ?>" class="inline"><?= csrf_field() ?><button class="out">Abmelden</button></form></div>
</aside>
<div class="content">
<header class="top slim"><button type="button" class="burger" id="burger" aria-label="Menü">☰</button><span class="brand"><?= e($brand) ?></span></header>
<?php else: ?>
<header class="top">
  <a class="brand" href="<?= e(url('dashboard')) ?>"><?= e($brand) ?></a>
  <nav>
    <?php foreach ($groups as [$label, $items]):
      if ($label === 'Verwaltung') { if ($adminFirst) { ?><a href="<?= e(url($adminFirst)) ?>" class="<?= nav_active($cur, ['settings', 'backups', 'backup', 'users', 'user', 'roles', 'role', 'updates', 'update', 'audit']) ? 'on' : '' ?>">Verwaltung</a><?php }
        foreach ($items as $it) if ($it[0] === 'billing') { ?><a href="<?= e(url('billing')) ?>" class="<?= str_starts_with($cur, 'billing') ? 'on' : '' ?>">Abo</a><?php }
        continue; }
      foreach ($items as [$route, $text, $show, $prefixes]): ?><a href="<?= e(url($route)) ?>" class="<?= nav_active($cur, $prefixes) ? 'on' : '' ?>"><?= e($text) ?></a><?php endforeach;
    endforeach; ?>
  </nav>
  <span class="spacer"></span>
  <a class="me" href="<?= e(url('profile')) ?>" title="Mein Konto"><?= e($me) ?></a>
  <form method="post" action="<?= e(url('logout')) ?>" class="inline"><?= csrf_field() ?><button class="out">Abmelden</button></form>
</header>
<?php endif; ?>
<?php if (!empty($_SESSION['impersonating']) && sa_user()): ?>
<div class="banner warn">Support-Zugriff als Mandant „<?= e($tn['company'] ?? '') ?>“ – <form method="post" action="<?= e(url('sa_back')) ?>" class="inline"><?= csrf_field() ?><button class="linkbtn">Zurück zum Superadmin</button></form></div>
<?php endif; ?>
<?php if ($tn): ?>
  <?php if (!(int)$tn['email_verified']): ?><div class="banner warn">Bitte bestätigen Sie Ihre E-Mail-Adresse (Link in Ihrem Postfach), um E-Mails an Kunden senden zu können. <form method="post" action="<?= e(url('verify_resend')) ?>" class="inline"><?= csrf_field() ?><button class="linkbtn">Mail erneut senden</button></form></div><?php endif; ?>
  <?php if ($tn['status'] === 'trial' && $tn['trial_ends']): ?><div class="banner info">Testphase bis <?= e(date_de($tn['trial_ends'])) ?>. <?php if ((int)$u['is_system'] === 1): ?><a href="<?= e(url('billing')) ?>">Tarif wählen</a><?php endif; ?></div><?php endif; ?>
  <?php if ($tn['status'] === 'past_due'): ?><div class="banner warn">Die letzte Zahlung ist fehlgeschlagen. <?php if ((int)$u['is_system'] === 1): ?><a href="<?= e(url('billing')) ?>">Zahlung prüfen</a><?php endif; ?></div><?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<main class="wrap">
<?php foreach (flash() ?? [] as [$t, $m]): ?><div class="msg <?= e($t) ?>"><?= e($m) ?></div><?php endforeach; ?>
<?= $content ?>
</main>
<?php if ($side): ?></div></div><?php endif; ?>
<script src="<?= e(asset_url('app.js')) ?>"></script>
</body>
</html>
