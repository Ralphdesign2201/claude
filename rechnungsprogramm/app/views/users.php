<?php $adminTab = 'users'; require __DIR__ . '/_admin_tabs.php'; ?>
<div class="head"><h1>Benutzer</h1><?php if (can('users', 'w')): ?><a class="btn primary" href="<?= e(url('user_edit')) ?>">+ Neuer Benutzer</a><?php endif; ?></div>
<div class="tablewrap"><table><thead><tr><th>Benutzername</th><th>Name</th><th>Rolle</th><th>Status</th><th>Letzte Anmeldung</th><th></th></tr></thead><tbody>
<?php foreach ($users as $u): ?><tr>
<td><?= can('users', 'w') ? '<a href="' . e(url('user_edit', ['id' => $u['id']])) . '">' . e($u['username']) . '</a>' : e($u['username']) ?></td>
<td><?= e($u['display_name']) ?></td><td><?= e($u['role_name']) ?></td>
<td><span class="badge <?= $u['active'] ? 'paid' : 'cancelled' ?>"><?= $u['active'] ? 'Aktiv' : 'Gesperrt' ?></span></td>
<td><?= $u['last_login'] ? e(date('d.m.Y H:i', strtotime($u['last_login']))) : '–' ?></td>
<td class="r"><?php if (can('users', 'w') && (int)$u['id'] !== (int)current_user()['id']): ?><form method="post" action="<?= e(url('user_delete')) ?>" class="inline" data-confirm="Benutzer „<?= e($u['username']) ?>“ wirklich löschen?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn ghost">Löschen</button></form><?php endif; ?></td>
</tr><?php endforeach; ?></tbody></table></div>
