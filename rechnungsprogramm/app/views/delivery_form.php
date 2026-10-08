<div class="head"><h1><?= $d['id'] ? 'Lieferschein ' . e($d['note_number']) . ' bearbeiten' : 'Neuer Lieferschein' ?></h1></div>
<form method="post" action="<?= e(url('delivery_save')) ?>" class="card" id="delform">
<?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="from_invoice" value="<?= (int)$invoiceId ?>">
<div class="grid">
<label class="span2">Kunde<select name="customer_id" required><option value="">– bitte wählen –</option>
<?php foreach ($customers as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)$d['customer_id'] === (int)$c['id'] ? ' selected' : '' ?>><?= e(customer_name($c)) ?><?= $c['city'] !== '' ? ' (' . e($c['city']) . ')' : '' ?></option><?php endforeach; ?></select></label>
<label>Lieferdatum<input type="date" name="note_date" value="<?= e($d['note_date']) ?>" required></label>
<label>Betreff<input name="subject" value="<?= e($d['subject']) ?>"></label>
<label class="span2">Einleitung<textarea name="intro" rows="2"><?= e($d['intro']) ?></textarea></label>
</div>
<h2>Positionen</h2>
<div class="tablewrap"><table class="items" id="items"><thead><tr><th>Beschreibung</th><th>Menge</th><th>Einheit</th><th></th></tr></thead><tbody>
<?php foreach ($items as $it): ?><tr class="item"><td><textarea name="description[]" rows="1"><?= e($it['description']) ?></textarea></td>
<td><input name="quantity[]" class="num qty" inputmode="decimal" value="<?= e(qty_fmt((float)$it['quantity'])) ?>"></td>
<td><input name="unit[]" class="unit" value="<?= e($it['unit']) ?>" list="units"></td>
<td><button type="button" class="btn ghost rm" title="Position entfernen">×</button></td></tr><?php endforeach; ?>
</tbody></table></div>
<datalist id="units"><option>Stk.</option><option>Std.</option><option>m</option><option>m²</option><option>kg</option><option>Pkg.</option></datalist>
<p><button type="button" class="btn" id="addrow">+ Position hinzufügen</button></p>
<label>Hinweis (optional)<textarea name="notes" rows="2"><?= e($d['notes']) ?></textarea></label>
<div class="actions"><button class="btn primary">Speichern</button><a class="btn ghost" href="<?= e($d['id'] ? url('delivery_show', ['id' => $d['id']]) : url('deliveries')) ?>">Abbrechen</a></div>
</form>
