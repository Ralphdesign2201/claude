<div class="head"><h1><?= e($t['company']) ?> <span class="badge <?= e($eff['status']) ?>"><?= e(tenant_status_label($eff['status'])) ?></span></h1>
<form method="post" action="<?= e(url('sa_tenant_action')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="action" value="login"><button class="btn primary">Als Mandant anmelden</button></form></div>
<div class="tiles"><div class="tile"><span>Benutzer</span><strong><?= (int)$stats['users'] ?></strong></div><div class="tile"><span>Kunden</span><strong><?= (int)$stats['customers'] ?></strong></div><div class="tile"><span>Rechnungen</span><strong><?= (int)$stats['invoices'] ?></strong></div><div class="tile"><span>Datenbank-Version</span><strong><?= e($t['db_version'] ?: '–') ?></strong><small>Code: <?= e(app_version()) ?></small></div></div>
<form method="post" action="<?= e(url('sa_tenant_save')) ?>" class="card"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
<div class="grid">
<label>Firma<input name="company" value="<?= e($t['company']) ?>" required></label><label>Firmen-ID<input value="<?= e($t['slug']) ?>" disabled></label>
<label>Inhaber<input name="owner_name" value="<?= e($t['owner_name']) ?>"></label><label>E-Mail<input type="email" name="owner_email" value="<?= e($t['owner_email']) ?>"></label>
<label>Tarif<select name="plan_id"><?php foreach ($plans as $p): ?><option value="<?= (int)$p['id'] ?>"<?= (int)$t['plan_id'] === (int)$p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?> (<?= e(money_c((int)$p['price_cents'], $p['currency'])) ?>)</option><?php endforeach; ?></select></label>
<label>Status<select name="status"><?php foreach (['trial', 'active', 'past_due', 'canceled', 'expired', 'suspended'] as $s): ?><option value="<?= e($s) ?>"<?= $t['status'] === $s ? ' selected' : '' ?>><?= e(tenant_status_label($s)) ?></option><?php endforeach; ?></select></label>
<label>Testphase bis<input type="date" name="trial_ends" value="<?= e((string)$t['trial_ends']) ?>"></label><label>Bezahlt bis<input type="date" name="period_end" value="<?= e((string)$t['period_end']) ?>"></label>
<label class="span2">Interne Notizen<textarea name="notes" rows="3"><?= e($t['notes']) ?></textarea></label>
</div>
<p class="muted">Zahlungsanbieter: <?= e($t['provider'] ?: '–') ?><?= $t['provider_subscription'] !== '' ? ' · Abo ' . e($t['provider_subscription']) : '' ?><?= (int)$t['cancel_at_period_end'] ? ' · <b>zum Periodenende gekündigt</b>' : '' ?> · Angelegt <?= e(date_de($t['created_at'])) ?></p>
<div class="actions"><button class="btn primary">Speichern</button><a class="btn ghost" href="<?= e(url('sa_tenants')) ?>">Zurück</a></div></form>

<div class="card"><h2>Aktionen</h2><div class="actions">
<?php foreach (['extend' => 'Testphase +7 Tage', 'suspend' => 'Sperren', 'activate' => 'Freigeben', 'migrate' => 'Datenbank aktualisieren'] as $a => $l): ?>
<form method="post" action="<?= e(url('sa_tenant_action')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="action" value="<?= e($a) ?>"><button class="btn"><?= e($l) ?></button></form>
<?php endforeach; ?></div>
<h3>Mandant löschen</h3>
<form method="post" action="<?= e(url('sa_tenant_action')) ?>" class="inline" data-confirm="Mandant samt ALLEN Daten endgültig löschen?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="action" value="delete"><input name="confirm" placeholder="Firmen-ID „<?= e($t['slug']) ?>“ eintippen" autocomplete="off"><button class="btn danger">Endgültig löschen</button></form></div>

<div class="card"><h2>Zahlungen</h2><?php if ($pays): ?><table><thead><tr><th>Datum</th><th>Anbieter</th><th>Beschreibung</th><th class="r">Betrag</th></tr></thead><tbody>
<?php foreach ($pays as $p): ?><tr><td><?= e(date_de($p['created_at'])) ?></td><td><?= e($p['provider']) ?></td><td><?= e($p['description']) ?></td><td class="r"><?= e(money_c((int)$p['amount_cents'], $p['currency'])) ?></td></tr><?php endforeach; ?></tbody></table><?php else: ?><p class="muted">Noch keine Zahlungen.</p><?php endif; ?></div>
