<?php

declare(strict_types=1);

namespace App\Pdf;

use App\Services\MailTemplates;
use App\Support\Format;

/** Zahlungserinnerung bzw. Mahnung als Brief mit Forderungsaufstellung. */
final class ReminderPdf extends DocumentPdf
{
    /** @var array<string,mixed> */
    private array $reminder;
    /** @var array<string,mixed> */
    private array $invoice;

    /**
     * @param array<string,mixed> $reminder Datensatz aus der Tabelle Reminder (level, fee, dueDate, message, createdAt)
     * @param array<string,mixed> $invoice Rechnung inkl. client, totals (wie InvoiceService::detail)
     */
    public static function render(array $reminder, array $invoice): string
    {
        return (new self($reminder, $invoice))->build();
    }

    /** @param array<string,mixed> $reminder @param array<string,mixed> $invoice */
    private function __construct(array $reminder, array $invoice)
    {
        $this->reminder = $reminder;
        $this->invoice = $invoice;
        parent::__construct(MailTemplates::LEVEL_NAMES[$reminder['level']] . ' ' . $invoice['number']);
    }

    private function build(): string
    {
        $i = $this->invoice;
        $r = $this->reminder;
        $this->header();
        $this->recipient($i['client'], [
            ['Datum', Format::date($r['createdAt'])],
            ['Rechnungsnummer', (string) $i['number']],
            ['Rechnungsdatum', Format::date($i['issueDate'])],
            ['Neue Zahlungsfrist', Format::date($r['dueDate'])],
        ]);

        $this->pdf->text(self::LEFT, 262, MailTemplates::LEVEL_NAMES[$r['level']] . ' zu Rechnung ' . $i['number'], 16, true, self::INK);
        $this->y = 282;

        foreach (preg_split('/\n{2,}/', trim((string) $r['message'])) ?: [] as $block) {
            $this->paragraph($block, 10, self::INK);
        }

        $this->amounts();
        $this->paragraph('Bitte überweisen Sie den Betrag unter Angabe der Rechnungsnummer ' . $i['number'] . ' auf das folgende Konto:', 10, self::INK);
        $this->bankLines();
        $this->y += 10;
        $this->paragraph("Freundliche Grüße\n" . $this->co['name'], 10, self::INK);
        $this->footers();
        return $this->pdf->output();
    }

    private function amounts(): void
    {
        $p = $this->pdf;
        $c = (string) $this->invoice['currency'];
        $t = $this->invoice['totals'];
        $fee = (float) $this->reminder['fee'];

        $rows = [['Rechnungsbetrag ' . $this->invoice['number'], $this->money($t['total'], $c), false]];
        if ($t['paid'] > 0) {
            $rows[] = ['Bereits bezahlt', '- ' . $this->money($t['paid'], $c), false];
        }
        $rows[] = ['Offener Betrag', $this->money($t['balance'], $c), false];
        if ($fee > 0) {
            $rows[] = ['Mahngebühr', $this->money($fee, $c), false];
        }
        $rows[] = ['Zu zahlen bis ' . Format::date($this->reminder['dueDate']), $this->money($t['balance'] + $fee, $c), true];

        $this->ensureSpace(count($rows) * 18 + 20);
        $p->rect(self::LEFT, $this->y, self::RIGHT - self::LEFT, count($rows) * 18 + 8, self::LIGHT);
        $this->y += 6;
        foreach ($rows as [$label, $value, $bold]) {
            $p->text(self::LEFT + 10, $this->y + 12, $label, $bold ? 10.5 : 9.5, $bold, $bold ? self::INK : self::GREY);
            $p->textRight(self::RIGHT - 10, $this->y + 12, $value, $bold ? 10.5 : 9.5, $bold, self::INK);
            $this->y += 18;
        }
        $this->y += 18;
    }
}
