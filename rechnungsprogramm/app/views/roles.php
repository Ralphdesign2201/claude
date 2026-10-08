<?php $adminTab = 'roles'; require __DIR__ . '/_admin_tabs.php'; ?>
<div class="head"><h1>Rollen</h1><?php if (can('users', 'w')): ?><a class="btn primary" href="<?= e(url('role_edit')) ?>">+ Neue Rolle</a><?php endif; ?></div>
<p class="muted">Eine Rolle legt fest, welche Bereiche ein Benutzer sehen („lesen“) oder ändern („ändern“) darf.</p>
<div class="tablewrap"><table><thead><tr><th>Rolle</th><th>Rechte</th><th class="r">Benutzer</th><th></th></tr></thead><tbody>
<?php foreach ($roles as $r): $p = (int)$r['is_system'] === 1 ? role_all_write() : (json_decode((string)$r['permissions'], true) ?: []); ?><tr>
<td><?= (int)$r['is_system'] === 1 || !can('users', 'w') ? e($r['name']) : '<a href="' . e(url('role_edit', ['id' => $r['id']])) . '">' . e($r['name']) . '</a>' ?><?= (int)$r['is_system'] === 1 ? ' <span class="badge open">fest</span>' : '' ?></td>
<td><?php $parts = []; foreach (MODULES as $k => $l) if (!empty($p[$k])) $parts[] = e($l) . ($p[$k] === 'r' ? ' (lesen)' : ''); echo $parts ? implode(', ', $parts) : '<span class="muted">keine</span>'; ?></td>
<td class="r"><?= (int)$r['user_count'] ?></td>
<td class="r"><?php if (can('users', 'w') && (int)$r['is_system'] !== 1): ?><form method="post" action="<?= e(url('role_delete')) ?>" class="inline" data-confirm="Rolle „<?= e($r['name']) ?>“ löschen?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn ghost">Löschen</button></form><?php endif; ?></td>
</tr><?php endforeach; ?></tbody></table></div>
