<div class="head"><h1>Leistungen &amp; Artikel</h1>
<?php if (can('catalog', 'w')): ?><div class="actions"><a class="btn primary" href="<?= e(url('catalog_edit', ['kind' => 'service'])) ?>">+ Leistung</a><a class="btn primary" href="<?= e(url('catalog_edit', ['kind' => 'article'])) ?>">+ Artikel</a></div><?php endif; ?></div>
<p class="muted">Hier pflegen Sie Ihre Handwerkerleistungen (z. B. Arbeitsstunde, Anfahrt) und Artikel (Material). In Rechnungen, Angeboten und Lieferscheinen fügen Sie sie mit einem Klick als Position ein.</p>
<form method="get" class="filter"><input type="hidden" name="r" value="catalog">
<select name="kind"><option value="">Alle</option><option value="service"<?= $kind === 'service' ? ' selected' : '' ?>>Leistungen</option><option value="article"<?= $kind === 'article' ? ' selected' : '' ?>>Artikel</option></select>
<input type="search" name="q" value="<?= e($q) ?>" placeholder="Nummer, Bezeichnung, Beschreibung"><button class="btn">Filtern</button></form>
<?php if (!$items): ?><p class="muted">Noch keine Einträge.</p><?php else: ?>
<div class="tablewrap"><table><thead><tr><th>Art</th><th>Nr.</th><th>Bezeichnung</th><th>Einheit</th><th class="r">Preis netto</th><th class="r">USt</th><th class="r">Marge</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($items as $i): $m = ((int)$i['cost_cents'] > 0 && (int)$i['price_cents'] > 0) ? round(((int)$i['price_cents'] - (int)$i['cost_cents']) * 100 / (int)$i['price_cents']) : null; ?>
<tr><td><span class="badge <?= e($i['kind']) ?>"><?= e(CATALOG_KINDS[$i['kind']] ?? $i['kind']) ?></span></td><td><?= e($i['number']) ?></td>
<td><?= can('catalog', 'w') ? '<a href="' . e(url('catalog_edit', ['id' => $i['id']])) . '">' . e($i['name']) . '</a>' : e($i['name']) ?><?php if ($i['description'] !== ''): ?><br><small class="muted"><?= e(mb_substr($i['description'], 0, 90)) ?></small><?php endif; ?></td>
<td><?= e($i['unit']) ?></td><td class="r"><?= e(money((int)$i['price_cents'])) ?></td><td class="r"><?= e(qty_fmt((float)$i['vat_rate'])) ?> %</td><td class="r"><?= $m === null ? '–' : e((string)$m) . ' %' ?></td>
<td><span class="badge <?= $i['active'] ? 'paid' : 'cancelled' ?>"><?= $i['active'] ? 'Aktiv' : 'Inaktiv' ?></span></td>
<td class="r"><?php if (can('catalog', 'w')): ?><form method="post" action="<?= e(url('catalog_delete')) ?>" class="inline" data-confirm="Eintrag löschen?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$i['id'] ?>"><button class="btn ghost">Löschen</button></form><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?>
<div class="card"><h2>Import / Export</h2>
<div class="actions"><a class="btn" href="<?= e(url('catalog_export')) ?>">Als CSV exportieren</a>
<?php if (can('catalog', 'w')): ?><form method="post" action="<?= e(url('catalog_import')) ?>" enctype="multipart/form-data" class="inline"><?= csrf_field() ?><input type="file" name="file" accept=".csv,text/csv" required><button class="btn">CSV importieren</button></form><?php endif; ?></div>
<p class="muted">Spalten: <code>Art;Nummer;Bezeichnung;Beschreibung;Einheit;Preis netto;Einkaufspreis;USt %;Aktiv</code> – „Art“ ist <em>Leistung</em> oder <em>Artikel</em>. Gleiche Nummer = vorhandener Eintrag wird aktualisiert. Am einfachsten: erst exportieren, in Excel ergänzen, wieder importieren.</p></div>
