<?php if (!is_saas()) { $adminTab = 'updates'; require __DIR__ . '/_admin_tabs.php'; } $pfx = updates_prefix(); ?>
<div class="head"><h1>Updates</h1><span class="badge paid">Installiert: Version <?= e(app_version()) ?></span></div>

<p class="muted small">Kontrolle: app.js <?= e(substr((string)@sha1_file(dirname(APP_ROOT) . '/public/assets/app.js'), 0, 8)) ?> · app.css <?= e(substr((string)@sha1_file(dirname(APP_ROOT) . '/public/assets/app.css'), 0, 8)) ?></p>
<?php if (!$sodium): ?><div class="msg err">Die PHP-Erweiterung „sodium“ fehlt. Ohne sie können Updates nicht geprüft werden. Bitte beim Hoster aktivieren.</div><?php endif; ?>

<?php if ($pending): $pl = $pending['plan']; ?>
<div class="card"><h2>Update auf Version <?= e($pending['target']) ?> bereit</h2>
<p>Installiert werden nur die Änderungen, die Ihnen noch fehlen (<?= count($pl['steps']) ?> Schritt<?= count($pl['steps']) === 1 ? '' : 'e' ?>, <?= (int)$pending['files'] ?> Datei<?= $pending['files'] === 1 ? '' : 'en' ?><?= $pending['deleted'] ? ', ' . (int)$pending['deleted'] . ' entfallen' : '' ?>):</p>
<?php foreach ($pl['steps'] as $s): ?><h3>Version <?= e($s['version']) ?> <small class="muted"><?= e($s['date'] ?? '') ?></small></h3>
<ul><?php foreach (($s['notes'] ?? []) as $n): ?><li><?= e($n) ?></li><?php endforeach; ?></ul><?php endforeach; ?>
<p class="muted">Vorher wird automatisch eine Sicherheitskopie der Daten angelegt; die ersetzten Dateien werden in <code>storage/update_backup/</code> gesichert. Bei einem Fehler wird alles zurückgesetzt.</p>
<form method="post" action="<?= e(url($pfx . 'update_install')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($pending['token']) ?>"><button class="btn primary" data-confirm="Update jetzt installieren?">Jetzt installieren</button> <a class="btn ghost" href="<?= e(url($pfx . 'updates')) ?>">Abbrechen</a></form>
</div>
<?php endif; ?>

<form method="post" action="<?= e(url($pfx . 'update_upload')) ?>" enctype="multipart/form-data" class="card"><?= csrf_field() ?>
<h2>Update hochladen</h2>
<p class="muted">Update-Datei (<code>.rgu</code>) des Herstellers auswählen. Die Dateien sind kumulativ: Sie enthalten alle Änderungen seit der Startversion, installiert wird nur, was bei Ihnen noch fehlt – es ist also nicht schlimm, Versionen ausgelassen zu haben. Upload-Limit dieses Servers: <?= e($maxUpload) ?>.</p>
<input type="file" name="file" accept=".rgu" required>
<div class="actions"><button class="btn primary">Hochladen &amp; prüfen</button></div>
</form>

<?php if ($history): ?><div class="card"><h2>Bisher installiert</h2>
<table><thead><tr><th>Datum</th><th>Von → auf</th><th>Änderungen</th></tr></thead><tbody>
<?php foreach ($history as $h): ?><tr><td><?= e(date('d.m.Y H:i', strtotime($h['at']))) ?></td><td><?= e($h['from']) ?> → <?= e($h['version']) ?></td>
<td><?php foreach ($h['steps'] as $s): foreach ($s['notes'] as $n): ?><div>• <?= e($n) ?></div><?php endforeach; endforeach; ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
