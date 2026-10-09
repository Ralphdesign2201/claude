<?php
declare(strict_types=1);
require_once APP_ROOT . '/invoice_pdf.php';

function invoice_load(int $id): array {
    $st = db()->prepare('SELECT i.*, c.company, c.firstname, c.lastname, c.leitweg_id FROM invoices i JOIN customers c ON c.id = i.customer_id WHERE i.id = ?');
    $st->execute([$id]);
    $inv = $st->fetch();
    if (!$inv) { flash('Rechnung nicht gefunden.', 'err'); redirect('invoices'); }
    return $inv;
}
function invoice_items(int $id): array {
    $st = db()->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY position, id'); $st->execute([$id]);
    return $st->fetchAll();
}

function invoice_customer(int $id): array {
    $st = db()->prepare('SELECT * FROM customers WHERE id = ?'); $st->execute([$id]);
    return $st->fetch();
}

/** ZUGFeRD/Factur-X-XML separat herunterladen (z. B. für Steuerberater-Software). */
function invoices_xml(): void {
    require_once APP_ROOT . '/zugferd.php';
    $id = (int)($_GET['id'] ?? 0); $inv = invoice_load($id);
    $xr = isset($_GET['xr']);
    if ($inv['status'] === 'cancelled') { flash('Für stornierte Rechnungen wird keine E-Rechnung erzeugt.', 'err'); redirect('invoice_show', ['id' => $id]); }
    $cust = invoice_customer((int)$inv['customer_id']);
    if ($xr && ($miss = xrechnung_missing($cust))) { flash('Für die XRechnung fehlen: ' . implode(', ', $miss) . '.', 'err'); redirect('invoice_show', ['id' => $id]); }
    $xml = zugferd_xml($inv, invoice_items($id), $cust, $xr);
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $inv['invoice_number']) . ($xr ? '_xrechnung.xml' : '_factur-x.xml') . '"');
    echo $xml;
}

function invoices_index(): void {
    $status = (string)($_GET['status'] ?? ''); $q = trim((string)($_GET['q'] ?? '')); $year = (string)($_GET['year'] ?? '');
    $w = []; $p = [];
    if ($status === 'overdue') { $w[] = "i.status = 'open' AND i.due_date < :today"; $p[':today'] = date('Y-m-d'); }
    elseif (in_array($status, ['open', 'paid', 'cancelled'], true)) { $w[] = 'i.status = :st'; $p[':st'] = $status; }
    if ($q !== '') { $w[] = '(i.invoice_number LIKE :q OR c.company LIKE :q OR c.firstname LIKE :q OR c.lastname LIKE :q OR i.subject LIKE :q)'; $p[':q'] = "%$q%"; }
    if (preg_match('/^\d{4}$/', $year)) { $w[] = 'substr(i.invoice_date,1,4) = :y'; $p[':y'] = $year; }
    $sql = 'SELECT i.*, c.company, c.firstname, c.lastname FROM invoices i JOIN customers c ON c.id = i.customer_id'
        . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY i.invoice_date DESC, i.id DESC LIMIT 500';
    $st = db()->prepare($sql); $st->execute($p);
    $years = db()->query("SELECT DISTINCT substr(invoice_date,1,4) FROM invoices ORDER BY 1 DESC")->fetchAll(PDO::FETCH_COLUMN);
    render('invoices', ['invoices' => $st->fetchAll(), 'status' => $status, 'q' => $q, 'year' => $year, 'years' => $years], 'Rechnungen');
}

function invoices_edit(): void {
    $id = (int)($_GET['id'] ?? 0);
    $customers = db()->query("SELECT * FROM customers ORDER BY " . CUSTOMER_ORDER)->fetchAll();
    if (!$customers) { flash('Bitte zuerst einen Kunden anlegen.', 'err'); redirect('customer_edit'); }
    if ($id) {
        $inv = invoice_load($id);
        if ($inv['status'] !== 'open') { flash('Nur offene Rechnungen können bearbeitet werden.', 'err'); redirect('invoice_show', ['id' => $id]); }
        $items = invoice_items($id);
    } else {
        $today = date('Y-m-d');
        $inv = ['id' => 0, 'customer_id' => (int)($_GET['customer_id'] ?? 0), 'invoice_number' => '', 'invoice_date' => $today,
            'due_date' => date('Y-m-d', strtotime('+' . max(0, (int)setting('payment_days', '14')) . ' days')),
            'service_date' => '', 'subject' => '', 'intro' => setting('default_intro'), 'notes' => '', 'status' => 'open'];
        $items = [['description' => '', 'quantity' => 1, 'unit' => 'Std.', 'unit_price' => 0, 'vat_rate' => setting('small_business') === '1' ? 0 : 19]];
    }
    render('invoice_form', ['inv' => $inv, 'items' => $items, 'catalog' => catalog_active(), 'customers' => $customers, 'small' => $id ? (bool)$inv['small_business'] : setting('small_business') === '1'], $id ? 'Rechnung bearbeiten' : 'Neue Rechnung');
}

function invoices_save(): void {
    csrf_check();
    $pdo = db();
    $id = (int)($_POST['id'] ?? 0);
    $back = fn() => redirect($id ? 'invoice_edit' : 'invoice_new', $id ? ['id' => $id] : []);

    $cs = $pdo->prepare('SELECT * FROM customers WHERE id = ?'); $cs->execute([(int)($_POST['customer_id'] ?? 0)]);
    $cust = $cs->fetch();
    $date = valid_date(post('invoice_date')); $due = valid_date(post('due_date'));
    if (!$cust || !$date || !$due) { flash('Bitte Kunde sowie gültige Datumsangaben wählen.', 'err'); $back(); }
    if ($due < $date) { flash('Das Fälligkeitsdatum liegt vor dem Rechnungsdatum.', 'err'); $back(); }

    $small = $id ? (bool)invoice_load($id)['small_business'] : setting('small_business') === '1';
    $items = [];
    $descs = (array)($_POST['description'] ?? []);
    foreach ($descs as $i => $desc) {
        $desc = trim((string)$desc);
        $qty = parse_decimal((string)($_POST['quantity'][$i] ?? '1'));
        $price = parse_cents((string)($_POST['unit_price'][$i] ?? '0'));
        if ($desc === '' && $price === 0) continue;
        if ($desc === '') { flash('Jede Position braucht eine Beschreibung.', 'err'); $back(); }
        $rate = $small ? 0.0 : max(0.0, min(100.0, parse_decimal((string)($_POST['vat_rate'][$i] ?? '19'))));
        if ($e = amount_error($qty, $price)) { flash($e, 'err'); $back(); }
        if ($e = field_too_long('invoice_items', ['description' => $desc, 'unit' => trim((string)($_POST['unit'][$i] ?? ''))])) { flash($e, 'err'); $back(); }
        $items[] = ['description' => $desc, 'quantity' => $qty, 'unit' => trim((string)($_POST['unit'][$i] ?? '')), 'unit_price' => $price, 'vat_rate' => $rate];
    }
    if (!$items) { flash('Mindestens eine Position ist erforderlich.', 'err'); $back(); }
    if (!$id && ($e = saas_limit_error('invoices'))) { flash($e, 'err'); $back(); }
    $calc = calc_invoice($items);
    if (abs($calc['gross']) > 900000000000000) { flash('Der Rechnungsbetrag ist zu groß.', 'err'); $back(); }
    if ($e = field_too_long('invoices', ['subject' => post('subject'), 'intro' => post('intro'), 'notes' => post('notes'), 'service_date' => post('service_date')])) { flash($e, 'err'); $back(); }

    db_begin($pdo);
    try {
        $f = ['customer_id' => $cust['id'], 'invoice_date' => $date, 'due_date' => $due, 'service_date' => post('service_date'),
            'subject' => post('subject'), 'intro' => post('intro'), 'notes' => post('notes'),
            'net_amount' => $calc['net'], 'vat_amount' => $calc['vat'], 'gross_amount' => $calc['gross'], 'customer_address' => customer_address($cust)];
        if ($id) {
            $cur = invoice_load($id);
            if ($cur['status'] !== 'open') throw new RuntimeException('locked');
            if (substr($cur['invoice_date'], 0, 4) !== substr($date, 0, 4)) {
                // Jahreswechsel: neue Nummer, damit Nummernkreis zum Jahr passt
                $f['invoice_number'] = next_invoice_number($pdo, $date);
            }
            $sets = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($f)));
            $pdo->prepare("UPDATE invoices SET $sets WHERE id = :id")->execute($f + ['id' => $id]);
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
        } else {
            $f['invoice_number'] = next_invoice_number($pdo, $date);
            $f['small_business'] = $small ? 1 : 0;
            $cols = implode(', ', array_keys($f)); $ph = implode(', ', array_map(fn($k) => ":$k", array_keys($f)));
            $pdo->prepare("INSERT INTO invoices ($cols) VALUES ($ph)")->execute($f);
            $id = (int)$pdo->lastInsertId();
        }
        $ins = $pdo->prepare('INSERT INTO invoice_items(invoice_id, position, description, quantity, unit, unit_price, vat_rate, total) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($items as $n => $it) $ins->execute([$id, $n + 1, $it['description'], $it['quantity'], $it['unit'], $it['unit_price'], $it['vat_rate'], $calc['lines'][$n]]);
        db_commit($pdo);
    } catch (Throwable $e) {
        db_rollback($pdo);
        error_log($e->getMessage());
        flash('Speichern fehlgeschlagen.', 'err');
        $back();
    }
    flash('Rechnung gespeichert.');
    redirect('invoice_show', ['id' => $id]);
}

function invoices_show(): void {
    $id = (int)($_GET['id'] ?? 0);
    $inv = invoice_load($id);
    $rs = db()->prepare('SELECT * FROM reminders WHERE invoice_id = ? ORDER BY reminder_date, id'); $rs->execute([$id]);
    render('invoice_show', ['inv' => $inv, 'items' => invoice_items($id), 'reminders' => $rs->fetchAll(), 'mailLog' => (function () use ($id) { require_once APP_ROOT . '/pages/mails.php'; return array_merge(mail_log_for('invoice', $id), ...array_map(fn($r) => mail_log_for('reminder', (int)$r['id']), db()->query('SELECT id FROM reminders WHERE invoice_id = ' . (int)$id)->fetchAll())); })()], 'Rechnung ' . $inv['invoice_number']);
}

function invoices_pdf(): void {
    $id = (int)($_GET['id'] ?? 0);
    $inv = invoice_load($id);
    $pdf = invoice_pdf($inv, invoice_items($id), invoice_customer((int)$inv['customer_id']));
    $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', 'Rechnung_' . $inv['invoice_number']) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $name . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, no-store');
    echo $pdf;
}

function invoices_status(): void {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0); $inv = invoice_load($id); $act = post('action');
    $pdo = db();
    if ($act === 'paid' && $inv['status'] === 'open') {
        $d = valid_date(post('paid_date')) ?? date('Y-m-d');
        $pdo->prepare("UPDATE invoices SET status='paid', paid_date=? WHERE id=?")->execute([$d, $id]);
        flash('Als bezahlt markiert.');
    } elseif ($act === 'reopen' && $inv['status'] === 'paid') {
        $pdo->prepare("UPDATE invoices SET status='open', paid_date=NULL WHERE id=?")->execute([$id]);
        flash('Wieder auf „offen“ gesetzt.');
    } elseif ($act === 'cancel' && $inv['status'] !== 'cancelled') {
        $pdo->prepare("UPDATE invoices SET status='cancelled', cancelled_at=?, cancel_reason=? WHERE id=?")->execute([date('Y-m-d'), post('reason'), $id]);
        flash('Rechnung storniert. Die Rechnungsnummer bleibt belegt.');
    } else {
        flash('Aktion nicht möglich.', 'err');
    }
    redirect('invoice_show', ['id' => $id]);
}

function invoices_copy(): void {
    csrf_check();
    if ($e = saas_limit_error('invoices')) { flash($e, 'err'); redirect('invoices'); }
    $id = (int)($_POST['id'] ?? 0); $inv = invoice_load($id); $pdo = db();
    $cs = $pdo->prepare('SELECT * FROM customers WHERE id = ?'); $cs->execute([$inv['customer_id']]); $cust = $cs->fetch();
    $today = date('Y-m-d');
    db_begin($pdo);
    $pdo->prepare('INSERT INTO invoices(invoice_number, customer_id, customer_address, invoice_date, due_date, service_date, subject, intro, notes, net_amount, vat_amount, gross_amount, small_business) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([next_invoice_number($pdo, $today), $inv['customer_id'], customer_address($cust), $today, date('Y-m-d', strtotime('+' . max(0, (int)setting('payment_days', '14')) . ' days')), '', $inv['subject'], $inv['intro'], $inv['notes'], $inv['net_amount'], $inv['vat_amount'], $inv['gross_amount'], (int)$inv['small_business']]);
    $new = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO invoice_items(invoice_id, position, description, quantity, unit, unit_price, vat_rate, total) SELECT ?, position, description, quantity, unit, unit_price, vat_rate, total FROM invoice_items WHERE invoice_id = ?')->execute([$new, $id]);
    db_commit($pdo);
    flash('Rechnung kopiert. Bitte Positionen und Daten prüfen.');
    redirect('invoice_edit', ['id' => $new]);
}
