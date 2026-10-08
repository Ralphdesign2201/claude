<div class="head"><h1>Rechnungen</h1><?php if (can('invoices', 'w')): ?><a class="btn primary" href="<?= e(url('invoice_new')) ?>">+ Neue Rechnung</a><?php endif; ?></div>
<form method="get" class="filter"><input type="hidden" name="r" value="invoices">
<select name="status"><?php foreach (['' => 'Alle Status', 'open' => 'Offen', 'overdue' => 'Überfällig', 'paid' => 'Bezahlt', 'cancelled' => 'Storniert'] as $k => $l): ?><option value="<?= e($k) ?>"<?= $status === (string)$k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
<select name="year"><option value="">Alle Jahre</option><?php foreach ($years as $y): ?><option<?= $year === $y ? ' selected' : '' ?>><?= e($y) ?></option><?php endforeach; ?></select>
<input type="search" name="q" value="<?= e($q) ?>" placeholder="Nummer, Kunde, Betreff"><button class="btn">Filtern</button></form>
<?php if (!$invoices): ?><p class="muted">Keine Rechnungen gefunden.</p><?php else: $sum = 0; ?>
<div class="tablewrap"><table><thead><tr><th>Nr.</th><th>Datum</th><th>Kunde</th><th>Betreff</th><th>Fällig</th><th class="r">Brutto</th><th>Status</th></tr></thead><tbody>
<?php foreach ($invoices as $i): if ($i['status'] !== 'cancelled') $sum += (int)$i['gross_amount']; ?>
<tr><td><a href="<?= e(url('invoice_show', ['id' => $i['id']])) ?>"><?= e($i['invoice_number']) ?></a></td><td><?= e(date_de($i['invoice_date'])) ?></td>
<td><?= e($i['company'] !== '' ? $i['company'] : trim($i['firstname'] . ' ' . $i['lastname'])) ?></td><td><?= e($i['subject']) ?></td><td><?= e(date_de($i['due_date'])) ?></td>
<td class="r"><?= e(money((int)$i['gross_amount'])) ?></td><td><span class="badge <?= $i['status'] ?><?= is_overdue($i) ? ' overdue' : '' ?>"><?= e(status_label($i['status'], is_overdue($i))) ?></span></td></tr>
<?php endforeach; ?></tbody><tfoot><tr><td colspan="5">Summe (ohne stornierte)</td><td class="r"><?= e(money($sum)) ?></td><td></td></tr></tfoot></table></div>
<?php endif; ?>
