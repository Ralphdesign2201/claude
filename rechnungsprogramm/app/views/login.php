<div class="card narrow">
<h1>Anmelden</h1>
<?php if ($err): ?><div class="msg err"><?= e($err) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('login')) ?>">
<?= csrf_field() ?>
<label>Passwort<input type="password" name="password" required autofocus autocomplete="current-password"></label>
<button class="btn primary">Anmelden</button>
</form>
</div>
