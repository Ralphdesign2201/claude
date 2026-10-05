<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\MailException;
use App\Mail\Mailer;
use App\Support\Activity;
use App\Support\Dates;
use App\Support\Db;
use App\Support\Format;
use DateTimeImmutable;
use DateTimeZone;

/** Wiederkehrende Rechnungen (Domain, Hosting, Wartung, Homepage-Miete …): Zeitplan und automatische Erzeugung. */
final class RecurringService
{
    public const MONTHS = ['MONTHLY' => 1, 'QUARTERLY' => 3, 'HALF_YEARLY' => 6, 'YEARLY' => 12];
    private const MONTH_NAMES = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    private const MAX_CATCH_UP = 12;

    /** Start + n Monate; der Tag bleibt erhalten, soweit der Zielmonat ihn hat (31.01. + 1 Monat = 28.02., danach wieder 31.03.). */
    public static function addMonths(DateTimeImmutable $date, int $months): DateTimeImmutable
    {
        $first = $date->setDate((int) $date->format('Y'), (int) $date->format('n'), 1)->modify("+$months months");
        $day = min((int) $date->format('j'), (int) $first->format('t'));
        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), $day);
    }

    /** Termin der n-ten Abrechnung (0 = Start), immer ab dem Startdatum gerechnet, damit nichts abdriftet. */
    public static function scheduleDate(string $startIso, string $unit, int $occurrence): DateTimeImmutable
    {
        $start = (new DateTimeImmutable($startIso))->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
        return self::addMonths($start, self::MONTHS[$unit] * $occurrence);
    }

    public static function iso(DateTimeImmutable $date): string
    {
        return $date->format(Dates::FORMAT);
    }

    /** Ersetzt {zeitraum}, {von}, {bis}, {monat} und {jahr} in Positionsbeschreibungen. */
    public static function fill(string $text, DateTimeImmutable $from, DateTimeImmutable $to): string
    {
        $fmt = static fn (DateTimeImmutable $d) => $d->format('d.m.Y');
        return strtr($text, [
            '{zeitraum}' => $fmt($from) . ' – ' . $fmt($to),
            '{von}' => $fmt($from),
            '{bis}' => $fmt($to),
            '{monat}' => self::MONTH_NAMES[(int) $from->format('n')],
            '{jahr}' => $from->format('Y'),
        ]);
    }

    /** @return array<string,mixed> Abo mit Positionen (Rohdaten) */
    public static function load(string $id): array
    {
        $rec = Db::require('Recurring', $id, 'Abo nicht gefunden');
        $rec['items'] = Db::all('SELECT * FROM "RecurringItem" WHERE "recurringId" = ? ORDER BY "position" ASC', [$id]);
        return $rec;
    }

    /**
     * Erzeugt die fällige Rechnung eines Abos und schaltet den Zeitplan weiter.
     * Mit $send wird die Rechnung (falls das Abo „automatisch senden“ hat und E-Mail eingerichtet ist) direkt versendet,
     * sonst bleibt sie ein Entwurf.
     *
     * @return array{recurringId:string,title:string,invoiceId:string,number:string,sent:bool,error:?string}
     */
    public static function runOne(string $recurringId, ?string $userId, bool $send): array
    {
        $result = Db::transaction(static function () use ($recurringId, $userId) {
            $rec = self::load($recurringId);
            $from = self::scheduleDate($rec['startDate'], $rec['intervalUnit'], (int) $rec['occurrence']);
            $next = self::scheduleDate($rec['startDate'], $rec['intervalUnit'], (int) $rec['occurrence'] + 1);
            $to = $next->modify('-1 day');

            $items = array_map(static fn ($i) => [
                'description' => self::fill($i['description'], $from, $to),
                'quantity' => $i['quantity'],
                'unitPrice' => $i['unitPrice'],
            ], $rec['items']);

            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $invoiceId = InvoiceService::create([
                'clientId' => $rec['clientId'],
                'projectId' => $rec['projectId'],
                'status' => 'DRAFT',
                'issueDate' => $now->format(Dates::FORMAT),
                'dueDate' => $now->modify('+' . (int) $rec['paymentDays'] . ' days')->format(Dates::FORMAT),
                'taxRate' => $rec['taxRate'],
                'discount' => $rec['discount'],
                'notes' => $rec['notes'],
                'currency' => $rec['currency'],
                'recurringId' => $rec['id'],
            ], $items);

            $finished = $rec['endDate'] !== null && $next > new DateTimeImmutable($rec['endDate']);
            Db::update('Recurring', $rec['id'], [
                'occurrence' => (int) $rec['occurrence'] + 1,
                'nextRunDate' => self::iso($next),
                'lastRunAt' => Dates::now(),
                'active' => $finished ? 0 : (int) $rec['active'],
            ]);

            $invoice = Db::find('Invoice', $invoiceId);
            Activity::log('RECURRING_RUN', "Rechnung {$invoice['number']} aus Abo „{$rec['title']}“ erzeugt", $rec['clientId'], $rec['projectId'], $userId);
            return ['rec' => $rec, 'invoiceId' => $invoiceId, 'number' => $invoice['number']];
        });

        $rec = $result['rec'];
        $sent = false;
        $error = null;
        if ($send && $rec['autoSend']) {
            try {
                $invoice = InvoiceService::detail($result['invoiceId']);
                $to = MailTemplates::recipient($invoice['client']);
                if (!Mailer::configured()) {
                    throw new MailException('E-Mail-Versand ist nicht eingerichtet');
                }
                if ($to === '') {
                    throw new MailException('Beim Kunden ist keine E-Mail-Adresse hinterlegt');
                }
                $draft = MailTemplates::invoice($invoice);
                InvoiceService::sendEmail($result['invoiceId'], $to, $draft['subject'], $draft['message'], $userId);
                $sent = true;
            } catch (MailException $e) {
                $error = $e->getMessage();
            }
        }

        return [
            'recurringId' => $rec['id'],
            'title' => $rec['title'],
            'invoiceId' => $result['invoiceId'],
            'number' => $result['number'],
            'sent' => $sent,
            'error' => $error,
        ];
    }

    /**
     * Erzeugt alle fälligen Abo-Rechnungen. Versäumte Zeiträume (z. B. Cron war aus) werden als Entwürfe nachgeholt;
     * nur wenn genau ein Zeitraum fällig ist, wird bei „automatisch senden“ direkt per E-Mail verschickt.
     *
     * @return list<array{recurringId:string,title:string,invoiceId:string,number:string,sent:bool,error:?string}>
     */
    public static function runDue(?string $userId = null): array
    {
        $endOfToday = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTime(23, 59, 59)->format(Dates::FORMAT);
        $results = [];

        foreach (Db::all('SELECT "id" FROM "Recurring" WHERE "active" = 1 AND "nextRunDate" <= ? ORDER BY "nextRunDate" ASC', [$endOfToday]) as $row) {
            $dueCount = self::dueCount($row['id'], $endOfToday);
            for ($i = 0; $i < min($dueCount, self::MAX_CATCH_UP); $i++) {
                $results[] = self::runOne($row['id'], $userId, $dueCount === 1);
            }
        }
        return $results;
    }

    private static function dueCount(string $id, string $until): int
    {
        $rec = self::load($id);
        $count = 0;
        $occurrence = (int) $rec['occurrence'];
        while ($count < self::MAX_CATCH_UP) {
            $date = self::scheduleDate($rec['startDate'], $rec['intervalUnit'], $occurrence + $count);
            if (self::iso($date) > $until || ($rec['endDate'] !== null && $date > new DateTimeImmutable($rec['endDate']))) {
                break;
            }
            $count++;
        }
        return $count;
    }
}
