<?php
declare(strict_types=1);

const DATEV_FIELDS = ['datev_berater', 'datev_mandant', 'datev_acc_19', 'datev_acc_7', 'datev_acc_0', 'datev_acc_small', 'datev_bank', 'datev_debitor_base'];
const DATEV_DEFAULTS = ['datev_berater' => '0', 'datev_mandant' => '0', 'datev_acc_19' => '8400', 'datev_acc_7' => '8300', 'datev_acc_0' => '8200', 'datev_acc_small' => '8195', 'datev_bank' => '1200', 'datev_debitor_base' => '10000'];

function datev_index(): void {
    $cfg = []; foreach (DATEV_FIELDS as $f) $cfg[$f] = setting($f, DATEV_DEFAULTS[$f]);
    $y = (int)date('Y'); $m = (int)date('n');
    $q = intdiv($m - 1, 3); // vorheriges Quartal als Vorschlag
    $pq = $q === 0 ? 3 : $q - 1; $py = $q === 0 ? $y - 1 : $y;
    $from = sprintf('%04d-%02d-01', $py, $pq * 3 + 1); $to = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $py, $pq * 3 + 3)));
    render('datev', ['cfg' => $cfg, 'from' => $from, 'to' => $to], 'Export');
}

function datev_money(int $cents): string { return number_format($cents / 100, 2, ',', ''); }
function datev_text(string $s, int $max): string { return '"' . str_replace('"', '""', mb_substr(trim(preg_replace('/\s+/', ' ', $s)), 0, $max)) . '"'; }

function datev_export(): void {
    csrf_check();
    $from = valid_date(post('from')); $to = valid_date(post('to'));
    if (!$from || !$to || $to < $from) { flash('Bitte einen gültigen Zeitraum wählen.', 'err'); redirect('datev'); }
    foreach (DATEV_FIELDS as $f) {
        $v = preg_replace('/\D/', '', post($f)); if ($v === '') $v = DATEV_DEFAULTS[$f];
        set_setting($f, $v);
    }
    $kind = post('kind');
    $cfg = fn(string $k) => setting($k, DATEV_DEFAULTS[$k]);
    $pdo = db();

    if ($kind === 'csv') { datev_invoice_csv($from, $to); return; }

    $rows = []; // [datum, zeile]
    $debitor = fn(int $cid) => (string)((int)$cfg('datev_debitor_base') + $cid);
    $revenue = fn(float $rate, bool $small) => $small ? $cfg('datev_acc_small') : ($rate >= 15 ? $cfg('datev_acc_19') : ($rate > 0 ? $cfg('datev_acc_7') : $cfg('datev_acc_0')));
    $line = function (int $amount, string $sh, string $konto, string $gegen, string $date, string $beleg, string $text) {
        return datev_money($amount) . ';"' . $sh . '";"EUR";;;;' . $konto . ';' . $gegen . ';;' . date('dm', strtotime($date)) . ';' . datev_text($beleg, 36) . ';;;' . datev_text($text, 60);
    };
    $st = $pdo->prepare('SELECT i.*, c.company, c.firstname, c.lastname FROM invoices i JOIN customers c ON c.id = i.customer_id ORDER BY i.invoice_date, i.id');
    $st->execute();
    $itemSt = $pdo->prepare('SELECT quantity, unit_price, vat_rate FROM invoice_items WHERE invoice_id = ?');
    foreach ($st->fetchAll() as $inv) {
        $name = $inv['company'] !== '' ? $inv['company'] : trim($inv['firstname'] . ' ' . $inv['lastname']);
        $itemSt->execute([$inv['id']]);
        $items = array_map(fn($r) => ['quantity' => (float)$r['quantity'], 'unit_price' => (int)$r['unit_price'], 'vat_rate' => (float)$r['vat_rate']], $itemSt->fetchAll());
        $calc = calc_invoice($items); $small = (bool)$inv['small_business'];
        $inRange = fn(?string $d) => $d && $d >= $from && $d <= $to;
        foreach ($calc['net_by_rate'] as $rate => $net) {
            $gross = $net + $calc['vat_by_rate'][$rate];
            $acc = $revenue((float)$rate, $small);
            if ($inRange($inv['invoice_date'])) $rows[] = [$inv['invoice_date'], $line($gross, 'S', $debitor((int)$inv['customer_id']), $acc, $inv['invoice_date'], $inv['invoice_number'], 'RE ' . $inv['invoice_number'] . ' ' . $name)];
            if ($inv['status'] === 'cancelled' && $inRange($inv['cancelled_at']))
                $rows[] = [$inv['cancelled_at'], $line($gross, 'H', $debitor((int)$inv['customer_id']), $acc, $inv['cancelled_at'], $inv['invoice_number'], 'Storno ' . $inv['invoice_number'] . ' ' . $name)];
        }
        if ($kind === 'full' && $inv['status'] === 'paid' && $inRange($inv['paid_date']))
            $rows[] = [$inv['paid_date'], $line((int)$inv['gross_amount'], 'S', $cfg('datev_bank'), $debitor((int)$inv['customer_id']), $inv['paid_date'], $inv['invoice_number'], 'Zahlung ' . $inv['invoice_number'] . ' ' . $name)];
    }
    usort($rows, fn($a, $b) => strcmp($a[0], $b[0]));

    $nl = "\r\n";
    $fyStart = substr($from, 0, 4) . '0101';
    $out = '"EXTF";700;21;"Buchungsstapel";12;' . date('YmdHis') . '000;;"RE";"";"";' . $cfg('datev_berater') . ';' . $cfg('datev_mandant') . ';' . $fyStart . ';4;' . str_replace('-', '', $from) . ';' . str_replace('-', '', $to) . ';"Rechnungen";"";1;0;0;"EUR";;"";;;;' . $nl;
    $out .= '"Umsatz (ohne Soll/Haben-Kz)";"Soll/Haben-Kennzeichen";"WKZ Umsatz";"Kurs";"Basis-Umsatz";"WKZ Basis-Umsatz";"Konto";"Gegenkonto (ohne BU-Schlüssel)";"BU-Schlüssel";"Belegdatum";"Belegfeld 1";"Belegfeld 2";"Skonto";"Buchungstext"' . $nl;
    foreach ($rows as [, $l]) $out .= $l . $nl;
    $out = function_exists('iconv') ? (@iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $out) ?: $out) : mb_convert_encoding($out, 'Windows-1252', 'UTF-8');
    header('Content-Type: text/csv; charset=windows-1252');
    header('Content-Disposition: attachment; filename="EXTF_Buchungsstapel_' . $from . '_' . $to . '.csv"');
    header('Content-Length: ' . strlen($out));
    echo $out;
}

/** Einfache Rechnungsübersicht für Excel (Semikolon, UTF-8 mit BOM). */
function datev_invoice_csv(string $from, string $to): void {
    $st = db()->prepare('SELECT i.*, c.company, c.firstname, c.lastname FROM invoices i JOIN customers c ON c.id = i.customer_id WHERE i.invoice_date BETWEEN ? AND ? ORDER BY i.invoice_date, i.id');
    $st->execute([$from, $to]);
    $q = fn(string $s) => '"' . str_replace('"', '""', $s) . '"';
    $out = "\xEF\xBB\xBF" . "Rechnungsnummer;Datum;Kunde;Netto;USt;Brutto;Status;Zahlbar bis;Bezahlt am\r\n";
    foreach ($st->fetchAll() as $i) {
        $name = $i['company'] !== '' ? $i['company'] : trim($i['firstname'] . ' ' . $i['lastname']);
        $out .= implode(';', [$q($i['invoice_number']), date_de($i['invoice_date']), $q($name), datev_money((int)$i['net_amount']), datev_money((int)$i['vat_amount']), datev_money((int)$i['gross_amount']), status_label($i['status'], is_overdue($i)), date_de($i['due_date']), date_de($i['paid_date'])]) . "\r\n";
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Rechnungen_' . $from . '_' . $to . '.csv"');
    echo $out;
}
