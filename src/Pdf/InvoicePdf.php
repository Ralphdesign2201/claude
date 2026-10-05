<?php

declare(strict_types=1);

namespace App\Pdf;

use App\Support\Format;

/** Rechnung bzw. Angebot als PDF (gleiches Layout, andere Beschriftung). */
final class InvoicePdf extends DocumentPdf
{
    // Spalten der Positionstabelle (rechte Kanten bzw. linke Kante)
    private const COL_POS = 56.7;
    private const COL_DESC = 88.0;
    private const COL_QTY = 372.0;
    private const COL_PRICE = 452.0;
    private const COL_SUM = 538.6;

    private const STATUS_LABELS = [
        'invoice' => ['DRAFT' => 'ENTWURF', 'CANCELLED' => 'STORNIERT', 'PAID' => 'BEZAHLT'],
        'quote' => ['DRAFT' => 'ENTWURF', 'ACCEPTED' => 'ANGENOMMEN', 'DECLINED' => 'ABGELEHNT', 'EXPIRED' => 'ABGELAUFEN'],
    ];

    /** @var array<string,mixed> */
    private array $doc;
    private bool $quote;

    /**
     * @param array<string,mixed> $document Rechnung bzw. Angebot inkl. client, project, items, payments, totals
     * @param 'invoice'|'quote' $kind
     */
    public static function render(array $document, string $kind = 'invoice'): string
    {
        return (new self($document, $kind))->build();
    }

    /** @param array<string,mixed> $document */
    private function __construct(array $document, string $kind)
    {
        $this->doc = $document;
        $this->quote = $kind === 'quote';
        parent::__construct(($this->quote ? 'Angebot ' : 'Rechnung ') . $document['number']);
    }

    private function build(): string
    {
        $this->header();
        $this->recipient($this->doc['client'], $this->infoRows());
        $this->title();
        $this->itemsTable();
        $this->totals();
        $this->closing();
        $this->footers();
        return $this->pdf->output();
    }

    /** @return list<array{0:string,1:string}> */
    private function infoRows(): array
    {
        $d = $this->doc;
        $rows = $this->quote
            ? [['Angebotsnummer', (string) $d['number']], ['Angebotsdatum', Format::date($d['issueDate'])]]
            : [['Rechnungsnummer', (string) $d['number']], ['Rechnungsdatum', Format::date($d['issueDate'])]];
        if ($this->quote && $d['validUntil']) {
            $rows[] = ['Gültig bis', Format::date($d['validUntil'])];
        }
        if (!$this->quote && $d['dueDate']) {
            $rows[] = ['Zahlbar bis', Format::date($d['dueDate'])];
        }
        if (!empty($d['project'])) {
            $rows[] = ['Projekt', (string) $d['project']['name']];
        }
        return $rows;
    }

    private function title(): void
    {
        $p = $this->pdf;
        $p->text(self::LEFT, 262, ($this->quote ? 'Angebot ' : 'Rechnung ') . $this->doc['number'], 17, true, self::INK);
        $status = self::STATUS_LABELS[$this->quote ? 'quote' : 'invoice'][$this->doc['status']] ?? null;
        if ($status !== null) {
            $good = in_array($this->doc['status'], ['PAID', 'ACCEPTED'], true);
            $p->textRight(self::RIGHT, 262, $status, 12, true, $good ? [0.08, 0.47, 0.23] : [0.75, 0.14, 0.17]);
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
        $currency = (string) $this->doc['currency'];
        $this->tableHeader();
        $descWidth = self::COL_QTY - 40 - self::COL_DESC;

        foreach ($this->doc['items'] as $i => $item) {
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
            $p->textRight(self::COL_QTY, $ty, Format::qty((float) $item['quantity']), 10, false, self::INK);
            $p->textRight(self::COL_PRICE, $ty, $this->money((float) $item['unitPrice'], $currency), 10, false, self::INK);
            $p->textRight(self::COL_SUM - 4, $ty, $this->money(round($item['quantity'] * $item['unitPrice'], 2), $currency), 10, false, self::INK);
            $this->y += $height;
            $p->line(self::LEFT, $this->y, self::RIGHT, $this->y, 0.4, [0.88, 0.9, 0.93]);
        }
        $this->y += 8;
    }

    private function totals(): void
    {
        $p = $this->pdf;
        $t = $this->doc['totals'];
        $c = (string) $this->doc['currency'];
        $rows = [['Zwischensumme (netto)', $this->money($t['subtotal'], $c), false]];
        if ((float) $this->doc['discount'] > 0) {
            $rows[] = ['Rabatt', '- ' . $this->money((float) $this->doc['discount'], $c), false];
        }
        $rows[] = ['Umsatzsteuer ' . Format::qty((float) $this->doc['taxRate']) . ' %', $this->money($t['tax'], $c), false];
        $rows[] = ['Gesamtbetrag', $this->money($t['total'], $c), true];
        if (!$this->quote && $t['paid'] > 0) {
            $rows[] = ['Bereits bezahlt', '- ' . $this->money($t['paid'], $c), false];
            $rows[] = ['Offener Betrag', $this->money($t['balance'], $c), true];
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

        if ((float) $this->doc['taxRate'] == 0.0 && $this->co['zeroTaxNote'] !== '') {
            $this->paragraph($this->co['zeroTaxNote'], 9, self::GREY);
        }
    }

    private function closing(): void
    {
        $d = $this->doc;
        $t = $d['totals'];

        if ($this->quote) {
            if ($d['validUntil']) {
                $this->paragraph('Dieses Angebot ist gültig bis zum ' . Format::date($d['validUntil']) . '.', 10, self::INK);
            }
        } elseif ($d['status'] === 'PAID' || $t['balance'] <= 0) {
            $paid = $d['payments'] ? Format::date($d['payments'][0]['paidAt']) : null;
            $this->paragraph('Der Rechnungsbetrag wurde' . ($paid ? " am $paid" : '') . ' beglichen. Vielen Dank!', 10, self::INK);
        } elseif ($d['status'] !== 'CANCELLED') {
            $due = $d['dueDate'] ? ' bis spätestens ' . Format::date($d['dueDate']) : ' ohne Abzug';
            $this->paragraph('Bitte überweisen Sie den offenen Betrag von ' . $this->money($t['balance'], (string) $d['currency']) . $due
                . ' unter Angabe der Rechnungsnummer ' . $d['number'] . ' auf das folgende Konto:', 10, self::INK);
            $this->bankLines();
        }

        if (!empty($d['notes'])) {
            $this->paragraph((string) $d['notes'], 9.5, self::GREY);
        }
        $closing = $this->quote ? $this->co['quoteClosing'] : $this->co['closing'];
        if ($closing !== '' && $d['status'] !== 'CANCELLED') {
            $this->paragraph($closing, 10, self::INK);
        }
    }
}
