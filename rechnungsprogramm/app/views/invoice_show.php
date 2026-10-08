<?php $od = is_overdue($inv); $name = $inv['company'] !== '' ? $inv['company'] : trim($inv['firstname'] . ' ' . $inv['lastname']); ?>
<div class="head"><h1>Rechnung <?= e($inv['invoice_number']) ?> <span class="badge <?= $inv['status'] ?><?= $od ? ' overdue' : '' ?>"><?= e(status_label($inv['status'], $od)) ?></span></h1>
<div class="actions">
<a class="btn primary" href="<?= e(url('invoice_pdf', ['id' => $inv['id']])) ?>" target="_blank" rel="noopener">PDF ansehen</a>
<a class="btn" href="<?= e(url('invoice_pdf', ['id' => $inv['id'], 'download' => 1])) ?>">PDF herunterladen</a>
<?php if ($inv['status'] !== 'cancelled'): ?><a class="btn" href="<?= e(url('invoice_xml', ['id' => $inv['id']])) ?>" title="ZUGFeRD / Factur-X (EN 16931)">E-Rechnung (XML)</a>
<?php if (!empty($inv['leitweg_id'])): ?><a class="btn" href="<?= e(url('invoice_xml', ['id' => $inv['id'], 'xr' => 1])) ?>" title="XRechnung 3.0 für Behörden">XRechnung (XML)</a><?php endif; ?><?php endif; ?>
<?php if ($inv['status'] === 'open' && can('invoices', 'w')): ?><a class="btn" href="<?= e(url('invoice_edit', ['id' => $inv['id']])) ?>">Bearbeiten</a><?php endif; ?>
<?php if (can('deliveries', 'w')): ?><a class="btn" href="<?= e(url('delivery_new', ['from_invoice' => $inv['id']])) ?>">Lieferschein erstellen</a><?php endif; ?>
<?php if ($inv['status'] !== 'cancelled'): ?><a class="btn" href="<?= e(url('mail_new', ['type' => 'invoice', 'id' => $inv['id']])) ?>">Per E-Mail senden</a><?php endif; ?>
<?php if (can('invoices', 'w')): ?><form method="post" action="<?= e(url('invoice_copy')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$inv['id'] ?>"><button class="btn">Kopieren</button></form><?php endif; ?>
</div></div>

<div class="card cols">
<div><h3>Kunde</h3><p><a href="<?= e(url('customer_edit', ['id' => $inv['customer_id']])) ?>"><?= e($name) ?></a></p><pre><?= e($inv['customer_address']) ?></pre></div>
<div><h3>Daten</h3><dl>
<dt>Rechnungsdatum</dt><dd><?= e(date_de($inv['invoice_date'])) ?></dd>
<dt>Zahlbar bis</dt><dd><?= e(date_de($inv['due_date'])) ?></dd>
<?php if ($inv['service_date'] !== ''): ?><dt>Leistung</dt><dd><?= e($inv['service_date']) ?></dd><?php endif; ?>
<?php if ($inv['paid_date']): ?><dt>Bezahlt am</dt><dd><?= e(date_de($inv['paid_date'])) ?></dd><?php endif; ?>
<?php if ($inv['cancelled_at']): ?><dt>Storniert am</dt><dd><?= e(date_de($inv['cancelled_at'])) ?><?= $inv['cancel_reason'] !== '' ? ' – ' . e($inv['cancel_reason']) : '' ?></dd><?php endif; ?>
</dl></div></div>

<?php if ($inv['subject'] !== ''): ?><p><strong><?= e($inv['subject']) ?></strong></p><?php endif; ?>
<div class="tablewrap"><table><thead><tr><th>Pos.</th><th>Beschreibung</th><th class="r">Menge</th><th>Einheit</th><th class="r">Einzelpreis</th><?php if (!$inv['small_business']): ?><th class="r">USt</th><?php endif; ?><th class="r">Netto</th></tr></thead><tbody>
<?php foreach ($items as $n => $it): ?><tr><td><?= $n + 1 ?></td><td class="pre"><?= e($it['description']) ?></td><td class="r"><?= e(qty_fmt((float)$it['quantity'])) ?></td><td><?= e($it['unit']) ?></td><td class="r"><?= e(money((int)$it['unit_price'])) ?></td><?php if (!$inv['small_business']): ?><td class="r"><?= e(qty_fmt((float)$it['vat_rate'])) ?> %</td><?php endif; ?><td class="r"><?= e(money((int)$it['total'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<div class="totals"><div>Netto <strong><?= e(money((int)$inv['net_amount'])) ?></strong></div><?php if (!$inv['small_business']): ?><div>Umsatzsteuer <strong><?= e(money((int)$inv['vat_amount'])) ?></strong></div><?php endif; ?><div class="big">Gesamt <strong><?= e(money((int)$inv['gross_amount'])) ?></strong></div></div>

<?php if ($inv['status'] !== 'cancelled' && can('invoices', 'w')): ?>
<div class="card"><h3>Zahlungsstatus</h3><div class="actions">
<?php if ($inv['status'] === 'open'): ?>
<form method="post" action="<?= e(url('invoice_status')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$inv['id'] ?>"><input type="hidden" name="action" value="paid">
<label class="inline">Bezahlt am <input type="date" name="paid_date" value="<?= e(date('Y-m-d')) ?>"></label><button class="btn primary">Als bezahlt markieren</button></form>
<?php else: ?>
<form method="post" action="<?= e(url('invoice_status')) ?>" class="inline" data-confirm="Rechnung wieder auf „offen“ setzen?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$inv['id'] ?>"><input type="hidden" name="action" value="reopen"><button class="btn">Wieder auf offen setzen</button></form>
<?php endif; ?>
<form method="post" action="<?= e(url('invoice_status')) ?>" class="inline" data-confirm="Rechnung wirklich stornieren? Das kann nicht rückgängig gemacht werden."><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$inv['id'] ?>"><input type="hidden" name="action" value="cancel">
<input name="reason" placeholder="Stornogrund (optional)"><button class="btn danger">Stornieren</button></form>
</div></div>
<?php endif; ?>

<?php if ($reminders || ($inv['status'] === 'open' && can('invoices', 'w'))): ?>
<div class="card"><h3>Mahnwesen</h3>
<?php if ($reminders): ?>
<table><thead><tr><th>Stufe</th><th>Datum</th><th>Neue Frist</th><th class="r">Gesamt</th><th></th></tr></thead><tbody>
<?php foreach ($reminders as $r): ?><tr><td><?= e(REMINDER_LEVELS[$r['level']]) ?></td><td><?= e(date_de($r['reminder_date'])) ?></td><td><?= e(date_de($r['new_due_date'])) ?></td>
<td class="r"><?= e(money((int)$r['open_amount'] + (int)$r['fee'] + (int)$r['interest'])) ?></td>
<td class="r"><a href="<?= e(url('reminder_pdf', ['id' => $r['id']])) ?>" target="_blank" rel="noopener">PDF</a> · <a href="<?= e(url('mail_new', ['type' => 'reminder', 'id' => $r['id']])) ?>">E-Mail</a>
<form method="post" action="<?= e(url('reminder_delete')) ?>" class="inline" data-confirm="Mahnung löschen?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn ghost">Löschen</button></form></td></tr><?php endforeach; ?>
</tbody></table>
<?php endif; ?>
<?php if ($inv['status'] === 'open' && can('invoices', 'w')):
  $next = $reminders ? min(3, (int)max(array_column($reminders, 'level')) + 1) : 1; ?>
<form method="post" action="<?= e(url('reminder_save')) ?>" class="grid" id="remform" data-fee2="<?= e(setting('reminder_fee_2', '0,00') ?: '0,00') ?>" data-fee3="<?= e(setting('reminder_fee_3', '0,00') ?: '0,00') ?>">
<?= csrf_field() ?><input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
<label>Stufe<select name="level" id="remlevel"><?php foreach (REMINDER_LEVELS as $k => $l): ?><option value="<?= $k ?>"<?= $k === $next ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
<label>Mahndatum<input type="date" name="reminder_date" value="<?= e(date('Y-m-d')) ?>"></label>
<label>Neue Zahlungsfrist<input type="date" name="new_due_date" value="<?= e(date('Y-m-d', strtotime('+7 days'))) ?>"></label>
<label>Mahngebühr (€)<input name="fee" id="remfee" inputmode="decimal" value="<?= e($next === 2 ? (setting('reminder_fee_2') ?: '0,00') : ($next === 3 ? (setting('reminder_fee_3') ?: '0,00') : '0,00')) ?>"></label>
<label class="span2">Text (leer = Standardtext der Stufe)<textarea name="text" rows="3"></textarea></label>
<div><button class="btn"><?= $od ? 'Mahnung erstellen' : 'Erinnerung/Mahnung erstellen (noch nicht überfällig)' ?></button></div>
</form>
<?php endif; ?></div>
<?php endif; ?>
<?php require __DIR__ . '/_maillog.php'; ?>
