<div class="head"><h1>Mandanten</h1></div>
<form method="get" class="filter"><input type="hidden" name="r" value="sa_tenants">
<select name="status"><?php foreach (['' => 'Alle Status', 'trial' => 'Testphase', 'active' => 'Aktiv', 'past_due' => 'Zahlung offen', 'canceled' => 'Gekündigt', 'expired' => 'Abgelaufen', 'suspended' => 'Gesperrt'] as $k => $l): ?><option value="<?= e($k) ?>"<?= $status === (string)$k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
<input type="search" name="q" value="<?= e($q) ?>" placeholder="Firma, ID, E-Mail"><button class="btn">Filtern</button></form>
<div class="tablewrap"><table><thead><tr><th>Firma</th><th>ID</th><th>E-Mail</th><th>Tarif</th><th>Status</th><th>Testphase bis</th><th>Zahlt bis</th><th>Letzter Login</th></tr></thead><tbody>
<?php foreach ($tenants as $t): ?><tr><td><a href="<?= e(url('sa_tenant', ['id' => $t['id']])) ?>"><?= e($t['company']) ?></a></td><td><?= e($t['slug']) ?></td><td><?= e($t['owner_email']) ?></td><td><?= e($t['plan_name'] ?? '–') ?></td>
<td><span class="badge <?= e($t['status']) ?>"><?= e(tenant_status_label($t['status'])) ?></span></td><td><?= e(date_de($t['trial_ends'])) ?></td><td><?= e(date_de($t['period_end'])) ?></td><td><?= $t['last_login'] ? e(date('d.m.Y H:i', strtotime($t['last_login']))) : '–' ?></td></tr><?php endforeach; ?>
<?php if (!$tenants): ?><tr><td colspan="8" class="muted">Keine Mandanten gefunden.</td></tr><?php endif; ?></tbody></table></div>
