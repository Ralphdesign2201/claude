<h1>Einrichtung</h1>
<p class="muted">Legen Sie den ersten Zugang (Administrator) an.</p>
<?php if ($err): ?><div class="msg err"><?= e($err) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('setup')) ?>">
<?= csrf_field() ?>
<label>Benutzername<input name="username" value="<?= e($old['username']) ?>" required autocomplete="username"></label>
<label>Anzeigename (optional)<input name="display_name" value="<?= e($old['display_name']) ?>"></label>
<label>Passwort (mind. 8 Zeichen)<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
<label>Passwort wiederholen<input type="password" name="password2" required minlength="8" autocomplete="new-password"></label>
<button class="btn primary wide">Weiter</button>
</form>
