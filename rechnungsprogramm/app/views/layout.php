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
<?php if (logged_in()): $cur = $_GET['r'] ?? 'dashboard'; $u = current_user(); $tn = is_saas() ? current_tenant() : null;
  $items = [
    ['dashboard', 'Übersicht', true, ['dashboard']],
    ['offers', 'Angebote', can('offers'), ['offer']],
    ['invoices', 'Rechnungen', can('invoices'), ['invoice', 'reminder']],
    ['deliveries', 'Lieferscheine', can('deliveries'), ['deliver']],
    ['customers', 'Kunden', can('customers'), ['customer']],
    ['datev', 'Export', can('export'), ['datev']],
  ];
  $adminHome = can('settings') ? 'settings' : (can('system') ? 'backups' : (can('users') ? 'users' : null)); ?>
<?php if (!empty($_SESSION['impersonating']) && sa_user()): ?>
<div class="banner warn">Support-Zugriff als Mandant „<?= e($tn['company'] ?? '') ?>“ – <form method="post" action="<?= e(url('sa_back')) ?>" class="inline"><?= csrf_field() ?><button class="linkbtn">Zurück zum Superadmin</button></form></div>
<?php endif; ?>
<header class="top">
  <a class="brand" href="<?= e(url('dashboard')) ?>"><?= e(setting('company') ?: APP_NAME) ?></a>
  <nav>
    <?php foreach ($items as [$route, $label, $show, $prefixes]): if (!$show) continue;
      $on = false; foreach ($prefixes as $p) if (str_starts_with($cur, $p)) $on = true; ?>
    <a href="<?= e(url($route)) ?>" class="<?= $on ? 'on' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <?php if ($adminHome): ?><a href="<?= e(url($adminHome)) ?>" class="<?= in_array($cur, ['settings', 'settings_save', 'backups', 'users', 'user_edit', 'roles', 'role_edit', 'updates', 'audit'], true) ? 'on' : '' ?>">Verwaltung</a><?php endif; ?>
    <?php if ($tn && (int)$u['is_system'] === 1): ?><a href="<?= e(url('billing')) ?>" class="<?= str_starts_with($cur, 'billing') ? 'on' : '' ?>">Abo</a><?php endif; ?>
  </nav>
  <span class="spacer"></span>
  <a class="me" href="<?= e(url('profile')) ?>" title="Mein Konto"><?= e($u['display_name'] !== '' ? $u['display_name'] : $u['username']) ?></a>
  <form method="post" action="<?= e(url('logout')) ?>" class="inline"><?= csrf_field() ?><button class="out">Abmelden</button></form>
</header>
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
<script src="<?= e(asset_url('app.js')) ?>"></script>
</body>
</html>
