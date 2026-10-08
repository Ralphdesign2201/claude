<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> – <?= e(csetting('brand_name', APP_NAME)) ?></title>
<link rel="stylesheet" href="<?= e(asset_url('app.css')) ?>">
</head>
<body class="pub">
<header class="pub-top"><div class="pub-wrap">
  <a class="pub-brand" href="<?= e(url('home')) ?>"><?= e(csetting('brand_name', APP_NAME)) ?></a>
  <nav><a href="<?= e(url('home')) ?>#preise">Preise</a><a href="<?= e(url('login')) ?>">Anmelden</a><a class="btn primary" href="<?= e(url('signup')) ?>">Kostenlos testen</a></nav>
</div></header>
<main class="pub-main">
<?php foreach (flash() ?? [] as [$t, $m]): ?><div class="pub-wrap"><div class="msg <?= e($t) ?>"><?= e($m) ?></div></div><?php endforeach; ?>
<?= $content ?>
</main>
<footer class="pub-foot"><div class="pub-wrap">
  <span>© <?= e(date('Y')) ?> <?= e(csetting('company', csetting('brand_name', APP_NAME))) ?></span>
  <span><a href="<?= e(url('page', ['p' => 'impressum'])) ?>">Impressum</a> · <a href="<?= e(url('page', ['p' => 'datenschutz'])) ?>">Datenschutz</a> · <a href="<?= e(url('page', ['p' => 'agb'])) ?>">AGB</a></span>
</div></footer>
<script src="<?= e(asset_url('app.js')) ?>"></script>
</body>
</html>
