<div class="head"><h1><?= $p['id'] ? 'Tarif bearbeiten' : 'Neuer Tarif' ?></h1></div>
<form method="post" action="<?= e(url('sa_plan_save')) ?>" class="card"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
<div class="grid">
<label>Name<input name="name" value="<?= e($p['name']) ?>" required></label><label>Reihenfolge<input type="number" name="sort" value="<?= (int)$p['sort'] ?>"></label>
<label class="span2">Beschreibung (Kurztext)<input name="description" value="<?= e($p['description']) ?>"></label>
<label>Preis (€)<input name="price" inputmode="decimal" value="<?= e(money_plain((int)$p['price_cents'])) ?>" required></label>
<label>Abrechnung<select name="interval_unit"><option value="month"<?= $p['interval_unit'] === 'month' ? ' selected' : '' ?>>monatlich</option><option value="year"<?= $p['interval_unit'] === 'year' ? ' selected' : '' ?>>jährlich</option></select></label>
<label>Währung<input name="currency" value="<?= e($p['currency']) ?>" maxlength="3"></label><span></span>
<label>Max. Benutzer (0 = unbegrenzt)<input type="number" min="0" name="max_users" value="<?= (int)$p['max_users'] ?>"></label>
<label>Max. Rechnungen pro Monat (0 = unbegrenzt)<input type="number" min="0" name="max_invoices" value="<?= (int)$p['max_invoices'] ?>"></label>
<label class="span2 check"><input type="checkbox" name="active" value="1"<?= $p['active'] ? ' checked' : '' ?>> Tarif ist buchbar (auf der Startseite sichtbar)</label>
<label class="span2 check"><input type="checkbox" name="make_default" value="1"> Als Standard-Tarif für neue Registrierungen verwenden</label>
</div>
<p class="muted">Der Tarif wird beim Zahlungsanbieter (Stripe/PayPal) automatisch angelegt, sobald er zum ersten Mal gebucht wird. Preisänderungen gelten nur für neue Abos.</p>
<div class="actions"><button class="btn primary">Speichern</button><a class="btn ghost" href="<?= e(url('sa_plans')) ?>">Abbrechen</a></div></form>
