<?php
declare(strict_types=1);
require_once APP_ROOT . '/mail.php';
require_once APP_ROOT . '/pages/invoices.php';
require_once APP_ROOT . '/pages/offers.php';
require_once APP_ROOT . '/pages/reminders.php';
require_once APP_ROOT . '/pages/delivery.php';

function mail_signature(): string {
    if (setting('mail_signature') !== '') return setting('mail_signature');
    $l = array_filter([setting('company'), setting('owner'), trim(setting('street') . ', ' . trim(setting('zip') . ' ' . setting('city')), ', '), setting('phone') !== '' ? 'Tel. ' . setting('phone') : '', setting('email'), setting('website')]);
    return implode("\n", $l);
}

/** Sammelt Empfänger, Betreff, Text und PDF für ein Dokument. */
function mail_document(string $type, int $id): array {
    $sal = fn(array $c) => ($c['company'] !== '' && $c['contact_person'] !== '') ? 'Sehr geehrte/r ' . $c['contact_person'] . ',' : 'Sehr geehrte Damen und Herren,';
    $cust = function (int $cid) { $st = db()->prepare('SELECT * FROM customers WHERE id = ?'); $st->execute([$cid]); return $st->fetch(); };
    if ($type === 'invoice') {
        $inv = invoice_load($id); $c = $cust((int)$inv['customer_id']);
        if ($inv['status'] === 'cancelled') { flash('Stornierte Rechnungen werden nicht versendet.', 'err'); redirect('invoice_show', ['id' => $id]); }
        $body = $sal($c) . "\n\nanbei erhalten Sie unsere Rechnung " . $inv['invoice_number'] . ' über ' . money((int)$inv['gross_amount']) . '. Bitte überweisen Sie den Betrag bis zum ' . date_de($inv['due_date']) . ".\n\nVielen Dank für Ihren Auftrag!\n\nMit freundlichen Grüßen\n\n" . mail_signature();
        return ['to' => $c['email'], 'subject' => 'Rechnung ' . $inv['invoice_number'] . (setting('company') !== '' ? ' – ' . setting('company') : ''), 'body' => $body,
            'file' => 'Rechnung_' . $inv['invoice_number'] . '.pdf', 'pdf' => fn() => invoice_pdf($inv, invoice_items($id), $c), 'back' => ['invoice_show', ['id' => $id]]];
    }
    if ($type === 'offer') {
        $o = offer_load($id); $c = $cust((int)$o['customer_id']);
        $body = $sal($c) . "\n\nvielen Dank für Ihre Anfrage. Anbei erhalten Sie unser Angebot " . $o['offer_number'] . ' über ' . money((int)$o['gross_amount']) . '. Es ist gültig bis zum ' . date_de($o['valid_until']) . ".\n\nBei Fragen melden Sie sich gerne. Wir freuen uns auf Ihren Auftrag.\n\nMit freundlichen Grüßen\n\n" . mail_signature();
        return ['to' => $c['email'], 'subject' => 'Angebot ' . $o['offer_number'] . (setting('company') !== '' ? ' – ' . setting('company') : ''), 'body' => $body,
            'file' => 'Angebot_' . $o['offer_number'] . '.pdf', 'pdf' => fn() => offer_pdf_string($o), 'back' => ['offer_show', ['id' => $id]]];
    }
    if ($type === 'reminder') {
        $r = reminders_load($id); $c = $cust((int)$r['customer_id']);
        $total = (int)$r['open_amount'] + (int)$r['fee'] + (int)$r['interest'];
        $body = $sal($c) . "\n\nanbei erhalten Sie unsere " . REMINDER_LEVELS[$r['level']] . ' zur Rechnung ' . $r['invoice_number'] . '. Der offene Gesamtbetrag von ' . money($total) . ' ist bis zum ' . date_de($r['new_due_date']) . " zu überweisen.\n\nSollten Sie bereits gezahlt haben, betrachten Sie dieses Schreiben bitte als gegenstandslos.\n\nMit freundlichen Grüßen\n\n" . mail_signature();
        return ['to' => $c['email'], 'subject' => REMINDER_LEVELS[$r['level']] . ' zu Rechnung ' . $r['invoice_number'], 'body' => $body,
            'file' => 'Mahnung_' . $r['invoice_number'] . '_Stufe' . $r['level'] . '.pdf', 'pdf' => fn() => reminder_pdf($r), 'back' => ['invoice_show', ['id' => $r['inv_id']]]];
    }
    if ($type === 'delivery') {
        $dn = delivery_load($id); $c = $cust((int)$dn['customer_id']);
        $body = $sal($c) . "\n\nanbei erhalten Sie den Lieferschein " . $dn['note_number'] . " vom " . date_de($dn['note_date']) . ".\n\nMit freundlichen Grüßen\n\n" . mail_signature();
        return ['to' => $c['email'], 'subject' => 'Lieferschein ' . $dn['note_number'] . (setting('company') !== '' ? ' – ' . setting('company') : ''), 'body' => $body,
            'file' => 'Lieferschein_' . $dn['note_number'] . '.pdf', 'pdf' => fn() => delivery_pdf_string($dn), 'back' => ['delivery_show', ['id' => $id]]];
    }
    flash('Unbekannter Dokumenttyp.', 'err'); redirect('dashboard');
}

function mails_form(): void {
    $type = (string)($_GET['type'] ?? ''); $id = (int)($_GET['id'] ?? 0);
    $d = mail_document($type, $id);
    render('mail_form', ['d' => $d, 'type' => $type, 'id' => $id, 'copy' => setting('mail_copy', '1') === '1', 'from' => mail_from_address()], 'E-Mail senden');
}

function mails_send(): void {
    csrf_check();
    $type = post('type'); $id = (int)($_POST['id'] ?? 0);
    $d = mail_document($type, $id);
    $to = post('to'); $subject = post('subject'); $body = (string)($_POST['body'] ?? '');
    if (is_saas() && current_tenant() && !(int)current_tenant()['email_verified']) { flash('Bitte zuerst Ihre E-Mail-Adresse bestätigen (Link in Ihrem Postfach), dann können Sie Mails an Kunden senden.', 'err'); redirect('mail_new', ['type' => $type, 'id' => $id]); }
    $cnt = fn(int $sec) => (int)(function () use ($sec) { $st = db()->prepare('SELECT COUNT(*) FROM mail_log WHERE sent_at >= ?'); $st->execute([date('Y-m-d H:i:s', time() - $sec)]); return $st->fetchColumn(); })();
    if ($cnt(3600) >= 30 || $cnt(86400) >= 150) { audit('mail_limit', 'Versandlimit erreicht'); flash('Versandlimit erreicht (30 Mails pro Stunde, 150 pro Tag). Bitte später erneut versuchen.', 'err'); redirect('mail_new', ['type' => $type, 'id' => $id]); }
    if (strlen($subject) > 200 || strlen($body) > 20000) { flash('Betreff oder Text sind zu lang.', 'err'); redirect('mail_new', ['type' => $type, 'id' => $id]); }
    $err = send_mail($to, $subject, $body, [['name' => preg_replace('/[^A-Za-z0-9_.-]/', '_', $d['file']), 'data' => ($d['pdf'])(), 'mime' => 'application/pdf']], isset($_POST['copy']));
    db()->prepare('INSERT INTO mail_log(doc_type, doc_id, recipient, subject, ok, error, sent_at) VALUES (?,?,?,?,?,?,?)')->execute([$type, $id, $to, mb_substr($subject, 0, 250), $err === null ? 1 : 0, mb_substr((string)$err, 0, 490), date('Y-m-d H:i:s')]);
    if ($err !== null) { flash('E-Mail nicht gesendet: ' . $err, 'err'); redirect('mail_new', ['type' => $type, 'id' => $id]); }
    flash('E-Mail an ' . $to . ' gesendet.');
    redirect($d['back'][0], $d['back'][1]);
}

function mails_test(): void {
    csrf_check();
    $to = mail_from_address();
    $err = send_mail($to, 'Testnachricht Rechnungsprogramm', "Diese Testnachricht zeigt, dass der E-Mail-Versand funktioniert.\n\n" . mail_signature());
    if ($err) flash('Test fehlgeschlagen: ' . $err, 'err'); else flash('Testmail an ' . $to . ' gesendet.');
    redirect('settings');
}

function mail_log_for(string $type, int $id): array {
    $st = db()->prepare('SELECT * FROM mail_log WHERE doc_type = ? AND doc_id = ? ORDER BY id DESC LIMIT 10'); $st->execute([$type, $id]);
    return $st->fetchAll();
}
