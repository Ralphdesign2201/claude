<?php

declare(strict_types=1);

namespace App\Pdf;

use App\Support\Env;
use DateTimeImmutable;
use DateTimeZone;

/** Erzeugt die Rechnung als PDF im deutschen Geschäftsbrief-Layout (A4). Absenderdaten kommen aus der .env (COMPANY_*). */
final class InvoicePdf
{
    private const LEFT = 56.7;
    private const RIGHT = 538.6;
    private const FOOTER_TOP = 770.0;
    private const GREY = [0.42, 0.45, 0.5];
    private const LIGHT = [0.95, 0.96, 0.98];
    private const INK = [0.07, 0.09, 0.14];

    // Spalten der Positionstabelle (rechte Kanten bzw. linke Kante)
    private const COL_POS = 56.7;
    private const COL_DESC = 88.0;
    private const COL_QTY = 372.0;
    private const COL_PRICE = 452.0;
    private const COL_SUM = 538.6;

    private Pdf $pdf;
    /** @var array<string,mixed> */
    private array $inv;
    /** @var array<string,string> */
    private array $co;
    private float $y = 0;

    /**
     * @param array<string,mixed> $invoice Rechnung inkl. client, project, items, payments, totals (wie GET /api/invoices/:id)
     */
    public static function render(array $invoice): string
    {
        return (new self($invoice))->build();
    }

    /** @param array<string,mixed> $invoice */
    private function __construct(array $invoice)
    {
        $this->inv = $invoice;
        $this->co = self::company();
        $this->pdf = new Pdf('Rechnung ' . $invoice['number'], $this->co['name']);
    }

    /** @return array<string,string> */
    public static function company(): array
    {
        return [
            'name' => Env::get('COMPANY_NAME', 'Ihr Firmenname') ?? '',
            'address' => Env::get('COMPANY_ADDRESS', 'Musterstraße 1|12345 Musterstadt') ?? '',
            'email' => Env::get('COMPANY_EMAIL', '') ?? '',
            'phone' => Env::get('COMPANY_PHONE', '') ?? '',
            'web' => Env::get('COMPANY_WEBSITE', '') ?? '',
            'vat' => Env::get('COMPANY_VAT_ID', '') ?? '',
            'tax' => Env::get('COMPANY_TAX_NUMBER', '') ?? '',
            'bank' => Env::get('COMPANY_BANK', '') ?? '',
            'iban' => Env::get('COMPANY_IBAN', '') ?? '',
            'bic' => Env::get('COMPANY_BIC', '') ?? '',
            'zeroTaxNote' => Env::get('INVOICE_ZERO_TAX_NOTE', 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.') ?? '',
            'closing' => Env::get('INVOICE_CLOSING', 'Vielen Dank für Ihren Auftrag und das entgegengebrachte Vertrauen.') ?? '',
        ];
    }

    private function build(): string
    {
        $this->header();
        $this->recipient();
        $this->title();
        $this->itemsTable();
        $this->totals();
        $this->closing();
        $this->footers();
        return $this->pdf->output();
    }

    private function header(): void
    {
        $p = $this->pdf;
        $p->text(self::LEFT, 62, $this->co['name'], 17, true, self::INK);
        $y = 78;
        foreach (array_filter(explode('|', $this->co['address'])) as $line) {
            $p->text(self::LEFT, $y, trim($line), 9, false, self::GREY);
            $y += 12;
        }
        $y = 62;
        foreach (array_filter([$this->co['email'], $this->co['phone'], $this->co['web']]) as $line) {
            $p->textRight(self::RIGHT, $y, $line, 9, false, self::GREY);
            $y += 12;
        }
        $p->line(self::LEFT, 118, self::RIGHT, 118, 0.6, [0.82, 0.84, 0.88]);
    }

    private function recipient(): void
    {
        $p = $this->pdf;
        $c = $this->inv['client'];
        $sender = $this->co['name'] . ' · ' . implode(' · ', array_map('trim', array_filter(explode('|', $this->co['address']))));
        $p->text(self::LEFT, 150, $sender, 7, false, self::GREY);
        $p->line(self::LEFT, 154, self::LEFT + 240, 154, 0.4, [0.85, 0.87, 0.9]);

        $lines = array_filter([
            $c['company'] ?? '',
            $c['company'] ? ($c['name'] ?? '') : '',
            $c['address'] ?? '',
            trim(($c['zip'] ?? '') . ' ' . ($c['city'] ?? '')),
            self::foreignCountry($c['country'] ?? ''),
        ], static fn ($l) => $l !== '' && $l !== null);
        if ($lines === []) {
            $lines = [(string) $c['name']];
        }
        $y = 170;
        foreach ($lines as $i => $line) {
            $p->text(self::LEFT, $y, (string) $line, 10.5, $i === 0, self::INK);
            $y += 14;
        }
        if (!empty($c['vatId'])) {
            $p->text(self::LEFT, $y + 2, 'USt-IdNr.: ' . $c['vatId'], 9, false, self::GREY);
        }

        $info = [['Rechnungsnummer', (string) $this->inv['number']], ['Rechnungsdatum', $this->date($this->inv['issueDate'])]];
        if ($this->inv['dueDate']) {
            $info[] = ['Zahlbar bis', $this->date($this->inv['dueDate'])];
        }
        if (!empty($this->inv['project'])) {
            $info[] = ['Projekt', (string) $this->inv['project']['name']];
        }
        $y = 168;
        foreach ($info as [$label, $value]) {
            $p->text(352, $y, $label, 9, false, self::GREY);
            foreach (array_slice($p->wrap($value, 100, 9.5, true), 0, 2) as $k => $part) {
                $p->textRight(self::RIGHT, $y + $k * 11, $part, 9.5, true, self::INK);
            }
            $y += 16;
        }
    }

    private function title(): void
    {
        $p = $this->pdf;
        $p->text(self::LEFT, 262, 'Rechnung ' . $this->inv['number'], 17, true, self::INK);
        $status = ['DRAFT' => 'ENTWURF', 'CANCELLED' => 'STORNIERT', 'PAID' => 'BEZAHLT'][$this->inv['status']] ?? null;
        if ($status !== null) {
            $color = $this->inv['status'] === 'PAID' ? [0.08, 0.47, 0.23] : [0.75, 0.14, 0.17];
            $p->textRight(self::RIGHT, 262, $status, 12, true, $color);
        }
        $this->y = 284;
    }

    private function tableHeader(): void
    {
        $p = $this->pdf;
        $p->rect(self::LEFT, $this->y, self::RIGHT - self::LEFT, 20, self::LIGHT);
        $ty = $this->y + 13.5;
        $p->text(self::COL_POS + 4, $ty, 'Pos.', 8.5, true, self::GREY);
        $p->text(self::COL_DESC, $ty, 'Beschreibung', 8.5, true, self::GREY);
        $p->textRight(self::COL_QTY, $ty, 'Menge', 8.5, true, self::GREY);
        $p->textRight(self::COL_PRICE, $ty, 'Einzelpreis', 8.5, true, self::GREY);
        $p->textRight(self::COL_SUM - 4, $ty, 'Gesamt', 8.5, true, self::GREY);
        $this->y += 20;
    }

    private function itemsTable(): void
    {
        $p = $this->pdf;
        $this->tableHeader();
        $descWidth = self::COL_QTY - 40 - self::COL_DESC;

        foreach ($this->inv['items'] as $i => $item) {
            $lines = $p->wrap((string) $item['description'], $descWidth, 10);
            $height = max(1, count($lines)) * 13 + 10;
            if ($this->y + $height > self::FOOTER_TOP - 40) {
                $p->addPage();
                $this->y = 70;
                $this->tableHeader();
            }
            $ty = $this->y + 15;
            $p->text(self::COL_POS + 4, $ty, (string) ($i + 1), 10, false, self::GREY);
            foreach ($lines as $k => $line) {
                $p->text(self::COL_DESC, $ty + $k * 13, $line, 10, false, self::INK);
            }
            $p->textRight(self::COL_QTY, $ty, self::qty((float) $item['quantity']), 10, false, self::INK);
            $p->textRight(self::COL_PRICE, $ty, $this->money((float) $item['unitPrice']), 10, false, self::INK);
            $p->textRight(self::COL_SUM - 4, $ty, $this->money(round($item['quantity'] * $item['unitPrice'], 2)), 10, false, self::INK);
            $this->y += $height;
            $p->line(self::LEFT, $this->y, self::RIGHT, $this->y, 0.4, [0.88, 0.9, 0.93]);
        }
        $this->y += 8;
    }

    private function totals(): void
    {
        $p = $this->pdf;
        $t = $this->inv['totals'];
        $rows = [['Zwischensumme (netto)', $this->money($t['subtotal']), false]];
        if ((float) $this->inv['discount'] > 0) {
            $rows[] = ['Rabatt', '- ' . $this->money((float) $this->inv['discount']), false];
        }
        $rows[] = ['Umsatzsteuer ' . self::qty((float) $this->inv['taxRate']) . ' %', $this->money($t['tax']), false];
        $rows[] = ['Gesamtbetrag', $this->money($t['total']), true];
        if ($t['paid'] > 0) {
            $rows[] = ['Bereits bezahlt', '- ' . $this->money($t['paid']), false];
            $rows[] = ['Offener Betrag', $this->money($t['balance']), true];
        }

        $this->ensureSpace(count($rows) * 18 + 20);
        foreach ($rows as [$label, $value, $bold]) {
            if ($bold) {
                $p->line(300, $this->y + 2, self::RIGHT, $this->y + 2, 0.6, [0.6, 0.63, 0.7]);
                $this->y += 6;
            }
            $p->text(300, $this->y + 12, $label, $bold ? 10.5 : 9.5, $bold, $bold ? self::INK : self::GREY);
            $p->textRight(self::RIGHT - 4, $this->y + 12, $value, $bold ? 10.5 : 9.5, $bold, self::INK);
            $this->y += 17;
        }
        $this->y += 10;

        if ((float) $this->inv['taxRate'] == 0.0 && $this->co['zeroTaxNote'] !== '') {
            $this->paragraph($this->co['zeroTaxNote'], 9, self::GREY);
        }
    }

    private function closing(): void
    {
        $inv = $this->inv;
        $t = $inv['totals'];

        if ($inv['status'] === 'PAID' || $t['balance'] <= 0) {
            $paid = $inv['payments'] ? $this->date($inv['payments'][0]['paidAt']) : null;
            $this->paragraph('Der Rechnungsbetrag wurde' . ($paid ? " am $paid" : '') . ' beglichen. Vielen Dank!', 10, self::INK);
        } elseif ($inv['status'] !== 'CANCELLED') {
            $due = $inv['dueDate'] ? ' bis spätestens ' . $this->date($inv['dueDate']) : ' ohne Abzug';
            $text = 'Bitte überweisen Sie den offenen Betrag von ' . $this->money($t['balance']) . $due
                . ' unter Angabe der Rechnungsnummer ' . $inv['number'] . ' auf das folgende Konto:';
            $this->paragraph($text, 10, self::INK);
            $bank = array_filter([
                $this->co['bank'] !== '' ? $this->co['bank'] : null,
                $this->co['iban'] !== '' ? 'IBAN: ' . $this->co['iban'] : null,
                $this->co['bic'] !== '' ? 'BIC: ' . $this->co['bic'] : null,
            ]);
            foreach ($bank as $line) {
                $this->ensureSpace(16);
                $this->pdf->text(self::LEFT + 12, $this->y + 10, (string) $line, 10, true, self::INK);
                $this->y += 14;
            }
            $this->y += 6;
        }

        if (!empty($inv['notes'])) {
            $this->paragraph((string) $inv['notes'], 9.5, self::GREY);
        }
        if ($this->co['closing'] !== '' && $inv['status'] !== 'CANCELLED') {
            $this->paragraph($this->co['closing'], 10, self::INK);
        }
    }

    private function footers(): void
    {
        $p = $this->pdf;
        $total = $p->pageCount();
        $address = array_map('trim', array_filter(explode('|', $this->co['address'])));
        $cols = [
            array_merge([$this->co['name']], $address),
            array_filter([$this->co['email'], $this->co['phone'], $this->co['web']]),
            array_filter([
                $this->co['bank'],
                $this->co['iban'] !== '' ? 'IBAN ' . $this->co['iban'] : '',
                $this->co['bic'] !== '' ? 'BIC ' . $this->co['bic'] : '',
                $this->co['vat'] !== '' ? 'USt-IdNr. ' . $this->co['vat'] : '',
                $this->co['tax'] !== '' ? 'Steuernr. ' . $this->co['tax'] : '',
            ]),
        ];
        for ($i = 0; $i < $total; $i++) {
            $p->setPage($i);
            $p->line(self::LEFT, self::FOOTER_TOP, self::RIGHT, self::FOOTER_TOP, 0.5, [0.82, 0.84, 0.88]);
            foreach ($cols as $c => $lines) {
                $y = self::FOOTER_TOP + 14;
                foreach (array_slice(array_values($lines), 0, 5) as $line) {
                    $p->text(self::LEFT + $c * 165, $y, (string) $line, 7.5, false, self::GREY);
                    $y += 10;
                }
            }
            $p->textRight(self::RIGHT, 825, 'Seite ' . ($i + 1) . ' von ' . $total, 7.5, false, self::GREY);
        }
    }

    /** Absatz in voller Breite; bricht bei Bedarf auf eine neue Seite um. */
    private function paragraph(string $text, float $size, array $color): void
    {
        foreach ($this->pdf->wrap($text, self::RIGHT - self::LEFT, $size) as $line) {
            $this->ensureSpace(14);
            $this->pdf->text(self::LEFT, $this->y + 10, $line, $size, false, $color);
            $this->y += $size + 4;
        }
        $this->y += 8;
    }

    private function ensureSpace(float $needed): void
    {
        if ($this->y + $needed > self::FOOTER_TOP - 20) {
            $this->pdf->addPage();
            $this->y = 70;
        }
    }

    private function money(float $amount): string
    {
        $currency = (string) ($this->inv['currency'] ?? 'EUR');
        return number_format($amount, 2, ',', '.') . ' ' . ($currency === 'EUR' ? '€' : $currency);
    }

    private static function qty(float $value): string
    {
        return (string) preg_replace('/,?0+$/', '', number_format($value, 2, ',', '.'));
    }

    private function date(?string $iso): string
    {
        if (!$iso) {
            return '–';
        }
        $zone = new DateTimeZone(Env::get('APP_TIMEZONE', 'Europe/Berlin') ?? 'Europe/Berlin');
        return (new DateTimeImmutable($iso))->setTimezone($zone)->format('d.m.Y');
    }

    private static function foreignCountry(string $country): string
    {
        $country = trim($country);
        return in_array(strtolower($country), ['', 'de', 'deutschland', 'germany'], true) ? '' : $country;
    }
}
