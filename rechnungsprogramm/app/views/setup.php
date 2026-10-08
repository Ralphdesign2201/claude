<div class="card narrow">
<h1>Einrichtung</h1>
<p>Willkommen! Legen Sie ein Passwort für den Zugang fest.</p>
<?php if ($err): ?><div class="msg err"><?= e($err) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('setup')) ?>">
<?= csrf_field() ?>
<label>Passwort (mind. 8 Zeichen)<input type="password" name="password" required minlength="8" autofocus autocomplete="new-password"></label>
<label>Passwort wiederholen<input type="password" name="password2" required minlength="8" autocomplete="new-password"></label>
<button class="btn primary">Weiter</button>
</form>
</div>
