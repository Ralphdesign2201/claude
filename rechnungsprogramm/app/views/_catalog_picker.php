<?php /* Katalogdaten für die Auswahl im Beschreibungsfeld: $catalog (Liste), $withPrice (bool). Keine Ausgabe ohne Recht/Einträge. */
if (!empty($catalog)):
    $cat = []; foreach ($catalog as $c) $cat[] = ['k' => $c['kind'] === 'article' ? 'Artikel' : 'Leistung', 'n' => (string)$c['number'], 'name' => (string)$c['name'], 'd' => (string)$c['description'], 'u' => (string)$c['unit'], 'p' => money_plain((int)$c['price_cents']), 'v' => qty_fmt((float)$c['vat_rate'])];
?>
<div id="catdata" hidden data-price="<?= !empty($withPrice) ? '1' : '0' ?>" data-catalog="<?= e(json_encode($cat, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>"></div>
<p class="muted small">Tipp: Nummer oder Text in die Beschreibung tippen – passende Leistungen und Artikel erscheinen zur Auswahl.</p>
<?php endif; ?>
