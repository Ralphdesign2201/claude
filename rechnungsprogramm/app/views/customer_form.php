<?php $old = $_SESSION['old'] ?? null; unset($_SESSION['old']); if ($old) $c = $old + $c; ?>
<div class="head"><h1><?= $c['id'] ? 'Kunde bearbeiten' : 'Neuer Kunde' ?></h1></div>
<form method="post" action="<?= e(url('customer_save')) ?>" class="card">
<?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
<div class="grid">
<label class="span2">Firma<input name="company" value="<?= e($c['company']) ?>" autofocus></label>
<label class="span2">Ansprechpartner<input name="contact_person" value="<?= e($c['contact_person']) ?>"></label>
<label>Vorname<input name="firstname" value="<?= e($c['firstname']) ?>"></label>
<label>Nachname<input name="lastname" value="<?= e($c['lastname']) ?>"></label>
<label class="span2">Straße und Hausnummer<input name="street" value="<?= e($c['street']) ?>"></label>
<label>PLZ<input name="zip" value="<?= e($c['zip']) ?>" maxlength="10"></label>
<label>Ort<input name="city" value="<?= e($c['city']) ?>"></label>
<label>Telefon<input type="tel" name="phone" value="<?= e($c['phone']) ?>"></label>
<label>E-Mail<input type="email" name="email" value="<?= e($c['email']) ?>"></label>
<label class="span2">Leitweg-ID (nur für Behörden / XRechnung)<input name="leitweg_id" value="<?= e($c['leitweg_id'] ?? '') ?>" placeholder="z. B. 991-12345-67"></label>
<label class="span2">Notizen<textarea name="notes" rows="3"><?= e($c['notes']) ?></textarea></label>
</div>
<p class="muted">Firma <em>oder</em> Nachname ist erforderlich.</p>
<div class="actions"><?php if (can('customers', 'w')): ?><button class="btn primary">Speichern</button>
<?php if (!$c['id']): ?><button class="btn" name="then_invoice" value="1">Speichern &amp; Rechnung erstellen</button><?php endif; ?><?php endif; ?>
<a class="btn ghost" href="<?= e(url('customers')) ?>">Abbrechen</a></div>
</form>
<?php if ($c['id']): ?>
<div class="head"><h2>Rechnungen dieses Kunden</h2><a class="btn" href="<?= e(url('invoice_new', ['customer_id' => $c['id']])) ?>">+ Rechnung</a></div>
<?php if ($invoices): ?><div class="tablewrap"><table><thead><tr><th>Nr.</th><th>Datum</th><th class="r">Betrag</th><th>Status</th></tr></thead><tbody>
<?php foreach ($invoices as $i): ?><tr><td><a href="<?= e(url('invoice_show', ['id' => $i['id']])) ?>"><?= e($i['invoice_number']) ?></a></td><td><?= e(date_de($i['invoice_date'])) ?></td><td class="r"><?= e(money((int)$i['gross_amount'])) ?></td><td><span class="badge <?= $i['status'] ?><?= is_overdue($i) ? ' overdue' : '' ?>"><?= e(status_label($i['status'], is_overdue($i))) ?></span></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php else: ?>
<form method="post" action="<?= e(url('customer_delete')) ?>" data-confirm="Kunde wirklich löschen?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn danger">Kunde löschen</button></form>
<?php endif; endif; ?>
