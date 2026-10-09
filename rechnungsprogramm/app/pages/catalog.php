<?php
declare(strict_types=1);

const CATALOG_KINDS = ['service' => 'Leistung', 'article' => 'Artikel'];

function catalog_index(): void {
    $kind = (string)($_GET['kind'] ?? ''); $q = trim((string)($_GET['q'] ?? '')); $w = []; $p = [];
    if (isset(CATALOG_KINDS[$kind])) { $w[] = 'kind = ?'; $p[] = $kind; }
    if ($q !== '') { $w[] = '(name LIKE ? OR number LIKE ? OR description LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%"); }
    $st = db()->prepare('SELECT * FROM catalog_items' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY kind, LOWER(name) LIMIT 1000'); $st->execute($p);
    render('catalog', ['items' => $st->fetchAll(), 'kind' => $kind, 'q' => $q], 'Leistungen & Artikel');
}

function catalog_edit(): void {
    $id = (int)($_GET['id'] ?? 0);
    $i = ['id' => 0, 'kind' => isset(CATALOG_KINDS[(string)($_GET['kind'] ?? '')]) ? (string)$_GET['kind'] : 'service', 'number' => '', 'name' => '', 'description' => '', 'unit' => ($_GET['kind'] ?? '') === 'article' ? 'Stk.' : 'Std.', 'price_cents' => 0, 'cost_cents' => 0, 'vat_rate' => setting('small_business') === '1' ? 0 : 19, 'active' => 1];
    if ($id) { $st = db()->prepare('SELECT * FROM catalog_items WHERE id = ?'); $st->execute([$id]); $i = $st->fetch() ?: redirect('catalog'); }
    render('catalog_form', ['i' => $i], $id ? 'Eintrag bearbeiten' : 'Neuer Eintrag');
}

function catalog_save(): void {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0); $back = fn() => redirect('catalog_edit', $id ? ['id' => $id] : ['kind' => post('kind')]);
    $kind = post('kind'); if (!isset(CATALOG_KINDS[$kind])) $kind = 'service';
    $d = ['name' => post('name'), 'number' => post('number'), 'description' => post('description'), 'unit' => post('unit')];
    if ($d['name'] === '') { flash('Bitte eine Bezeichnung angeben.', 'err'); $back(); }
    if ($e = field_too_long('catalog_items', $d)) { flash($e, 'err'); $back(); }
    if (!money_valid(post('price')) || !money_valid(post('cost')) || !money_valid(post('vat_rate', '19'))) { flash('Bitte Preise und USt-Satz als Zahl angeben (z. B. 12,50).', 'err'); $back(); }
    $price = parse_cents(post('price')); $cost = parse_cents(post('cost'));
    if ($price < 0 || $cost < 0 || ($e = amount_error(1, max($price, $cost)))) { flash($e ?? 'Preise dürfen nicht negativ sein.', 'err'); $back(); }
    $vat = max(0.0, min(100.0, parse_decimal(post('vat_rate', '19'))));
    if ($d['number'] !== '') { $st = db()->prepare('SELECT COUNT(*) FROM catalog_items WHERE number = ? AND id <> ?'); $st->execute([$d['number'], $id]); if ((int)$st->fetchColumn()) { flash('Diese Nummer ist schon vergeben.', 'err'); $back(); } }
    $v = [$kind, $d['number'], $d['name'], $d['description'], $d['unit'], $price, $cost, $vat, isset($_POST['active']) ? 1 : 0];
    if ($id) db()->prepare('UPDATE catalog_items SET kind=?, number=?, name=?, description=?, unit=?, price_cents=?, cost_cents=?, vat_rate=?, active=? WHERE id=?')->execute(array_merge($v, [$id]));
    else db()->prepare('INSERT INTO catalog_items (kind, number, name, description, unit, price_cents, cost_cents, vat_rate, active) VALUES (?,?,?,?,?,?,?,?,?)')->execute($v);
    flash('Gespeichert.');
    redirect('catalog', ['kind' => $kind]);
}

function catalog_delete(): void {
    csrf_check();
    db()->prepare('DELETE FROM catalog_items WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
    audit('catalog_deleted', 'ID ' . (int)($_POST['id'] ?? 0));
    flash('Eintrag gelöscht (bestehende Rechnungen bleiben unverändert).');
    redirect('catalog');
}

function catalog_export(): void {
    $q = fn(string $s) => '"' . str_replace('"', '""', preg_match('/^[=+\-@\t\r]/', $s) ? "'" . $s : $s) . '"';
    $out = "\xEF\xBB\xBF" . "Art;Nummer;Bezeichnung;Beschreibung;Einheit;Preis netto;Einkaufspreis;USt %;Aktiv\r\n";
    foreach (db()->query('SELECT * FROM catalog_items ORDER BY kind, LOWER(name)')->fetchAll() as $r)
        $out .= implode(';', [CATALOG_KINDS[$r['kind']] ?? 'Leistung', $q($r['number']), $q($r['name']), $q(str_replace(["\r", "\n"], ' ', $r['description'])), $q($r['unit']), money_plain((int)$r['price_cents']), money_plain((int)$r['cost_cents']), qty_fmt((float)$r['vat_rate']), $r['active'] ? 'ja' : 'nein']) . "\r\n";
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="Leistungen_Artikel.csv"'); echo $out;
}

/** CSV-Import (Semikolon oder Komma): Art;Nummer;Bezeichnung;Beschreibung;Einheit;Preis netto;Einkaufspreis;USt %;Aktiv. Gleiche Nummer = Aktualisierung. */
function catalog_import(): void {
    csrf_check();
    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] > 5 * 1024 * 1024) { flash('Bitte eine CSV-Datei (max. 5 MB) auswählen.', 'err'); redirect('catalog'); }
    $raw = (string)file_get_contents($f['tmp_name']);
    if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    $lines = preg_split('/\r\n|\r|\n/', trim($raw)); if (count($lines) < 2) { flash('Die Datei enthält keine Datenzeilen.', 'err'); redirect('catalog'); }
    $delim = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';
    $n = 0; $upd = 0; $skip = 0; $pdo = db(); db_begin($pdo);
    try {
        foreach (array_slice($lines, 1, 5000) as $line) {
            if (trim($line) === '') continue;
            $c = str_getcsv($line, $delim, '"', '');
            $c = array_pad(array_map('trim', $c), 9, '');
            [$k, $num, $name, $desc, $unit, $price, $cost, $vat, $act] = $c;
            $kind = (stripos($k, 'art') === 0) ? 'article' : 'service';
            if ($name === '' || mb_strlen($name) > 190 || mb_strlen($num) > 60 || mb_strlen($desc) > 1000 || mb_strlen($unit) > 30) { $skip++; continue; }
            if (!money_valid($price) || !money_valid($cost) || !money_valid($vat)) { $skip++; continue; }
            $p = parse_cents($price); $cs = parse_cents($cost); $vr = max(0.0, min(100.0, $vat === '' ? 19.0 : parse_decimal($vat)));
            if ($p < 0 || $cs < 0 || amount_error(1, max($p, $cs))) { $skip++; continue; }
            $active = in_array(strtolower($act), ['nein', '0', 'false', 'n'], true) ? 0 : 1;
            $ex = null; if ($num !== '') { $st = $pdo->prepare('SELECT id FROM catalog_items WHERE number = ?'); $st->execute([$num]); $ex = $st->fetchColumn(); }
            if ($ex) { $pdo->prepare('UPDATE catalog_items SET kind=?, name=?, description=?, unit=?, price_cents=?, cost_cents=?, vat_rate=?, active=? WHERE id=?')->execute([$kind, $name, $desc, $unit, $p, $cs, $vr, $active, $ex]); $upd++; }
            else { $pdo->prepare('INSERT INTO catalog_items (kind, number, name, description, unit, price_cents, cost_cents, vat_rate, active) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$kind, $num, $name, $desc, $unit, $p, $cs, $vr, $active]); $n++; }
        }
        db_commit($pdo);
    } catch (Throwable $e) { db_rollback($pdo); error_log('catalog import: ' . $e->getMessage()); flash('Import fehlgeschlagen – es wurde nichts übernommen.', 'err'); redirect('catalog'); }
    audit('catalog_import', "$n neu, $upd aktualisiert, $skip übersprungen");
    flash("Import fertig: $n neu, $upd aktualisiert" . ($skip ? ", $skip Zeilen übersprungen (Bezeichnung fehlt oder Werte ungültig)" : '') . '.');
    redirect('catalog');
}
