<div class="head"><h1>Superadmins</h1></div>
<div class="tablewrap"><table><thead><tr><th>Benutzername</th><th>Name</th><th>E-Mail</th><th>Status</th><th>Letzte Anmeldung</th><th></th></tr></thead><tbody>
<?php foreach ($admins as $a): ?><tr><form method="post" action="<?= e(url('sa_admin_save')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
<td><input name="username" value="<?= e($a['username']) ?>" required></td><td><input name="display_name" value="<?= e($a['display_name']) ?>"></td><td><input type="email" name="email" value="<?= e($a['email']) ?>"></td>
<td><label class="check"><input type="checkbox" name="active" value="1"<?= $a['active'] ? ' checked' : '' ?>> aktiv</label></td><td><?= $a['last_login'] ? e(date('d.m.Y H:i', strtotime($a['last_login']))) : '–' ?></td>
<td class="r"><input type="password" name="password" placeholder="neues Passwort" minlength="10" autocomplete="new-password"> <button class="btn">Speichern</button></td></form></tr><?php endforeach; ?></tbody></table></div>
<form method="post" action="<?= e(url('sa_admin_save')) ?>" class="card"><?= csrf_field() ?><input type="hidden" name="id" value="0"><h2>Neuen Superadmin anlegen</h2>
<div class="grid"><label>Benutzername<input name="username" required></label><label>Name<input name="display_name"></label><label>E-Mail<input type="email" name="email"></label><label>Passwort (mind. 10 Zeichen)<input type="password" name="password" required minlength="10" autocomplete="new-password"></label></div>
<input type="hidden" name="active" value="1"><div class="actions"><button class="btn primary">Anlegen</button></div></form>
<?php foreach ($admins as $a): if (count($admins) > 1): ?><?php endif; endforeach; ?>
<div class="card"><h2>Superadmin löschen</h2><div class="actions"><?php foreach ($admins as $a): ?><form method="post" action="<?= e(url('sa_admin_delete')) ?>" class="inline" data-confirm="Superadmin „<?= e($a['username']) ?>“ löschen?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn ghost"><?= e($a['username']) ?> löschen</button></form><?php endforeach; ?></div></div>
