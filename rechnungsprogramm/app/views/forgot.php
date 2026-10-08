<h1>Passwort vergessen</h1>
<?php if ($done): ?>
<div class="msg ok">Wenn zu den Angaben ein Konto mit hinterlegter E-Mail-Adresse existiert, haben wir einen Link zum Zurücksetzen gesendet (60 Minuten gültig). Bitte auch den Spam-Ordner prüfen.</div>
<?php else: ?>
<p class="muted">Wir senden Ihnen einen Link an die im Konto hinterlegte E-Mail-Adresse.</p>
<?php if ($err): ?><div class="msg err"><?= e($err) ?></div><?php endif; ?>
<form method="post" action="<?= e(url($action)) ?>">
<?= csrf_field() ?>
<?php if ($saas): ?><label>Firmen-ID<input name="tenant" value="<?= e($tenant) ?>" required autocapitalize="none"></label><?php endif; ?>
<label>Benutzername oder E-Mail<input name="username" required autofocus autocomplete="username"></label>
<button class="btn primary wide">Link anfordern</button>
</form>
<?php endif; ?>
<p class="center small"><a href="<?= e(url($back)) ?>">Zurück zur Anmeldung</a></p>
