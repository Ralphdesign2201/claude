<section class="pub-wrap narrow-form">
<h1><?= $trial ? e($trial) . ' Tage kostenlos testen' : 'Konto anlegen' ?></h1>
<p class="muted">In einer Minute startklar – ohne Kreditkarte.</p>
<?php if ($err): ?><div class="msg err"><?= e($err) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('signup')) ?>" class="card" autocomplete="off">
<?= csrf_field() ?>
<input type="hidden" name="fs" value="<?= e($stamp) ?>">
<div class="hp" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
<label>Firma<input name="company" id="su_company" value="<?= e($v['company']) ?>" required></label>
<div class="grid"><label>Ihr Name<input name="owner_name" value="<?= e($v['owner_name']) ?>" required></label><label>E-Mail (zugleich Benutzername)<input type="email" name="owner_email" value="<?= e($v['owner_email']) ?>" required></label></div>
<label>Firmen-ID für die Anmeldung<input name="slug" id="su_slug" value="<?= e($v['slug']) ?>" pattern="[a-z0-9][a-z0-9-]{1,38}[a-z0-9]" placeholder="z. B. mueller-sanitaer"><small class="muted">Kleinbuchstaben, Ziffern, Bindestrich – wird aus dem Firmennamen vorgeschlagen.</small></label>
<div class="grid"><label>Passwort (mind. 8 Zeichen)<input type="password" name="password" required minlength="8" autocomplete="new-password"></label><label>Wiederholen<input type="password" name="password2" required minlength="8" autocomplete="new-password"></label></div>
<label class="check"><input type="checkbox" name="terms" value="1" required> Ich akzeptiere die <a href="<?= e(url('page', ['p' => 'agb'])) ?>" target="_blank" rel="noopener">AGB</a> und die <a href="<?= e(url('page', ['p' => 'datenschutz'])) ?>" target="_blank" rel="noopener">Datenschutzerklärung</a>.</label>
<button class="btn primary wide">Konto anlegen</button>
</form>
<p class="center muted">Schon registriert? <a href="<?= e(url('login')) ?>">Anmelden</a></p>
</section>
