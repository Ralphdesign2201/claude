<div class="head"><h1>Mein Konto</h1><a class="btn" href="<?= e(url('twofa')) ?>">Zwei-Faktor-Anmeldung <?= (int)$u['totp_enabled'] ? '(aktiv)' : '(einrichten)' ?></a></div>
<form method="post" action="<?= e(url('profile_save')) ?>" class="card">
<?= csrf_field() ?>
<p class="muted">Angemeldet als <strong><?= e($u['username']) ?></strong> · Rolle: <?= e($u['role_name']) ?></p>
<div class="grid"><label>Anzeigename<input name="display_name" value="<?= e($u['display_name']) ?>"></label><label>E-Mail<input type="email" name="email" value="<?= e($u['email']) ?>"></label></div>
<h2>Ansicht</h2>
<label>Meine Menü-Ansicht<select name="ui_layout"><option value=""<?= ($u['ui_layout'] ?? '') === '' ? ' selected' : '' ?>>Standard des Betriebs (<?= setting('ui_layout', 'top') === 'side' ? 'Seitenleiste' : 'obere Leiste' ?>)</option><option value="top"<?= ($u['ui_layout'] ?? '') === 'top' ? ' selected' : '' ?>>Obere Menüleiste</option><option value="side"<?= ($u['ui_layout'] ?? '') === 'side' ? ' selected' : '' ?>>Seitenleiste mit allen Modulen</option></select></label>
<h2>Passwort ändern</h2>
<div class="grid">
<label class="span2">Aktuelles Passwort<input type="password" name="current" autocomplete="current-password"></label>
<label>Neues Passwort<input type="password" name="new" minlength="8" autocomplete="new-password"></label>
<label>Wiederholen<input type="password" name="new2" minlength="8" autocomplete="new-password"></label>
</div>
<div class="actions"><button class="btn primary">Speichern</button></div>
</form>
