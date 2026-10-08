<?php if (!$central) { $adminTab = 'audit'; require __DIR__ . '/_admin_tabs.php'; } ?>
<div class="head"><h1>Sicherheitsprotokoll</h1></div>
<form method="get" class="filter"><input type="hidden" name="r" value="<?= e($route) ?>"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Benutzer, Ereignis, Details"><button class="btn">Suchen</button></form>
<div class="tablewrap"><table><thead><tr><th>Zeit</th><th>Benutzer</th><th>Ereignis</th><th>Details</th><th>IP</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= e(date('d.m.Y H:i:s', strtotime($r['ts']))) ?></td><td><?= e($r['username']) ?></td><td><?= e($r['action']) ?></td><td><?= e($r['detail']) ?></td><td><?= e($r['ip']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5" class="muted">Keine Einträge.</td></tr><?php endif; ?></tbody></table></div>
<p class="muted">Angezeigt werden die letzten 300 Einträge. Ältere Einträge werden nach 180 Tagen automatisch gelöscht.</p>
