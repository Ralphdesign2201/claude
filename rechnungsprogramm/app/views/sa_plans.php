<div class="head"><h1>Tarife</h1><a class="btn primary" href="<?= e(url('sa_plan_edit')) ?>">+ Neuer Tarif</a></div>
<div class="tablewrap"><table><thead><tr><th>Name</th><th class="r">Preis</th><th>Limits</th><th>Mandanten</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($plans as $p): ?><tr><td><a href="<?= e(url('sa_plan_edit', ['id' => $p['id']])) ?>"><?= e($p['name']) ?></a><?= (int)$p['id'] === $default ? ' <span class="badge open">Standard bei Registrierung</span>' : '' ?><br><small class="muted"><?= e($p['description']) ?></small></td>
<td class="r"><?= e(money_c((int)$p['price_cents'], $p['currency'])) ?> / <?= $p['interval_unit'] === 'year' ? 'Jahr' : 'Monat' ?></td>
<td><?= (int)$p['max_users'] ? e((int)$p['max_users']) . ' Benutzer' : 'Benutzer unbegrenzt' ?>, <?= (int)$p['max_invoices'] ? e((int)$p['max_invoices']) . ' Rechnungen/Monat' : 'Rechnungen unbegrenzt' ?></td>
<td><?= (int)$p['n'] ?></td><td><span class="badge <?= $p['active'] ? 'paid' : 'cancelled' ?>"><?= $p['active'] ? 'Aktiv' : 'Inaktiv' ?></span></td>
<td class="r"><form method="post" action="<?= e(url('sa_plan_delete')) ?>" class="inline" data-confirm="Tarif löschen?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn ghost">Löschen</button></form></td></tr><?php endforeach; ?></tbody></table></div>
