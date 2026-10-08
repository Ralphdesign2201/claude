<div class="head"><h1>Angebote</h1><a class="btn primary" href="<?= e(url('offer_new')) ?>">+ Neues Angebot</a></div>
<form method="get" class="filter"><input type="hidden" name="r" value="offers">
<select name="status"><?php foreach (['' => 'Alle Status', 'open' => 'Offen', 'accepted' => 'Angenommen', 'declined' => 'Abgelehnt'] as $k => $l): ?><option value="<?= e($k) ?>"<?= $status === (string)$k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
<input type="search" name="q" value="<?= e($q) ?>" placeholder="Nummer, Kunde, Betreff"><button class="btn">Filtern</button></form>
<?php if (!$offers): ?><p class="muted">Keine Angebote gefunden.</p><?php else: ?>
<div class="tablewrap"><table><thead><tr><th>Nr.</th><th>Datum</th><th>Kunde</th><th>Betreff</th><th>Gültig bis</th><th class="r">Brutto</th><th>Status</th></tr></thead><tbody>
<?php foreach ($offers as $o): $ex = offer_expired($o); ?>
<tr><td><a href="<?= e(url('offer_show', ['id' => $o['id']])) ?>"><?= e($o['offer_number']) ?></a></td><td><?= e(date_de($o['offer_date'])) ?></td>
<td><?= e($o['company'] !== '' ? $o['company'] : trim($o['firstname'] . ' ' . $o['lastname'])) ?></td><td><?= e($o['subject']) ?></td><td><?= e(date_de($o['valid_until'])) ?></td>
<td class="r"><?= e(money((int)$o['gross_amount'])) ?></td><td><span class="badge <?= $o['status'] === 'accepted' ? 'paid' : ($o['status'] === 'declined' ? 'cancelled' : ($ex ? 'open overdue' : 'open')) ?>"><?= e(offer_status_label($o['status'], $ex)) ?></span></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?>
