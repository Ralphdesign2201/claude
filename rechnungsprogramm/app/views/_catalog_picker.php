<?php /* Auswahl aus dem Katalog: $catalog (Liste), $withPrice (bool) */ if (!empty($catalog)): ?>
<div class="catalog-pick" id="catpick" data-price="<?= !empty($withPrice) ? '1' : '0' ?>">
<label>Leistung oder Artikel aus dem Katalog einfügen<input type="search" id="catsearch" list="catlist" placeholder="Tippen zum Suchen, dann auswählen …" autocomplete="off"></label>
<button type="button" class="btn" id="catadd">Einfügen</button>
<datalist id="catlist"><?php foreach ($catalog as $c): $label = trim(($c['number'] !== '' ? $c['number'] . ' · ' : '') . $c['name']) . (!empty($withPrice) ? ' (' . money_plain((int)$c['price_cents']) . ' €/' . ($c['unit'] ?: 'Einh.') . ')' : ''); ?>
<option value="<?= e($label) ?>" data-name="<?= e($c['name']) ?>" data-desc="<?= e($c['description']) ?>" data-unit="<?= e($c['unit']) ?>" data-price="<?= e(money_plain((int)$c['price_cents'])) ?>" data-vat="<?= e(qty_fmt((float)$c['vat_rate'])) ?>"></option><?php endforeach; ?></datalist>
</div>
<?php endif; ?>
