<?php $ex = offer_expired($o); $name = $o['company'] !== '' ? $o['company'] : trim($o['firstname'] . ' ' . $o['lastname']); $locked = $o['status'] !== 'open' || $o['invoice_id']; ?>
<div class="head"><h1>Angebot <?= e($o['offer_number']) ?> <span class="badge <?= $o['status'] === 'accepted' ? 'paid' : ($o['status'] === 'declined' ? 'cancelled' : ($ex ? 'open overdue' : 'open')) ?>"><?= e(offer_status_label($o['status'], $ex)) ?></span></h1>
<div class="actions">
<a class="btn primary" href="<?= e(url('offer_pdf', ['id' => $o['id']])) ?>" target="_blank" rel="noopener">PDF ansehen</a>
<a class="btn" href="<?= e(url('offer_pdf', ['id' => $o['id'], 'download' => 1])) ?>">PDF herunterladen</a>
<?php if (!$locked): ?><a class="btn" href="<?= e(url('offer_edit', ['id' => $o['id']])) ?>">Bearbeiten</a><?php endif; ?>
</div></div>
<div class="card cols">
<div><h3>Kunde</h3><p><a href="<?= e(url('customer_edit', ['id' => $o['customer_id']])) ?>"><?= e($name) ?></a></p><pre><?= e($o['customer_address']) ?></pre></div>
<div><h3>Daten</h3><dl><dt>Angebotsdatum</dt><dd><?= e(date_de($o['offer_date'])) ?></dd><dt>Gültig bis</dt><dd><?= e(date_de($o['valid_until'])) ?></dd>
<?php if ($o['invoice_id']): ?><dt>Rechnung</dt><dd><a href="<?= e(url('invoice_show', ['id' => $o['invoice_id']])) ?>">ansehen</a></dd><?php endif; ?></dl></div></div>
<?php if ($o['subject'] !== ''): ?><p><strong><?= e($o['subject']) ?></strong></p><?php endif; ?>
<div class="tablewrap"><table><thead><tr><th>Pos.</th><th>Beschreibung</th><th class="r">Menge</th><th>Einheit</th><th class="r">Einzelpreis</th><?php if (!$o['small_business']): ?><th class="r">USt</th><?php endif; ?><th class="r">Netto</th></tr></thead><tbody>
<?php foreach ($items as $n => $it): ?><tr><td><?= $n + 1 ?></td><td class="pre"><?= e($it['description']) ?></td><td class="r"><?= e(qty_fmt((float)$it['quantity'])) ?></td><td><?= e($it['unit']) ?></td><td class="r"><?= e(money((int)$it['unit_price'])) ?></td><?php if (!$o['small_business']): ?><td class="r"><?= e(qty_fmt((float)$it['vat_rate'])) ?> %</td><?php endif; ?><td class="r"><?= e(money((int)$it['total'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<div class="totals"><div>Netto <strong><?= e(money((int)$o['net_amount'])) ?></strong></div><?php if (!$o['small_business']): ?><div>Umsatzsteuer <strong><?= e(money((int)$o['vat_amount'])) ?></strong></div><?php endif; ?><div class="big">Gesamt <strong><?= e(money((int)$o['gross_amount'])) ?></strong></div></div>
<div class="card"><h3>Weiterverarbeitung</h3><div class="actions">
<?php if (!$o['invoice_id']): ?>
<form method="post" action="<?= e(url('offer_to_invoice')) ?>" class="inline" data-confirm="Aus diesem Angebot eine Rechnung erstellen?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><button class="btn primary">In Rechnung umwandeln</button></form>
<?php if ($o['status'] === 'open'): foreach (['accept' => 'Als angenommen markieren', 'decline' => 'Als abgelehnt markieren'] as $a => $l): ?>
<form method="post" action="<?= e(url('offer_status')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="action" value="<?= $a ?>"><button class="btn"><?= e($l) ?></button></form>
<?php endforeach; else: ?>
<form method="post" action="<?= e(url('offer_status')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="action" value="reopen"><button class="btn">Wieder auf offen setzen</button></form>
<?php endif; ?>
<form method="post" action="<?= e(url('offer_delete')) ?>" class="inline" data-confirm="Angebot wirklich löschen?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><button class="btn danger">Löschen</button></form>
<?php else: ?><a class="btn" href="<?= e(url('invoice_show', ['id' => $o['invoice_id']])) ?>">Zur Rechnung</a><?php endif; ?>
</div></div>
