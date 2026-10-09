<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title ? $title . ' – ' : '') ?>Superadmin</title>
<link rel="stylesheet" href="<?= e(asset_url('app.css')) ?>">
</head>
<body class="<?= csetting('sa_layout', 'top') === 'side' ? 'layout-side' : 'layout-top' ?>">
<?php $cur = $_GET['r'] ?? 'sa_dashboard'; $su = sa_user();
$nav = [['sa_dashboard', 'Dashboard', ['sa_dashboard']], ['sa_tenants', 'Mandanten', ['sa_tenant']], ['sa_plans', 'Tarife', ['sa_plan']], ['sa_payments', 'Zahlungen', ['sa_payment']], ['sa_settings', 'Einstellungen', ['sa_setting']], ['sa_admins', 'Superadmins', ['sa_admin']], ['sa_audit', 'Protokoll', ['sa_audit']], ['sa_updates', 'Updates', ['sa_update']]]; ?>
<?php $sbrand = csetting('brand_name', APP_NAME); $sname = $su['display_name'] !== '' ? $su['display_name'] : $su['username'];
$isOn = function ($pfx) use ($cur) { foreach ($pfx as $p) if (str_starts_with($cur, $p)) return true; return false; }; ?>
<?php if (csetting('sa_layout', 'top') === 'side'): ?>
<div class="shell">
<aside class="sidebar" id="sidebar">
  <a class="sb-brand" href="<?= e(url('sa_dashboard')) ?>">⚙ <?= e($sbrand) ?> · Superadmin</a>
  <nav aria-label="Module"><?php foreach ($nav as [$r, $l, $pfx]): ?><a href="<?= e(url($r)) ?>" class="<?= $isOn($pfx) ? 'on' : '' ?>"><?= e($l) ?></a><?php endforeach; ?></nav>
  <div class="sb-foot"><a href="<?= e(url('sa_profile')) ?>"><?= e($sname) ?> · v<?= e(app_version()) ?></a>
    <form method="post" action="<?= e(url('sa_logout')) ?>" class="inline"><?= csrf_field() ?><button class="out">Abmelden</button></form></div>
</aside>
<div class="content">
<header class="top slim sa"><button type="button" class="burger" id="burger" aria-label="Menü">☰</button><span class="brand">⚙ Superadmin</span></header>
<?php else: ?>
<header class="top sa">
  <a class="brand" href="<?= e(url('sa_dashboard')) ?>">⚙ <?= e(csetting('brand_name', APP_NAME)) ?> · Superadmin</a>
  <nav><?php foreach ($nav as [$r, $l, $pfx]): $on = false; foreach ($pfx as $p) if (str_starts_with($cur, $p)) $on = true; ?><a href="<?= e(url($r)) ?>" class="<?= $on ? 'on' : '' ?>"><?= e($l) ?></a><?php endforeach; ?></nav>
  <span class="spacer"></span>
  <a class="me" href="<?= e(url('sa_profile')) ?>"><?= e($su['display_name'] !== '' ? $su['display_name'] : $su['username']) ?> · v<?= e(app_version()) ?></a>
  <form method="post" action="<?= e(url('sa_logout')) ?>" class="inline"><?= csrf_field() ?><button class="out">Abmelden</button></form>
</header>
<?php endif; ?>
<main class="wrap">
<?php foreach (flash() ?? [] as [$t, $m]): ?><div class="msg <?= e($t) ?>"><?= e($m) ?></div><?php endforeach; ?>
<?= $content ?>
</main>
<?php if (csetting('sa_layout', 'top') === 'side'): ?></div></div><?php endif; ?>
<script src="<?= e(asset_url('app.js')) ?>"></script>
</body>
</html>
