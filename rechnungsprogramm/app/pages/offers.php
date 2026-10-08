<?php
declare(strict_types=1);
require_once APP_ROOT . '/invoice_pdf.php';

function offer_load(int $id): array {
    $st = db()->prepare('SELECT o.*, c.company, c.firstname, c.lastname FROM offers o JOIN customers c ON c.id = o.customer_id WHERE o.id = ?');
    $st->execute([$id]);
    $o = $st->fetch();
    if (!$o) { flash('Angebot nicht gefunden.', 'err'); redirect('offers'); }
    return $o;
}
function offer_items(int $id): array {
    $st = db()->prepare('SELECT * FROM offer_items WHERE offer_id = ? ORDER BY position, id'); $st->execute([$id]);
    return $st->fetchAll();
}
function offer_expired(array $o): bool { return $o['status'] === 'open' && $o['valid_until'] < date('Y-m-d'); }

function offers_index(): void {
    $status = (string)($_GET['status'] ?? ''); $q = trim((string)($_GET['q'] ?? ''));
    $w = []; $p = [];
    if (in_array($status, ['open', 'accepted', 'declined'], true)) { $w[] = 'o.status = :st'; $p[':st'] = $status; }
    if ($q !== '') { $w[] = '(o.offer_number LIKE :q OR c.company LIKE :q OR c.firstname LIKE :q OR c.lastname LIKE :q OR o.subject LIKE :q)'; $p[':q'] = "%$q%"; }
    $st = db()->prepare('SELECT o.*, c.company, c.firstname, c.lastname FROM offers o JOIN customers c ON c.id = o.customer_id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY o.offer_date DESC, o.id DESC LIMIT 500');
    $st->execute($p);
    render('offers', ['offers' => $st->fetchAll(), 'status' => $status, 'q' => $q], 'Angebote');
}

function offers_edit(): void {
    $id = (int)($_GET['id'] ?? 0);
    $customers = db()->query("SELECT * FROM customers ORDER BY " . CUSTOMER_ORDER)->fetchAll();
    if (!$customers) { flash('Bitte zuerst einen Kunden anlegen.', 'err'); redirect('customer_edit'); }
    if ($id) {
        $o = offer_load($id);
        if ($o['status'] !== 'open' || $o['invoice_id']) { flash('Dieses Angebot kann nicht mehr bearbeitet werden.', 'err'); redirect('offer_show', ['id' => $id]); }
        $items = offer_items($id);
    } else {
        $o = ['id' => 0, 'customer_id' => (int)($_GET['customer_id'] ?? 0), 'offer_number' => '', 'offer_date' => date('Y-m-d'), 'valid_until' => date('Y-m-d', strtotime('+30 days')),
              'subject' => '', 'intro' => '', 'notes' => '', 'status' => 'open', 'small_business' => setting('small_business') === '1' ? 1 : 0];
        $items = [['description' => '', 'quantity' => 1, 'unit' => 'Std.', 'unit_price' => 0, 'vat_rate' => setting('small_business') === '1' ? 0 : 19]];
    }
    render('offer_form', ['o' => $o, 'items' => $items, 'customers' => $customers, 'small' => (bool)$o['small_business']], $id ? 'Angebot bearbeiten' : 'Neues Angebot');
}

function offers_save(): void {
    csrf_check();
    $pdo = db();
    $id = (int)($_POST['id'] ?? 0);
    $back = fn() => redirect($id ? 'offer_edit' : 'offer_new', $id ? ['id' => $id] : []);
    $cs = $pdo->prepare('SELECT * FROM customers WHERE id = ?'); $cs->execute([(int)($_POST['customer_id'] ?? 0)]);
    $cust = $cs->fetch();
    $date = valid_date(post('offer_date')); $until = valid_date(post('valid_until'));
    if (!$cust || !$date || !$until) { flash('Bitte Kunde sowie gültige Datumsangaben wählen.', 'err'); $back(); }
    $small = $id ? (bool)offer_load($id)['small_business'] : setting('small_business') === '1';
    $items = [];
    foreach ((array)($_POST['description'] ?? []) as $i => $desc) {
        $desc = trim((string)$desc);
        $price = parse_cents((string)($_POST['unit_price'][$i] ?? '0'));
        if ($desc === '' && $price === 0) continue;
        if ($desc === '') { flash('Jede Position braucht eine Beschreibung.', 'err'); $back(); }
        if ($e = amount_error((float)parse_decimal((string)($_POST['quantity'][$i] ?? '1')), $price)) { flash($e, 'err'); $back(); }
        if ($e = field_too_long('offer_items', ['description' => $desc, 'unit' => trim((string)($_POST['unit'][$i] ?? ''))])) { flash($e, 'err'); $back(); }
        $items[] = ['description' => $desc, 'quantity' => parse_decimal((string)($_POST['quantity'][$i] ?? '1')), 'unit' => trim((string)($_POST['unit'][$i] ?? '')), 'unit_price' => $price,
            'vat_rate' => $small ? 0.0 : max(0.0, min(100.0, parse_decimal((string)($_POST['vat_rate'][$i] ?? '19'))))];
    }
    if (!$items) { flash('Mindestens eine Position ist erforderlich.', 'err'); $back(); }
    $calc = calc_invoice($items);
    if (abs($calc['gross']) > 900000000000000) { flash('Der Angebotsbetrag ist zu groß.', 'err'); $back(); }
    if ($e = field_too_long('offers', ['subject' => post('subject'), 'intro' => post('intro'), 'notes' => post('notes')])) { flash($e, 'err'); $back(); }
    db_begin($pdo);
    try {
        $f = ['customer_id' => $cust['id'], 'offer_date' => $date, 'valid_until' => $until, 'subject' => post('subject'), 'intro' => post('intro'), 'notes' => post('notes'),
              'net_amount' => $calc['net'], 'vat_amount' => $calc['vat'], 'gross_amount' => $calc['gross'], 'customer_address' => customer_address($cust)];
        if ($id) {
            $cur = offer_load($id);
            if ($cur['status'] !== 'open' || $cur['invoice_id']) throw new RuntimeException('locked');
            if (substr($cur['offer_date'], 0, 4) !== substr($date, 0, 4)) $f['offer_number'] = next_offer_number($pdo, $date);
            $sets = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($f)));
            $pdo->prepare("UPDATE offers SET $sets WHERE id = :id")->execute($f + ['id' => $id]);
            $pdo->prepare('DELETE FROM offer_items WHERE offer_id = ?')->execute([$id]);
        } else {
            $f['offer_number'] = next_offer_number($pdo, $date); $f['small_business'] = $small ? 1 : 0;
            $pdo->prepare('INSERT INTO offers (' . implode(', ', array_keys($f)) . ') VALUES (' . implode(', ', array_map(fn($k) => ":$k", array_keys($f))) . ')')->execute($f);
            $id = (int)$pdo->lastInsertId();
        }
        $ins = $pdo->prepare('INSERT INTO offer_items(offer_id, position, description, quantity, unit, unit_price, vat_rate, total) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($items as $n => $it) $ins->execute([$id, $n + 1, $it['description'], $it['quantity'], $it['unit'], $it['unit_price'], $it['vat_rate'], $calc['lines'][$n]]);
        db_commit($pdo);
    } catch (Throwable $e) {
        db_rollback($pdo); error_log($e->getMessage()); flash('Speichern fehlgeschlagen.', 'err'); $back();
    }
    flash('Angebot gespeichert.');
    redirect('offer_show', ['id' => $id]);
}

function offers_show(): void {
    $id = (int)($_GET['id'] ?? 0); $o = offer_load($id);
    require_once APP_ROOT . '/pages/mails.php';
    render('offer_show', ['o' => $o, 'items' => offer_items($id), 'mailLog' => mail_log_for('offer', $id)], 'Angebot ' . $o['offer_number']);
}

function offer_pdf_string(array $o): string {
    $adapter = ['invoice_number' => $o['offer_number'], 'invoice_date' => $o['offer_date'], 'due_date' => $o['valid_until'], 'service_date' => '', 'status' => 'open', 'paid_date' => null] + $o;
    return invoice_pdf($adapter, offer_items((int)$o['id']), null, 'offer');
}

function offers_pdf(): void {
    $id = (int)($_GET['id'] ?? 0); $o = offer_load($id);
    $pdf = offer_pdf_string($o);
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', 'Angebot_' . $o['offer_number']) . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, no-store');
    echo $pdf;
}

function offers_status(): void {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0); $o = offer_load($id); $act = post('action');
    $map = ['accept' => 'accepted', 'decline' => 'declined', 'reopen' => 'open'];
    if (isset($map[$act]) && !$o['invoice_id']) {
        db()->prepare('UPDATE offers SET status = ? WHERE id = ?')->execute([$map[$act], $id]);
        flash('Status geändert.');
    } else flash('Aktion nicht möglich.', 'err');
    redirect('offer_show', ['id' => $id]);
}

/** Angebot in eine Rechnung übernehmen (Positionen werden kopiert, Angebot gilt als angenommen). */
function offers_to_invoice(): void {
    csrf_check();
    require_can('invoices', 'w');
    if ($e = saas_limit_error('invoices')) { flash($e, 'err'); redirect('offers'); }
    $id = (int)($_POST['id'] ?? 0); $o = offer_load($id); $pdo = db();
    if ($o['invoice_id']) redirect('invoice_show', ['id' => $o['invoice_id']]);
    $cs = $pdo->prepare('SELECT * FROM customers WHERE id = ?'); $cs->execute([$o['customer_id']]); $cust = $cs->fetch();
    $today = date('Y-m-d');
    db_begin($pdo);
    try {
        $pdo->prepare('INSERT INTO invoices(invoice_number, customer_id, customer_address, invoice_date, due_date, subject, intro, notes, net_amount, vat_amount, gross_amount, small_business) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([next_invoice_number($pdo, $today), $o['customer_id'], customer_address($cust), $today, date('Y-m-d', strtotime('+' . max(0, (int)setting('payment_days', '14')) . ' days')),
                $o['subject'], setting('default_intro'), '', $o['net_amount'], $o['vat_amount'], $o['gross_amount'], (int)$o['small_business']]);
        $new = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO invoice_items(invoice_id, position, description, quantity, unit, unit_price, vat_rate, total) SELECT ?, position, description, quantity, unit, unit_price, vat_rate, total FROM offer_items WHERE offer_id = ?')->execute([$new, $id]);
        $pdo->prepare("UPDATE offers SET status = 'accepted', invoice_id = ? WHERE id = ?")->execute([$new, $id]);
        db_commit($pdo);
    } catch (Throwable $e) { db_rollback($pdo); throw $e; }
    flash('Rechnung aus Angebot erstellt. Bitte Datum und Leistungszeitraum prüfen.');
    redirect('invoice_edit', ['id' => $new]);
}

function offers_delete(): void {
    csrf_check();
    $o = offer_load((int)($_POST['id'] ?? 0));
    if ($o['invoice_id']) { flash('Aus diesem Angebot wurde eine Rechnung erstellt – es kann nicht gelöscht werden.', 'err'); redirect('offer_show', ['id' => $o['id']]); }
    db()->prepare('DELETE FROM offers WHERE id = ?')->execute([$o['id']]);
    flash('Angebot gelöscht.');
    redirect('offers');
}
