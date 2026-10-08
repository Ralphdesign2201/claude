<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title ? $title . ' – ' : '') ?>Rechnungsprogramm</title>
<link rel="stylesheet" href="<?= e(asset_url('app.css')) ?>">
</head>
<body>
<?php if (logged_in()): $cur = $_GET['r'] ?? 'dashboard'; ?>
<header class="top">
  <a class="brand" href="<?= e(url('dashboard')) ?>"><?= e(setting('company') ?: 'Rechnungsprogramm') ?></a>
  <nav>
    <a href="<?= e(url('dashboard')) ?>" class="<?= $cur === 'dashboard' ? 'on' : '' ?>">Übersicht</a>
    <a href="<?= e(url('offers')) ?>" class="<?= str_starts_with($cur, 'offer') ? 'on' : '' ?>">Angebote</a>
    <a href="<?= e(url('invoices')) ?>" class="<?= str_starts_with($cur, 'invoice') || str_starts_with($cur, 'reminder') ? 'on' : '' ?>">Rechnungen</a>
    <a href="<?= e(url('customers')) ?>" class="<?= str_starts_with($cur, 'customer') ? 'on' : '' ?>">Kunden</a>
    <a href="<?= e(url('settings')) ?>" class="<?= str_starts_with($cur, 'settings') ? 'on' : '' ?>">Einstellungen</a>
    <a href="<?= e(url('logout')) ?>">Abmelden</a>
  </nav>
</header>
<?php endif; ?>
<main class="wrap">
<?php foreach (flash() ?? [] as [$t, $m]): ?><div class="msg <?= e($t) ?>"><?= e($m) ?></div><?php endforeach; ?>
<?= $content ?>
</main>
<script src="<?= e(asset_url('app.js')) ?>"></script>
</body>
</html>
