<div class="head"><h1>Lieferscheine</h1><?php if (can('deliveries', 'w')): ?><a class="btn primary" href="<?= e(url('delivery_new')) ?>">+ Neuer Lieferschein</a><?php endif; ?></div>
<form method="get" class="filter"><input type="hidden" name="r" value="deliveries"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Nummer, Kunde, Betreff"><button class="btn">Suchen</button></form>
<?php if (!$notes): ?><p class="muted">Keine Lieferscheine gefunden.</p><?php else: ?>
<div class="tablewrap"><table><thead><tr><th>Nr.</th><th>Datum</th><th>Kunde</th><th>Betreff</th></tr></thead><tbody>
<?php foreach ($notes as $d): ?><tr><td><a href="<?= e(url('delivery_show', ['id' => $d['id']])) ?>"><?= e($d['note_number']) ?></a></td><td><?= e(date_de($d['note_date'])) ?></td>
<td><?= e($d['company'] !== '' ? $d['company'] : trim($d['firstname'] . ' ' . $d['lastname'])) ?></td><td><?= e($d['subject']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
