<h1>Willkommen zurück</h1>
<p class="muted">Bitte melden Sie sich an.</p>
<?php if ($err): ?><div class="msg err"><?= e($err) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('login')) ?>">
<?= csrf_field() ?>
<?php if ($saas && !$hostTenant): ?><label>Firmen-ID<input name="tenant" value="<?= e($tenantInput) ?>" autocapitalize="none" autocomplete="organization" placeholder="z. B. mueller-sanitaer"><small class="muted">Kunden: Firmen-ID eingeben. Plattform-Administratoren lassen das Feld leer.</small></label><?php endif; ?>
<label>Benutzername<input name="username" value="<?= e($user) ?>" required <?= ($saas && !$hostTenant && $tenantInput === '') ? '' : 'autofocus' ?> autocomplete="username"></label>
<label>Passwort<input type="password" name="password" required autocomplete="current-password"></label>
<button class="btn primary wide">Anmelden</button>
</form>
<p class="center small"><a href="<?= e(url('forgot')) ?>">Passwort vergessen?</a><?php if ($saas && csetting('signup_open', '1') === '1'): ?> · <a href="<?= e(url('signup')) ?>">Kostenlos testen</a><?php endif; ?></p>
