<div class="head"><h1>Kunden</h1><?php if (can('customers', 'w')): ?><a class="btn primary" href="<?= e(url('customer_edit')) ?>">+ Neuer Kunde</a><?php endif; ?></div>
<form method="get" class="filter"><input type="hidden" name="r" value="customers">
<input type="search" name="q" value="<?= e($q) ?>" placeholder="Suchen (Name, Ort, E-Mail, Telefon)"><button class="btn">Suchen</button></form>
<?php if (!$customers): ?><p class="muted">Keine Kunden gefunden.</p><?php else: ?>
<div class="tablewrap"><table><thead><tr><th>Kunde</th><th>Ort</th><th>Telefon</th><th>E-Mail</th><th class="r">Rechnungen</th></tr></thead><tbody>
<?php foreach ($customers as $c): ?>
<tr><td><a href="<?= e(url('customer_edit', ['id' => $c['id']])) ?>"><?= e(customer_name($c)) ?></a><?php if ($c['company'] !== '' && $c['contact_person'] !== ''): ?><br><small class="muted"><?= e($c['contact_person']) ?></small><?php endif; ?></td>
<td><?= e(trim($c['zip'] . ' ' . $c['city'])) ?></td><td><?= e($c['phone']) ?></td><td><?= e($c['email']) ?></td><td class="r"><?= (int)$c['inv_count'] ?></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?>
