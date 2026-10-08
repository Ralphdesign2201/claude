<?php $name = $d['company'] !== '' ? $d['company'] : trim($d['firstname'] . ' ' . $d['lastname']); ?>
<div class="head"><h1>Lieferschein <?= e($d['note_number']) ?></h1><div class="actions">
<a class="btn primary" href="<?= e(url('delivery_pdf', ['id' => $d['id']])) ?>" target="_blank" rel="noopener">PDF ansehen</a>
<a class="btn" href="<?= e(url('delivery_pdf', ['id' => $d['id'], 'download' => 1])) ?>">PDF herunterladen</a>
<a class="btn" href="<?= e(url('mail_new', ['type' => 'delivery', 'id' => $d['id']])) ?>">Per E-Mail senden</a>
<a class="btn" href="<?= e(url('delivery_edit', ['id' => $d['id']])) ?>">Bearbeiten</a>
<form method="post" action="<?= e(url('delivery_delete')) ?>" class="inline" data-confirm="Lieferschein wirklich löschen?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn danger">Löschen</button></form>
</div></div>
<div class="card cols"><div><h3>Kunde</h3><p><a href="<?= e(url('customer_edit', ['id' => $d['customer_id']])) ?>"><?= e($name) ?></a></p><pre><?= e($d['customer_address']) ?></pre></div>
<div><h3>Daten</h3><dl><dt>Lieferdatum</dt><dd><?= e(date_de($d['note_date'])) ?></dd><?php if ($d['invoice_id']): ?><dt>Rechnung</dt><dd><a href="<?= e(url('invoice_show', ['id' => $d['invoice_id']])) ?>">ansehen</a></dd><?php endif; ?></dl></div></div>
<?php if ($d['subject'] !== ''): ?><p><strong><?= e($d['subject']) ?></strong></p><?php endif; ?>
<div class="tablewrap"><table><thead><tr><th>Pos.</th><th>Beschreibung</th><th class="r">Menge</th><th>Einheit</th></tr></thead><tbody>
<?php foreach ($items as $n => $it): ?><tr><td><?= $n + 1 ?></td><td class="pre"><?= e($it['description']) ?></td><td class="r"><?= e(qty_fmt((float)$it['quantity'])) ?></td><td><?= e($it['unit']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php require __DIR__ . '/_maillog.php'; ?>
