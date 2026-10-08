<h1>Bestätigung</h1>
<p class="muted">Bitte den 6-stelligen Code aus Ihrer Authenticator-App eingeben – oder einen Wiederherstellungscode.</p>
<?php if ($err): ?><div class="msg err"><?= e($err) ?></div><?php endif; ?>
<form method="post" action="<?= e(url($action)) ?>">
<?= csrf_field() ?>
<label>Code<input name="code" required autofocus autocomplete="one-time-code" inputmode="numeric" maxlength="20"></label>
<button class="btn primary wide">Bestätigen</button>
</form>
<p class="center small"><a href="<?= e(url($cancel)) ?>">Abbrechen</a></p>
