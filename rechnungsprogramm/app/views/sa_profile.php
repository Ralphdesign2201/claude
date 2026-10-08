<div class="head"><h1>Mein Konto (Superadmin)</h1><a class="btn" href="<?= e(url('sa_twofa')) ?>">Zwei-Faktor-Anmeldung <?= (int)$u['totp_enabled'] ? '(aktiv)' : '(einrichten)' ?></a></div>
<form method="post" action="<?= e(url('sa_profile_save')) ?>" class="card"><?= csrf_field() ?>
<p class="muted">Angemeldet als <strong><?= e($u['username']) ?></strong></p>
<div class="grid"><label>Anzeigename<input name="display_name" value="<?= e($u['display_name']) ?>"></label><label>E-Mail (für „Passwort vergessen“)<input type="email" name="email" value="<?= e($u['email']) ?>"></label></div>
<h2>Passwort ändern</h2>
<div class="grid"><label class="span2">Aktuelles Passwort<input type="password" name="current" autocomplete="current-password"></label><label>Neues Passwort (mind. 10 Zeichen)<input type="password" name="new" minlength="10" autocomplete="new-password"></label><label>Wiederholen<input type="password" name="new2" minlength="10" autocomplete="new-password"></label></div>
<div class="actions"><button class="btn primary">Speichern</button></div></form>
