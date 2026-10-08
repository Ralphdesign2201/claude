<?php $lbl = fn($s) => tenant_status_label($s); ?>
<div class="head"><h1>Dashboard</h1></div>
<?php if (!$ready['stripe'] && !$ready['paypal']): ?><div class="msg warn">Es ist noch kein Zahlungsanbieter eingerichtet – Kunden können nicht zahlen. <a href="<?= e(url('sa_settings')) ?>">Jetzt PayPal / Kreditkarte einrichten</a></div><?php endif; ?>
<div class="tiles">
<div class="tile"><span>Mandanten gesamt</span><strong><?= (int)$total ?></strong><small><?= (int)$new7 ?> neu in 7 Tagen</small></div>
<div class="tile"><span>Zahlende (aktiv)</span><strong><?= (int)($by['active'] ?? 0) ?></strong><small><?= (int)($by['trial'] ?? 0) ?> in der Testphase</small></div>
<div class="tile"><span>Monatl. wiederkehrender Umsatz</span><strong><?= e(money_c($mrr, $cur)) ?></strong><small>aus aktiven Abos</small></div>
<div class="tile"><span>Einnahmen (30 Tage)</span><strong><?= e(money_c($rev30, $cur)) ?></strong><small>verbuchte Zahlungen</small></div>
<a class="tile <?= ($by['past_due'] ?? 0) ? 'bad' : '' ?>" href="<?= e(url('sa_tenants', ['status' => 'past_due'])) ?>"><span>Zahlung offen</span><strong><?= (int)($by['past_due'] ?? 0) ?></strong><small>Mahnung nötig</small></a>
<a class="tile" href="<?= e(url('sa_tenants', ['status' => 'expired'])) ?>"><span>Abgelaufen / gekündigt</span><strong><?= (int)(($by['expired'] ?? 0) + ($by['canceled'] ?? 0)) ?></strong><small><?= (int)($by['suspended'] ?? 0) ?> gesperrt</small></a>
</div>
<h2>Neueste Mandanten</h2>
<div class="tablewrap"><table><thead><tr><th>Firma</th><th>ID</th><th>Tarif</th><th>Status</th><th>Angelegt</th></tr></thead><tbody>
<?php foreach ($recent as $t): ?><tr><td><a href="<?= e(url('sa_tenant', ['id' => $t['id']])) ?>"><?= e($t['company']) ?></a></td><td><?= e($t['slug']) ?></td><td><?= e($t['plan_name'] ?? '–') ?></td><td><span class="badge <?= e($t['status']) ?>"><?= e($lbl($t['status'])) ?></span></td><td><?= e(date_de($t['created_at'])) ?></td></tr><?php endforeach; ?>
<?php if (!$recent): ?><tr><td colspan="5" class="muted">Noch keine Mandanten.</td></tr><?php endif; ?></tbody></table></div>
<h2>Letzte Zahlungen</h2>
<div class="tablewrap"><table><thead><tr><th>Datum</th><th>Mandant</th><th>Anbieter</th><th class="r">Betrag</th></tr></thead><tbody>
<?php foreach ($pays as $p): ?><tr><td><?= e(date_de($p['created_at'])) ?></td><td><?= e($p['company']) ?></td><td><?= e($p['provider']) ?></td><td class="r"><?= e(money_c((int)$p['amount_cents'], $p['currency'])) ?></td></tr><?php endforeach; ?>
<?php if (!$pays): ?><tr><td colspan="4" class="muted">Noch keine Zahlungen.</td></tr><?php endif; ?></tbody></table></div>
<div class="card"><h2>Cronjob (täglich einrichten)</h2><p class="muted">Der Lauf sichert alle Mandanten, aktualisiert Datenbanken nach Updates, verschickt Testphasen-Hinweise und räumt auf.</p>
<p>Befehl: <code class="copy"><?= e($cronCmd) ?></code></p><p>oder Webcron-URL: <code class="copy"><?= e($cronUrl) ?></code></p></div>
