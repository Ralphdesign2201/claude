<?php

declare(strict_types=1);

namespace App\Pdf;

use App\Support\Env;
use App\Support\Format;

/**
 * Gemeinsames Layout für Rechnung, Angebot und Mahnung (A4, deutsches Geschäftsbrief-Schema).
 * Absenderdaten kommen aus der .env (COMPANY_*).
 */
abstract class DocumentPdf
{
    protected const LEFT = 56.7;
    protected const RIGHT = 538.6;
    protected const FOOTER_TOP = 770.0;
    protected const GREY = [0.42, 0.45, 0.5];
    protected const LIGHT = [0.95, 0.96, 0.98];
    protected const INK = [0.07, 0.09, 0.14];

    protected Pdf $pdf;
    /** @var array<string,string> */
    protected array $co;
    protected float $y = 0;

    protected function __construct(string $title)
    {
        $this->co = self::company();
        $this->pdf = new Pdf($title, $this->co['name']);
    }

    /** @return array<string,string> */
    public static function company(): array
    {
        $get = static fn (string $key, string $default = ''): string => Env::get($key, $default) ?? $default;
        return [
            'name' => $get('COMPANY_NAME', 'Ihr Firmenname'),
            'address' => $get('COMPANY_ADDRESS', 'Musterstraße 1|12345 Musterstadt'),
            'email' => $get('COMPANY_EMAIL'),
            'phone' => $get('COMPANY_PHONE'),
            'web' => $get('COMPANY_WEBSITE'),
            'vat' => $get('COMPANY_VAT_ID'),
            'tax' => $get('COMPANY_TAX_NUMBER'),
            'bank' => $get('COMPANY_BANK'),
            'iban' => $get('COMPANY_IBAN'),
            'bic' => $get('COMPANY_BIC'),
            'zeroTaxNote' => $get('INVOICE_ZERO_TAX_NOTE', 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.'),
            'closing' => $get('INVOICE_CLOSING', 'Vielen Dank für Ihren Auftrag und das entgegengebrachte Vertrauen.'),
            'quoteClosing' => $get('QUOTE_CLOSING', 'Wir freuen uns auf Ihre Beauftragung.'),
        ];
    }

    protected function header(): void
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

    /**
     * Empfängerblock links, Infozeilen (Bezeichnung/Wert) rechts.
     *
     * @param array<string,mixed> $client
     * @param list<array{0:string,1:string}> $info
     */
    protected function recipient(array $client, array $info): void
    {
        $p = $this->pdf;
        $sender = $this->co['name'] . ' · ' . implode(' · ', array_map('trim', array_filter(explode('|', $this->co['address']))));
        $p->text(self::LEFT, 150, $sender, 7, false, self::GREY);
        $p->line(self::LEFT, 154, self::LEFT + 240, 154, 0.4, [0.85, 0.87, 0.9]);

        $lines = array_values(array_filter([
            $client['company'] ?? '',
            !empty($client['company']) ? ($client['name'] ?? '') : '',
            $client['address'] ?? '',
            trim(($client['zip'] ?? '') . ' ' . ($client['city'] ?? '')),
            self::foreignCountry((string) ($client['country'] ?? '')),
        ], static fn ($l) => $l !== '' && $l !== null));
        if ($lines === []) {
            $lines = [(string) $client['name']];
        }
        $y = 170;
        foreach ($lines as $i => $line) {
            $p->text(self::LEFT, $y, (string) $line, 10.5, $i === 0, self::INK);
            $y += 14;
        }
        if (!empty($client['vatId'])) {
            $p->text(self::LEFT, $y + 2, 'USt-IdNr.: ' . $client['vatId'], 9, false, self::GREY);
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

    /** Seitenfuß mit Firmen- und Bankdaten und Seitenzahl auf jeder Seite. */
    protected function footers(): void
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
    protected function paragraph(string $text, float $size, array $color): void
    {
        foreach ($this->pdf->wrap($text, self::RIGHT - self::LEFT, $size) as $line) {
            $this->ensureSpace(14);
            $this->pdf->text(self::LEFT, $this->y + 10, $line, $size, false, $color);
            $this->y += $size + 4;
        }
        $this->y += 8;
    }

    /** Kontodaten als fette Zeilen. */
    protected function bankLines(): void
    {
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

    protected function ensureSpace(float $needed): void
    {
        if ($this->y + $needed > self::FOOTER_TOP - 20) {
            $this->pdf->addPage();
            $this->y = 70;
        }
    }

    protected function money(float $amount, string $currency = 'EUR'): string
    {
        return Format::money($amount, $currency);
    }

    protected static function foreignCountry(string $country): string
    {
        $country = trim($country);
        return in_array(strtolower($country), ['', 'de', 'deutschland', 'germany'], true) ? '' : $country;
    }
}
