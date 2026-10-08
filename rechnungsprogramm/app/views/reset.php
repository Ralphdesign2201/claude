<h1>Neues Passwort</h1>
<?php if ($err): ?><div class="msg err"><?= e($err) ?></div><?php endif; ?>
<form method="post" action="<?= e(url($action)) ?>">
<?= csrf_field() ?><input type="hidden" name="k" value="<?= e($tok) ?>"><?php if ($slug): ?><input type="hidden" name="t" value="<?= e($slug) ?>"><?php endif; ?>
<label>Neues Passwort<input type="password" name="password" required minlength="8" autofocus autocomplete="new-password"></label>
<label>Wiederholen<input type="password" name="password2" required minlength="8" autocomplete="new-password"></label>
<button class="btn primary wide">Passwort speichern</button>
</form>
