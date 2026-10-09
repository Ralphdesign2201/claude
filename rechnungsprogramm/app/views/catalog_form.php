<div class="head"><h1><?= $i['id'] ? 'Eintrag bearbeiten' : 'Neuer Eintrag' ?></h1></div>
<form method="post" action="<?= e(url('catalog_save')) ?>" class="card"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
<div class="grid">
<label>Art<select name="kind"><?php foreach (CATALOG_KINDS as $k => $l): ?><option value="<?= e($k) ?>"<?= $i['kind'] === $k ? ' selected' : '' ?>><?= e($l) ?><?= $k === 'service' ? ' (z. B. Arbeitsstunde, Anfahrt)' : ' (Material, Ware)' ?></option><?php endforeach; ?></select></label>
<label>Nummer / Artikelnummer (optional)<input name="number" value="<?= e($i['number']) ?>" maxlength="60"></label>
<label class="span2">Bezeichnung<input name="name" value="<?= e($i['name']) ?>" required maxlength="190" autofocus placeholder="z. B. Arbeitsstunde Geselle"></label>
<label class="span2">Beschreibung (optional, erscheint auf der Rechnung unter der Bezeichnung)<textarea name="description" rows="2" maxlength="1000"><?= e($i['description']) ?></textarea></label>
<label>Einheit<input name="unit" value="<?= e($i['unit']) ?>" list="units" maxlength="30"></label>
<label>USt-Satz %<input name="vat_rate" value="<?= e(qty_fmt((float)$i['vat_rate'])) ?>" inputmode="decimal"></label>
<label>Preis netto (€)<input name="price" value="<?= e(money_plain((int)$i['price_cents'])) ?>" inputmode="decimal"></label>
<label>Einkaufspreis netto (€, optional – für die Marge)<input name="cost" value="<?= e(money_plain((int)$i['cost_cents'])) ?>" inputmode="decimal"></label>
<label class="span2 check"><input type="checkbox" name="active" value="1"<?= $i['active'] ? ' checked' : '' ?>> Aktiv (in der Auswahl für Rechnungen sichtbar)</label>
</div>
<datalist id="units"><option>Std.</option><option>Stk.</option><option>m</option><option>m²</option><option>m³</option><option>kg</option><option>pauschal</option><option>km</option></datalist>
<div class="actions"><button class="btn primary">Speichern</button><a class="btn ghost" href="<?= e(url('catalog')) ?>">Abbrechen</a></div>
</form>
