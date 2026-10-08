<?php
declare(strict_types=1);
require_once APP_ROOT . '/invoice_pdf.php';

function delivery_load(int $id): array {
    $st = db()->prepare('SELECT d.*, c.company, c.firstname, c.lastname FROM delivery_notes d JOIN customers c ON c.id = d.customer_id WHERE d.id = ?');
    $st->execute([$id]);
    $d = $st->fetch();
    if (!$d) { flash('Lieferschein nicht gefunden.', 'err'); redirect('deliveries'); }
    return $d;
}
function delivery_items(int $id): array {
    $st = db()->prepare('SELECT * FROM delivery_items WHERE note_id = ? ORDER BY position, id'); $st->execute([$id]);
    return $st->fetchAll();
}

function delivery_index(): void {
    $q = trim((string)($_GET['q'] ?? '')); $p = []; $w = '';
    if ($q !== '') { $w = ' WHERE d.note_number LIKE :q OR c.company LIKE :q OR c.firstname LIKE :q OR c.lastname LIKE :q OR d.subject LIKE :q'; $p[':q'] = "%$q%"; }
    $st = db()->prepare('SELECT d.*, c.company, c.firstname, c.lastname FROM delivery_notes d JOIN customers c ON c.id = d.customer_id' . $w . ' ORDER BY d.note_date DESC, d.id DESC LIMIT 500');
    $st->execute($p);
    render('deliveries', ['notes' => $st->fetchAll(), 'q' => $q], 'Lieferscheine');
}

function delivery_edit(): void {
    $id = (int)($_GET['id'] ?? 0);
    $customers = db()->query("SELECT * FROM customers ORDER BY COALESCE(NULLIF(company,''), lastname) COLLATE NOCASE")->fetchAll();
    if (!$customers) { flash('Bitte zuerst einen Kunden anlegen.', 'err'); redirect('customer_edit'); }
    if ($id) { $d = delivery_load($id); $items = delivery_items($id); }
    else {
        $d = ['id' => 0, 'customer_id' => (int)($_GET['customer_id'] ?? 0), 'note_number' => '', 'note_date' => date('Y-m-d'), 'subject' => '', 'intro' => '', 'notes' => ''];
        $items = [['description' => '', 'quantity' => 1, 'unit' => 'Stk.']];
        // Vorbelegung aus einer Rechnung
        if ($inv = (int)($_GET['from_invoice'] ?? 0)) {
            $st = db()->prepare('SELECT * FROM invoices WHERE id = ?'); $st->execute([$inv]); $i = $st->fetch();
            if ($i) {
                $d['customer_id'] = (int)$i['customer_id']; $d['subject'] = $i['subject'];
                $it = db()->prepare('SELECT description, quantity, unit FROM invoice_items WHERE invoice_id = ? ORDER BY position'); $it->execute([$inv]);
                $items = $it->fetchAll() ?: $items;
            }
        }
    }
    render('delivery_form', ['d' => $d, 'items' => $items, 'customers' => $customers, 'invoiceId' => (int)($_GET['from_invoice'] ?? 0)], $id ? 'Lieferschein bearbeiten' : 'Neuer Lieferschein');
}

function delivery_save(): void {
    csrf_check();
    $pdo = db(); $id = (int)($_POST['id'] ?? 0);
    $back = fn() => redirect($id ? 'delivery_edit' : 'delivery_new', $id ? ['id' => $id] : []);
    $cs = $pdo->prepare('SELECT * FROM customers WHERE id = ?'); $cs->execute([(int)($_POST['customer_id'] ?? 0)]); $cust = $cs->fetch();
    $date = valid_date(post('note_date'));
    if (!$cust || !$date) { flash('Bitte Kunde und gültiges Datum wählen.', 'err'); $back(); }
    $items = [];
    foreach ((array)($_POST['description'] ?? []) as $i => $desc) {
        $desc = trim((string)$desc);
        if ($desc === '') continue;
        $items[] = [$desc, parse_decimal((string)($_POST['quantity'][$i] ?? '1')), trim((string)($_POST['unit'][$i] ?? ''))];
    }
    if (!$items) { flash('Mindestens eine Position ist erforderlich.', 'err'); $back(); }
    $pdo->beginTransaction();
    try {
        $f = ['customer_id' => $cust['id'], 'note_date' => $date, 'subject' => post('subject'), 'intro' => post('intro'), 'notes' => post('notes'), 'customer_address' => customer_address($cust)];
        if ($id) {
            $cur = delivery_load($id);
            if (substr($cur['note_date'], 0, 4) !== substr($date, 0, 4)) $f['note_number'] = next_delivery_number($pdo, $date);
            $pdo->prepare('UPDATE delivery_notes SET ' . implode(', ', array_map(fn($k) => "$k = :$k", array_keys($f))) . ' WHERE id = :id')->execute($f + ['id' => $id]);
            $pdo->prepare('DELETE FROM delivery_items WHERE note_id = ?')->execute([$id]);
        } else {
            $f['note_number'] = next_delivery_number($pdo, $date);
            if ($inv = (int)($_POST['from_invoice'] ?? 0)) $f['invoice_id'] = $inv;
            $pdo->prepare('INSERT INTO delivery_notes (' . implode(', ', array_keys($f)) . ') VALUES (' . implode(', ', array_map(fn($k) => ":$k", array_keys($f))) . ')')->execute($f);
            $id = (int)$pdo->lastInsertId();
        }
        $ins = $pdo->prepare('INSERT INTO delivery_items(note_id, position, description, quantity, unit) VALUES (?,?,?,?,?)');
        foreach ($items as $n => [$desc, $qty, $unit]) $ins->execute([$id, $n + 1, $desc, $qty, $unit]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); error_log($e->getMessage()); flash('Speichern fehlgeschlagen.', 'err'); $back(); }
    flash('Lieferschein gespeichert.');
    redirect('delivery_show', ['id' => $id]);
}

function delivery_show(): void {
    $id = (int)($_GET['id'] ?? 0); $d = delivery_load($id);
    require_once APP_ROOT . '/pages/mails.php';
    render('delivery_show', ['d' => $d, 'items' => delivery_items($id), 'mailLog' => mail_log_for('delivery', $id)], 'Lieferschein ' . $d['note_number']);
}

function delivery_pdf_string(array $d): string {
    $pdf = new Pdf(); $accent = [30, 64, 110]; $L = 20.0; $R = 190.0; $footerTop = 270.0; $light = [238, 242, 247];
    doc_frame($pdf);
    $y = doc_address_block($pdf, $d['customer_address'], [['Lieferschein-Nr.', $d['note_number']], ['Datum', date_de($d['note_date'])], ['Kunden-Nr.', (string)$d['customer_id']]]);
    $pdf->text($L, $y, 'Lieferschein ' . $d['note_number'], 15, true, 'L', $accent); $y += 7;
    if ($d['subject'] !== '') { $pdf->text($L, $y, $d['subject'], 10.5, true); $y += 6; }
    if ($d['intro'] !== '') { foreach ($pdf->wrap($d['intro'], $R - $L, 9.5) as $ln) { $pdf->text($L, $y, $ln, 9.5); $y += 4.5; } $y += 2; }
    $y += 4;
    $head = function (float $y) use ($pdf, $L, $R, $light) {
        $pdf->rect($L, $y - 4.2, $R - $L, 6.2, $light);
        $pdf->text($L + 1, $y, 'Pos.', 8.5, true); $pdf->text($L + 9, $y, 'Beschreibung', 8.5, true);
        $pdf->text(158, $y, 'Menge', 8.5, true, 'R'); $pdf->text(162, $y, 'Einheit', 8.5, true);
        return $y + 6;
    };
    $y = $head($y);
    foreach (delivery_items((int)$d['id']) as $i => $it) {
        $lines = $pdf->wrap($it['description'], 118, 9.5);
        if ($y + count($lines) * 4.4 + 2.5 > $footerTop - 50) { doc_frame($pdf); $y = $head(30); }
        $pdf->text($L + 1, $y, (string)($i + 1), 9.5);
        $yy = $y;
        foreach ($lines as $ln) { $pdf->text($L + 9, $y, $ln, 9.5); $y += 4.4; }
        $pdf->text(158, $yy, qty_fmt((float)$it['quantity']), 9.5, false, 'R'); $pdf->text(162, $yy, $it['unit'], 9.5);
        $y += 1.2; $pdf->line($L, $y, $R, $y, 0.1, [215, 215, 215]); $y += 3.8;
    }
    $y += 4;
    if ($d['notes'] !== '') { foreach ($pdf->wrap($d['notes'], $R - $L, 9) as $ln) { $pdf->text($L, $y, $ln, 9); $y += 4.2; } $y += 3; }
    if ($y > $footerTop - 45) { doc_frame($pdf); $y = 40; }
    $y = max($y + 8, 225);
    $pdf->text($L, $y, 'Ware vollständig und in einwandfreiem Zustand erhalten:', 9);
    $pdf->line($L, $y + 18, $L + 60, $y + 18, 0.2); $pdf->line($L + 80, $y + 18, $R, $y + 18, 0.2);
    $pdf->text($L, $y + 22, 'Datum', 7.5, false, 'L', [110, 110, 110]); $pdf->text($L + 80, $y + 22, 'Unterschrift Empfänger', 7.5, false, 'L', [110, 110, 110]);
    return $pdf->output('Lieferschein ' . $d['note_number']);
}

function delivery_pdf(): void {
    $d = delivery_load((int)($_GET['id'] ?? 0)); $pdf = delivery_pdf_string($d);
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', 'Lieferschein_' . $d['note_number']) . '.pdf"');
    header('Content-Length: ' . strlen($pdf)); header('Cache-Control: private, no-store');
    echo $pdf;
}

function delivery_delete(): void {
    csrf_check();
    $d = delivery_load((int)($_POST['id'] ?? 0));
    db()->prepare('DELETE FROM delivery_notes WHERE id = ?')->execute([$d['id']]);
    flash('Lieferschein gelöscht.');
    redirect('deliveries');
}
