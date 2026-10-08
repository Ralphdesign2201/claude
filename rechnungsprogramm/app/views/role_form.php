<?php $adminTab = 'roles'; require __DIR__ . '/_admin_tabs.php'; ?>
<div class="head"><h1><?= $r['id'] ? 'Rolle bearbeiten' : 'Neue Rolle' ?></h1></div>
<form method="post" action="<?= e(url('role_save')) ?>" class="card">
<?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
<label>Name der Rolle<input name="name" value="<?= e($r['name']) ?>" required maxlength="60" autofocus></label>
<h2>Rechte</h2>
<div class="tablewrap"><table class="perm"><thead><tr><th>Bereich</th><th>Kein Zugriff</th><th>Lesen</th><th>Ändern</th></tr></thead><tbody>
<?php foreach (MODULES as $k => $l): $v = $perm[$k] ?? ''; ?><tr><td><?= e($l) ?></td>
<?php foreach (['' => 'Kein Zugriff', 'r' => 'Lesen', 'w' => 'Ändern'] as $val => $lab): ?><td><label class="radio"><input type="radio" name="perm[<?= e($k) ?>]" value="<?= e($val) ?>"<?= $v === $val ? ' checked' : '' ?>><span class="sr"><?= e($lab) ?></span></label></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div>
<p class="muted">„Ändern“ umfasst Anlegen, Bearbeiten und Löschen. Wer „Benutzer & Rollen“ ändern darf, kann sich selbst weitere Rechte geben – diese Stufe nur an Vertrauenspersonen vergeben.</p>
<div class="actions"><button class="btn primary">Speichern</button><a class="btn ghost" href="<?= e(url('roles')) ?>">Abbrechen</a></div>
</form>
