<?php
$name = fn(array $r) => $r['company'] !== '' ? $r['company'] : trim($r['firstname'] . ' ' . $r['lastname']);
$row = function (array $i) use ($name) { ?>
<tr><td><a href="<?= e(url('invoice_show', ['id' => $i['id']])) ?>"><?= e($i['invoice_number']) ?></a></td><td><?= e($name($i)) ?></td>
<td><?= e(date_de($i['due_date'])) ?></td><td class="r"><?= e(money((int)$i['gross_amount'])) ?></td>
<td><span class="badge <?= e($i['status']) ?><?= is_overdue($i) ? ' overdue' : '' ?>"><?= e(status_label($i['status'], is_overdue($i))) ?></span></td></tr>
<?php }; ?>
<div class="head"><h1>Übersicht</h1><?php if (can('invoices', 'w')): ?><a class="btn primary" href="<?= e(url('invoice_new')) ?>">+ Neue Rechnung</a><?php endif; ?></div>
<?php if ($setupMissing && can('settings', 'w')): ?><div class="msg warn">Bitte zuerst die <a href="<?= e(url('settings')) ?>">Firmendaten</a> eintragen, damit sie auf den Rechnungen erscheinen.</div><?php endif; ?>
<div class="tiles">
  <a class="tile" href="<?= e(url('invoices', ['status' => 'open'])) ?>"><span>Offen</span><strong><?= e(money((int)$openSum)) ?></strong><small><?= (int)$openCnt ?> Rechnungen</small></a>
  <a class="tile <?= $odCnt ? 'bad' : '' ?>" href="<?= e(url('invoices', ['status' => 'overdue'])) ?>"><span>Überfällig</span><strong><?= e(money((int)$odSum)) ?></strong><small><?= (int)$odCnt ?> Rechnungen</small></a>
  <div class="tile"><span>Bezahlt (<?= e(date('m/Y')) ?>)</span><strong><?= e(money((int)$monthSum)) ?></strong><small>Zahlungseingang</small></div>
  <div class="tile"><span>Umsatz <?= e(date('Y')) ?> (netto)</span><strong><?= e(money((int)$yearNet)) ?></strong><small><?= (int)$custCnt ?> Kunden</small></div>
</div>
<?php if ($overdueList): ?>
<h2>Überfällige Rechnungen</h2>
<div class="tablewrap"><table><thead><tr><th>Nr.</th><th>Kunde</th><th>Fällig</th><th class="r">Betrag</th><th>Status</th></tr></thead><tbody><?php foreach ($overdueList as $i) $row($i); ?></tbody></table></div>
<?php endif; ?>
<h2>Zuletzt erstellt</h2>
<?php if (!$recent): ?><p class="muted">Noch keine Rechnungen vorhanden.</p><?php else: ?>
<div class="tablewrap"><table><thead><tr><th>Nr.</th><th>Kunde</th><th>Fällig</th><th class="r">Betrag</th><th>Status</th></tr></thead><tbody><?php foreach ($recent as $i) $row($i); ?></tbody></table></div>
<?php endif; ?>
