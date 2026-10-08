<h1>Superadmin</h1>
<p class="muted">Anmeldung für die Plattform-Verwaltung.</p>
<?php if ($err): ?><div class="msg err"><?= e($err) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('sa_login')) ?>">
<?= csrf_field() ?>
<label>Benutzername<input name="username" value="<?= e($user) ?>" required autofocus autocomplete="username"></label>
<label>Passwort<input type="password" name="password" required autocomplete="current-password"></label>
<button class="btn primary wide">Anmelden</button>
</form>
<p class="center small"><a href="<?= e(url('sa_forgot')) ?>">Passwort vergessen?</a></p>
