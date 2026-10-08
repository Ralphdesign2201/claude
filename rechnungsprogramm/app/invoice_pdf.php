<?php
declare(strict_types=1);
require_once __DIR__ . '/pdf.php';

function invoice_pdf(array $inv, array $items): string
{
    $pdf = new Pdf();
    $grey = [110, 110, 110]; $accent = [30, 64, 110]; $lightBg = [238, 242, 247];
    $L = 20.0; $R = 190.0; $footerTop = 270.0;
    $logo = APP_STORAGE . '/logo.jpg';
    $hasLogo = is_file($logo);
    $company = setting('company');
    $senderLine = trim($company . ' · ' . setting('street') . ' · ' . trim(setting('zip') . ' ' . setting('city')), ' ·');
    $small = (bool)$inv['small_business'];

    $page = function () use ($pdf, $inv, $L, $R, $footerTop, $logo, $hasLogo, $company, $grey) {
        $pdf->addPage();
        if ($hasLogo) {
            $s = getimagesize($logo);
            $h = 18.0; $w = $h * $s[0] / $s[1];
            if ($w > 60) { $w = 60.0; $h = $w * $s[1] / $s[0]; }
            $pdf->image($logo, $R - $w, 12, $w, $h);
        } elseif ($company !== '') {
            $pdf->text($R, 20, $company, 14, true, 'R', [30, 64, 110]);
        }
        // Fußzeile
        $pdf->line($L, $footerTop, $R, $footerTop, 0.2, [180, 180, 180]);
        $cols = [
            [$company, setting('owner'), setting('street'), trim(setting('zip') . ' ' . setting('city'))],
            array_filter(['Tel. ' . setting('phone'), setting('email'), setting('website')], fn($x) => !in_array($x, ['Tel. ', ''], true)),
            array_filter([setting('bank') !== '' ? setting('bank') : null, setting('iban') !== '' ? 'IBAN ' . setting('iban') : null, setting('bic') !== '' ? 'BIC ' . setting('bic') : null]),
            array_filter([setting('tax_number') !== '' ? 'St.-Nr. ' . setting('tax_number') : null, setting('vat_id') !== '' ? 'USt-IdNr. ' . setting('vat_id') : null]),
        ];
        $x = $L; $cw = ($R - $L) / 4;
        foreach ($cols as $col) {
            $y = $footerTop + 4;
            foreach ($col as $line) { if ($line !== '') { $pdf->text($x, $y, (string)$line, 7, false, 'L', $grey); $y += 3.2; } }
            $x += $cw;
        }
    };
    $page();
    if ($inv['status'] === 'cancelled') $pdf->textRotated(52, 215, 'STORNIERT', 62, 35, [248, 222, 222]);

    // Absenderzeile + Anschrift (DIN 5008)
    $pdf->text($L, 47, $senderLine, 7, false, 'L', $grey);
    $pdf->line($L, 48, $L + 85, 48, 0.1, [180, 180, 180]);
    $y = 54;
    foreach (explode("\n", $inv['customer_address']) as $i => $line) { $pdf->text($L, $y, $line, 10.5, $i === 0); $y += 4.8; }

    // Infoblock rechts
    $ix = 125; $iy = 52;
    $info = [['Rechnungs-Nr.', $inv['invoice_number']], ['Rechnungsdatum', date_de($inv['invoice_date'])]];
    if ($inv['service_date'] !== '') $info[] = ['Leistungsdatum', $inv['service_date']];
    $info[] = ['Zahlbar bis', date_de($inv['due_date'])];
    $info[] = ['Kunden-Nr.', (string)$inv['customer_id']];
    foreach ($info as [$k, $v]) { $pdf->text($ix, $iy, $k, 8.5, false, 'L', $grey); $pdf->text($R, $iy, $v, 9, true, 'R'); $iy += 4.8; }

    // Titel
    $y = 98;
    $pdf->text($L, $y, 'Rechnung ' . $inv['invoice_number'], 15, true, 'L', $accent);
    $y += 7;
    if ($inv['subject'] !== '') { $pdf->text($L, $y, $inv['subject'], 10.5, true); $y += 6; }
    if ($inv['intro'] !== '') {
        foreach ($pdf->wrap($inv['intro'], $R - $L, 9.5) as $ln) { $pdf->text($L, $y, $ln, 9.5); $y += 4.5; }
        $y += 2;
    }
    $y += 2;

    // Tabelle
    $cx = ['pos' => $L + 1, 'desc' => $L + 9, 'qty' => 118, 'unit' => 121, 'price' => 156, 'vat' => 166, 'total' => $R - 1];
    $head = function (float $y) use ($pdf, $cx, $L, $R, $lightBg, $small) {
        $pdf->rect($L, $y - 4.2, $R - $L, 6.2, $lightBg);
        $pdf->text($cx['pos'], $y, 'Pos.', 8.5, true);
        $pdf->text($cx['desc'], $y, 'Beschreibung', 8.5, true);
        $pdf->text($cx['qty'], $y, 'Menge', 8.5, true, 'R');
        $pdf->text($cx['unit'], $y, 'Einheit', 8.5, true);
        $pdf->text($cx['price'], $y, 'Einzelpreis', 8.5, true, 'R');
        if (!$small) $pdf->text($cx['vat'], $y, 'USt', 8.5, true, 'L');
        $pdf->text($cx['total'], $y, 'Netto', 8.5, true, 'R');
        return $y + 6;
    };
    $y = $head($y);
    $descW = $cx['qty'] - $cx['desc'] - 12;
    foreach ($items as $i => $it) {
        $lines = $pdf->wrap($it['description'], $descW, 9.5);
        $need = count($lines) * 4.4 + 2.5;
        if ($y + $need > $footerTop - 40) { $page(); $y = $head(30); }
        $pdf->text($cx['pos'], $y, (string)($i + 1), 9.5);
        foreach ($lines as $ln) { $pdf->text($cx['desc'], $y, $ln, 9.5); $y += 4.4; }
        $yy = $y - 4.4 * count($lines);
        $pdf->text($cx['qty'], $yy, qty_fmt((float)$it['quantity']), 9.5, false, 'R');
        $pdf->text($cx['unit'], $yy, $it['unit'], 9.5);
        $pdf->text($cx['price'], $yy, money_plain((int)$it['unit_price']), 9.5, false, 'R');
        if (!$small) $pdf->text($cx['vat'], $yy, qty_fmt((float)$it['vat_rate']) . ' %', 9.5);
        $pdf->text($cx['total'], $yy, money_plain((int)$it['total']), 9.5, false, 'R');
        $y += 1.2;
        $pdf->line($L, $y, $R, $y, 0.1, [215, 215, 215]);
        $y += 3.8;
    }

    // Summen
    $calc = calc_invoice(array_map(fn($i) => ['quantity' => (float)$i['quantity'], 'unit_price' => (int)$i['unit_price'], 'vat_rate' => (float)$i['vat_rate']], $items));
    $rows = [];
    $rows[] = ['Nettobetrag', money((int)$inv['net_amount']), false];
    if (!$small) foreach ($calc['vat_by_rate'] as $rate => $v) $rows[] = ['Umsatzsteuer ' . qty_fmt((float)$rate) . ' %', money($v), false];
    $rows[] = ['Gesamtbetrag', money((int)$inv['gross_amount']), true];
    $need = count($rows) * 5.5 + 40;
    if ($y + $need > $footerTop) { $page(); $y = 30; }
    $y += 2;
    foreach ($rows as [$k, $v, $b]) {
        if ($b) { $pdf->rect(115, $y - 4.4, $R - 115, 6.8, $lightBg); $pdf->line(115, $y - 4.4, $R, $y - 4.4, 0.4, $accent); }
        $pdf->text(117, $y, $k, $b ? 10.5 : 9.5, $b);
        $pdf->text($R - 1, $y, $v, $b ? 10.5 : 9.5, $b, 'R');
        $y += $b ? 8 : 5.2;
    }
    $y += 4;

    $texts = [];
    if ($small) $texts[] = 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.';
    if ($inv['status'] !== 'cancelled') {
        $pay = 'Bitte überweisen Sie den Betrag bis zum ' . date_de($inv['due_date']) . ' unter Angabe der Rechnungsnummer ' . $inv['invoice_number'] . ' auf das unten genannte Konto.';
        if ($inv['status'] === 'paid' && $inv['paid_date']) $pay = 'Der Betrag wurde am ' . date_de($inv['paid_date']) . ' bezahlt. Vielen Dank!';
        $texts[] = $pay;
    }
    if ($inv['notes'] !== '') $texts[] = $inv['notes'];
    if (setting('footer_text') !== '') $texts[] = setting('footer_text');
    foreach ($texts as $t) {
        $ls = $pdf->wrap($t, $R - $L, 9);
        if ($y + count($ls) * 4.2 > $footerTop - 3) { $page(); $y = 30; }
        foreach ($ls as $ln) { $pdf->text($L, $y, $ln, 9); $y += 4.2; }
        $y += 2.5;
    }

    return $pdf->output('Rechnung ' . $inv['invoice_number']);
}
