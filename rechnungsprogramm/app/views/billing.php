<?php $cur = $t['currency'] ?: csetting('currency', 'EUR'); $paid = in_array($t['status'], ['active', 'past_due', 'canceled'], true) && $t['provider_subscription'] !== ''; ?>
<div class="head"><h1>Abo &amp; Zahlung</h1><span class="badge <?= e($t['status']) ?>"><?= e(tenant_status_label($t['status'])) ?></span></div>
<div class="card">
<p><strong>Aktueller Tarif:</strong> <?= e($t['plan_name'] ?? '–') ?><?= $t['price_cents'] ? ' – ' . e(money_c((int)$t['price_cents'], $cur)) . ' / ' . ($t['interval_unit'] === 'year' ? 'Jahr' : 'Monat') : '' ?></p>
<?php if ($t['status'] === 'trial'): ?><p>Ihre Testphase läuft bis <strong><?= e(date_de($t['trial_ends'])) ?></strong>. Danach ist ein Tarif nötig – Ihre Daten bleiben erhalten.</p><?php endif; ?>
<?php if ($t['status'] === 'expired'): ?><p class="msg err">Ihr Zugang ist abgelaufen. Mit einem Tarif sind Sie sofort wieder startklar.</p><?php endif; ?>
<?php if ($t['period_end'] && $paid): ?><p><?= (int)$t['cancel_at_period_end'] || $t['status'] === 'canceled' ? 'Ihr Abo endet am' : 'Nächste Verlängerung / bezahlt bis' ?> <strong><?= e(date_de($t['period_end'])) ?></strong>. Zahlungsart: <?= $t['provider'] === 'paypal' ? 'PayPal' : 'Kreditkarte' ?>.</p><?php endif; ?>
<p class="muted">Nutzung: <?= (int)$usage['users'] ?> Benutzer<?= (int)$t['max_users'] ? ' von ' . (int)$t['max_users'] : '' ?> · <?= (int)$usage['invoices'] ?> Rechnungen in diesem Monat<?= (int)$t['max_invoices'] ? ' von ' . (int)$t['max_invoices'] : '' ?></p>
<div class="actions">
<?php if ($t['provider'] === 'stripe' && $t['provider_customer'] !== ''): ?><a class="btn" href="<?= e(url('billing_portal')) ?>">Zahlungsdaten &amp; Rechnungen verwalten</a><?php endif; ?>
<?php if ($paid && !(int)$t['cancel_at_period_end'] && $t['status'] !== 'canceled'): ?><form method="post" action="<?= e(url('billing_cancel')) ?>" class="inline" data-confirm="Abo zum Ende der bezahlten Periode kündigen?"><?= csrf_field() ?><button class="btn danger">Abo kündigen</button></form><?php endif; ?>
</div></div>

<?php if (!$ready['stripe'] && !$ready['paypal']): ?><div class="msg warn">Die Online-Zahlung ist noch nicht eingerichtet. Bitte wenden Sie sich an den Betreiber.</div><?php endif; ?>
<h2>Tarif wählen</h2>
<div class="pgrid"><?php foreach ($plans as $p): $cur_p = (int)$p['id'] === (int)$t['plan_id']; ?>
<div class="pcard<?= $cur_p ? ' on' : '' ?>"><h3><?= e($p['name']) ?><?= $cur_p ? ' <span class="badge paid">aktuell</span>' : '' ?></h3><p class="muted"><?= e($p['description']) ?></p>
<div class="pprice"><?= e(money_c((int)$p['price_cents'], $p['currency'])) ?><small> / <?= $p['interval_unit'] === 'year' ? 'Jahr' : 'Monat' ?></small></div>
<ul class="plist"><li><?= (int)$p['max_users'] > 0 ? e((int)$p['max_users']) . ' Benutzer' : 'Unbegrenzt Benutzer' ?></li><li><?= (int)$p['max_invoices'] > 0 ? 'bis ' . e((int)$p['max_invoices']) . ' Rechnungen / Monat' : 'Unbegrenzt Rechnungen' ?></li></ul>
<?php if (($ready['stripe'] || $ready['paypal']) && !($paid && $cur_p && $t['status'] === 'active')): ?>
<form method="post" action="<?= e(url('billing_checkout')) ?>" class="paybtns"><?= csrf_field() ?><input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
<?php if ($ready['stripe']): ?><button class="btn primary" name="provider" value="stripe">Mit Kreditkarte zahlen</button><?php endif; ?>
<?php if ($ready['paypal']): ?><button class="btn" name="provider" value="paypal">Mit PayPal zahlen</button><?php endif; ?></form>
<?php endif; ?></div>
<?php endforeach; ?></div>
<p class="muted small">Der Tarifwechsel bei einem laufenden Abo erfolgt durch Kündigung und Neubuchung; Fragen beantwortet der Support.</p>

<h2>Zahlungen</h2>
<?php if ($payments): ?><div class="tablewrap"><table><thead><tr><th>Datum</th><th>Beschreibung</th><th>Zahlungsart</th><th class="r">Betrag</th></tr></thead><tbody>
<?php foreach ($payments as $p): ?><tr><td><?= e(date_de($p['created_at'])) ?></td><td><?= e($p['description']) ?></td><td><?= $p['provider'] === 'paypal' ? 'PayPal' : 'Kreditkarte' ?></td><td class="r"><?= e(money_c((int)$p['amount_cents'], $p['currency'])) ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php else: ?><p class="muted">Noch keine Zahlungen.</p><?php endif; ?>
