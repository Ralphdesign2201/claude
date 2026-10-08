<h1>Willkommen zurück</h1>
<p class="muted">Bitte melden Sie sich an.</p>
<?php if ($err): ?><div class="msg err"><?= e($err) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('login')) ?>">
<?= csrf_field() ?>
<label>Benutzername<input name="username" value="<?= e($user) ?>" required autofocus autocomplete="username"></label>
<label>Passwort<input type="password" name="password" required autocomplete="current-password"></label>
<button class="btn primary wide">Anmelden</button>
</form>
