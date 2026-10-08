<?php $vatOpts = [19, 7, 0]; ?>
<div class="head"><h1><?= $inv['id'] ? 'Rechnung ' . e($inv['invoice_number']) . ' bearbeiten' : 'Neue Rechnung' ?></h1></div>
<form method="post" action="<?= e(url('invoice_save')) ?>" class="card" id="invform" data-small="<?= $small ? '1' : '0' ?>">
<?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$inv['id'] ?>">
<div class="grid">
<label class="span2">Kunde<select name="customer_id" required>
<option value="">– bitte wählen –</option>
<?php foreach ($customers as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)$inv['customer_id'] === (int)$c['id'] ? ' selected' : '' ?>><?= e(customer_name($c)) ?><?= $c['city'] !== '' ? ' (' . e($c['city']) . ')' : '' ?></option><?php endforeach; ?>
</select></label>
<label>Rechnungsdatum<input type="date" name="invoice_date" value="<?= e($inv['invoice_date']) ?>" required></label>
<label>Zahlbar bis<input type="date" name="due_date" value="<?= e($inv['due_date']) ?>" required></label>
<label>Leistungsdatum / -zeitraum<input name="service_date" value="<?= e($inv['service_date']) ?>" placeholder="z. B. 03.–05.10.2026"></label>
<label>Betreff<input name="subject" value="<?= e($inv['subject']) ?>" placeholder="z. B. Badsanierung Musterstraße 5"></label>
<label class="span2">Einleitung<textarea name="intro" rows="2"><?= e($inv['intro']) ?></textarea></label>
</div>
<h2>Positionen</h2>
<div class="tablewrap"><table class="items" id="items"><thead><tr><th>Beschreibung</th><th>Menge</th><th>Einheit</th><th>Einzelpreis (netto)</th><?php if (!$small): ?><th>USt %</th><?php endif; ?><th class="r">Netto</th><th></th></tr></thead><tbody>
<?php foreach ($items as $it): ?>
<tr class="item">
<td><textarea name="description[]" rows="1" required><?= e($it['description']) ?></textarea></td>
<td><input name="quantity[]" class="num qty" inputmode="decimal" value="<?= e(qty_fmt((float)$it['quantity'])) ?>"></td>
<td><input name="unit[]" class="unit" value="<?= e($it['unit']) ?>" list="units"></td>
<td><input name="unit_price[]" class="num price" inputmode="decimal" value="<?= e(money_plain((int)$it['unit_price'])) ?>"></td>
<?php if (!$small): ?><td><select name="vat_rate[]" class="vat"><?php $r = (float)$it['vat_rate']; $opts = in_array($r, [19.0, 7.0, 0.0], true) ? [19, 7, 0] : [19, 7, 0, $r]; foreach ($opts as $o): ?><option value="<?= e($o) ?>"<?= (float)$o === $r ? ' selected' : '' ?>><?= e(qty_fmt((float)$o)) ?></option><?php endforeach; ?></select></td><?php endif; ?>
<td class="r line">0,00</td>
<td><button type="button" class="btn ghost rm" title="Position entfernen">×</button></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<datalist id="units"><option>Std.</option><option>Stk.</option><option>m</option><option>m²</option><option>m³</option><option>kg</option><option>pauschal</option><option>km</option></datalist>
<p><button type="button" class="btn" id="addrow">+ Position hinzufügen</button></p>
<div class="totals"><div>Netto <strong id="t-net">0,00 €</strong></div><?php if (!$small): ?><div>Umsatzsteuer <strong id="t-vat">0,00 €</strong></div><?php endif; ?><div class="big">Gesamt <strong id="t-gross">0,00 €</strong></div></div>
<?php if ($small): ?><p class="muted">Kleinunternehmer (§ 19 UStG): Es wird keine Umsatzsteuer ausgewiesen.</p><?php endif; ?>
<label>Hinweis / Schlusstext (optional)<textarea name="notes" rows="2"><?= e($inv['notes']) ?></textarea></label>
<div class="actions"><button class="btn primary">Speichern</button><a class="btn ghost" href="<?= e($inv['id'] ? url('invoice_show', ['id' => $inv['id']]) : url('invoices')) ?>">Abbrechen</a></div>
</form>
