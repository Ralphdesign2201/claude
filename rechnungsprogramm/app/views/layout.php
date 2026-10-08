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
<?php if (logged_in()): $cur = $_GET['r'] ?? 'dashboard'; $u = current_user();
  $items = [
    ['dashboard', 'Übersicht', true, ['dashboard']],
    ['offers', 'Angebote', can('offers'), ['offer']],
    ['invoices', 'Rechnungen', can('invoices'), ['invoice', 'reminder']],
    ['deliveries', 'Lieferscheine', can('deliveries'), ['deliver']],
    ['customers', 'Kunden', can('customers'), ['customer']],
    ['datev', 'Export', can('export'), ['datev']],
  ];
  $adminHome = can('settings') ? 'settings' : (can('system') ? 'backups' : (can('users') ? 'users' : null)); ?>
<header class="top">
  <a class="brand" href="<?= e(url('dashboard')) ?>"><?= e(setting('company') ?: 'Rechnungsprogramm') ?></a>
  <nav>
    <?php foreach ($items as [$route, $label, $show, $prefixes]): if (!$show) continue;
      $on = false; foreach ($prefixes as $p) if (str_starts_with($cur, $p)) $on = true; ?>
    <a href="<?= e(url($route)) ?>" class="<?= $on ? 'on' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <?php if ($adminHome): ?><a href="<?= e(url($adminHome)) ?>" class="<?= in_array($cur, ['settings', 'settings_save', 'backups', 'users', 'user_edit', 'roles', 'role_edit'], true) ? 'on' : '' ?>">Verwaltung</a><?php endif; ?>
  </nav>
  <span class="spacer"></span>
  <a class="me" href="<?= e(url('profile')) ?>" title="Mein Konto"><?= e($u['display_name'] !== '' ? $u['display_name'] : $u['username']) ?></a>
  <a class="out" href="<?= e(url('logout')) ?>">Abmelden</a>
</header>
<?php endif; ?>
<main class="wrap">
<?php foreach (flash() ?? [] as [$t, $m]): ?><div class="msg <?= e($t) ?>"><?= e($m) ?></div><?php endforeach; ?>
<?= $content ?>
</main>
<script src="<?= e(asset_url('app.js')) ?>"></script>
</body>
</html>
