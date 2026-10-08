<?php $adminTab = 'users'; require __DIR__ . '/_admin_tabs.php'; ?>
<div class="head"><h1><?= $u['id'] ? 'Benutzer bearbeiten' : 'Neuer Benutzer' ?></h1></div>
<form method="post" action="<?= e(url('user_save')) ?>" class="card">
<?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
<div class="grid">
<label>Benutzername<input name="username" value="<?= e($u['username']) ?>" required autocomplete="off"></label>
<label>Anzeigename<input name="display_name" value="<?= e($u['display_name']) ?>"></label>
<label>E-Mail (optional)<input type="email" name="email" value="<?= e($u['email']) ?>"></label>
<label>Rolle<select name="role_id" required><option value="">– wählen –</option><?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>"<?= (int)$u['role_id'] === (int)$r['id'] ? ' selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></label>
<label class="span2"><?= $u['id'] ? 'Neues Passwort (leer = unverändert)' : 'Passwort (mind. 8 Zeichen)' ?><input type="password" name="password" minlength="8" autocomplete="new-password"<?= $u['id'] ? '' : ' required' ?>></label>
<label class="span2 check"><input type="checkbox" name="active" value="1"<?= $u['active'] ? ' checked' : '' ?>> Zugang aktiv</label>
</div>
<div class="actions"><button class="btn primary">Speichern</button><a class="btn ghost" href="<?= e(url('users')) ?>">Abbrechen</a></div>
</form>
