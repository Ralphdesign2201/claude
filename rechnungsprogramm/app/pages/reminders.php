<?php
declare(strict_types=1);
require_once APP_ROOT . '/invoice_pdf.php';

function reminders_save(): void {
    csrf_check();
    $pdo = db();
    $id = (int)($_POST['invoice_id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM invoices WHERE id = ?'); $st->execute([$id]); $inv = $st->fetch();
    if (!$inv || $inv['status'] !== 'open') { flash('Mahnungen sind nur für offene Rechnungen möglich.', 'err'); redirect('invoices'); }
    $level = (int)($_POST['level'] ?? 1);
    if (!isset(REMINDER_LEVELS[$level])) $level = 1;
    $date = valid_date(post('reminder_date')) ?? date('Y-m-d');
    $new = valid_date(post('new_due_date')) ?? date('Y-m-d', strtotime('+7 days'));
    if ($new < $date) { flash('Die neue Frist liegt vor dem Mahndatum.', 'err'); redirect('invoice_show', ['id' => $id]); }
    $fee = max(0, parse_cents(post('fee')));
    $interest = 0;
    $rate = parse_decimal(setting('interest_rate'));
    if ($level >= 2 && $rate > 0 && $date > $inv['due_date']) {
        $days = (int)((strtotime($date) - strtotime($inv['due_date'])) / 86400);
        $interest = (int)round($inv['gross_amount'] * $rate / 100 * $days / 365);
    }
    $text = post('text') !== '' ? post('text') : reminder_default_text($level, $inv);
    $pdo->prepare('INSERT INTO reminders(invoice_id, level, reminder_date, new_due_date, open_amount, fee, interest, text) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$id, $level, $date, $new, $inv['gross_amount'], $fee, $interest, $text]);
    flash(REMINDER_LEVELS[$level] . ' erstellt.');
    redirect('reminder_pdf', ['id' => (int)$pdo->lastInsertId()]);
}

function reminders_load(int $id): array {
    $st = db()->prepare('SELECT r.*, i.invoice_number, i.invoice_date, i.due_date, i.customer_address, i.customer_id, i.id AS inv_id FROM reminders r JOIN invoices i ON i.id = r.invoice_id WHERE r.id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) { flash('Mahnung nicht gefunden.', 'err'); redirect('invoices'); }
    return $r;
}

function reminders_pdf(): void {
    $r = reminders_load((int)($_GET['id'] ?? 0));
    $pdf = reminder_pdf($r);
    $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', 'Mahnung_' . $r['invoice_number'] . '_Stufe' . $r['level']) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, no-store');
    echo $pdf;
}

function reminders_delete(): void {
    csrf_check();
    $r = reminders_load((int)($_POST['id'] ?? 0));
    db()->prepare('DELETE FROM reminders WHERE id = ?')->execute([$r['id']]);
    flash('Mahnung gelöscht.');
    redirect('invoice_show', ['id' => $r['inv_id']]);
}

function reminder_pdf(array $r): string {
    $pdf = new Pdf(); $accent = [30, 64, 110]; $L = 20.0; $R = 190.0;
    doc_frame($pdf);
    $y = doc_address_block($pdf, $r['customer_address'], [
        ['Datum', date_de($r['reminder_date'])], ['Rechnungs-Nr.', $r['invoice_number']], ['Kunden-Nr.', (string)$r['customer_id']],
    ]);
    $pdf->text($L, $y, REMINDER_LEVELS[$r['level']] . ' zur Rechnung ' . $r['invoice_number'], 14, true, 'L', $accent);
    $y += 10;
    $pdf->text($L, $y, 'Sehr geehrte Damen und Herren,', 10); $y += 7;
    foreach ($pdf->wrap($r['text'], $R - $L, 10) as $ln) { $pdf->text($L, $y, $ln, 10); $y += 4.8; }
    $y += 6;
    $rows = [['Rechnung ' . $r['invoice_number'] . ' vom ' . date_de($r['invoice_date']) . ' (fällig am ' . date_de($r['due_date']) . ')', (int)$r['open_amount']]];
    if ($r['fee'] > 0) $rows[] = ['Mahngebühr', (int)$r['fee']];
    if ($r['interest'] > 0) $rows[] = ['Verzugszinsen ' . setting('interest_rate') . ' % p. a.', (int)$r['interest']];
    $total = (int)$r['open_amount'] + (int)$r['fee'] + (int)$r['interest'];
    foreach ($rows as [$k, $v]) { $pdf->text($L, $y, $k, 10); $pdf->text($R, $y, money($v), 10, false, 'R'); $y += 5.5; }
    $pdf->line($L, $y - 3, $R, $y - 3, 0.4, $accent);
    $pdf->rect($L, $y - 1.5, $R - $L, 8, [238, 242, 247]);
    $pdf->text($L + 2, $y + 4, 'Zu zahlender Gesamtbetrag', 11, true); $pdf->text($R - 2, $y + 4, money($total), 11, true, 'R');
    $y += 16;
    $pdf->text($L, $y, 'Bitte zahlen Sie bis spätestens ' . date_de($r['new_due_date']) . ' unter Angabe der Rechnungsnummer auf unser Konto:', 10); $y += 5.5;
    $acct = array_filter([setting('bank'), setting('iban') !== '' ? 'IBAN ' . setting('iban') : '', setting('bic') !== '' ? 'BIC ' . setting('bic') : '']);
    if ($acct) { $pdf->text($L, $y, implode('  ·  ', $acct), 10, true); $y += 7; }
    $y += 4;
    $pdf->text($L, $y, 'Mit freundlichen Grüßen', 10); $y += 12;
    $pdf->text($L, $y, setting('owner') !== '' ? setting('owner') : setting('company'), 10);
    return $pdf->output(REMINDER_LEVELS[$r['level']] . ' ' . $r['invoice_number']);
}
