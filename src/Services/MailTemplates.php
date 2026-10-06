<?php

declare(strict_types=1);

namespace App\Services;

use App\Pdf\DocumentPdf;
use App\Support\Db;
use App\Support\Format;

/** Standardtexte für Rechnung, Angebot und Mahnungen. Sie sind nur Vorschläge und vor dem Versand editierbar. */
final class MailTemplates
{
    public const LEVEL_NAMES = [1 => 'Zahlungserinnerung', 2 => '1. Mahnung', 3 => 'Letzte Mahnung'];

    /** @param array<string,mixed> $client */
    public static function greeting(array $client): string
    {
        $contact = Db::one('SELECT "name" FROM "Contact" WHERE "clientId" = ? ORDER BY "isPrimary" DESC, "createdAt" ASC LIMIT 1', [$client['id']]);
        return 'Guten Tag ' . ($contact['name'] ?? $client['name']) . ',';
    }

    public static function signature(): string
    {
        return "Freundliche Grüße\n" . DocumentPdf::company()['name'];
    }

    /** Empfängeradresse: E-Mail des Kunden, sonst die des Hauptansprechpartners. */
    public static function recipient(array $client): string
    {
        if (!empty($client['email'])) {
            return (string) $client['email'];
        }
        $contact = Db::one('SELECT "email" FROM "Contact" WHERE "clientId" = ? AND "email" IS NOT NULL AND "email" != \'\' ORDER BY "isPrimary" DESC, "createdAt" ASC LIMIT 1', [$client['id']]);
        return (string) ($contact['email'] ?? '');
    }

    /** @return array{subject:string,message:string} */
    public static function invoice(array $inv): array
    {
        $company = DocumentPdf::company()['name'];
        $due = $inv['dueDate'] ? ', zahlbar bis zum ' . Format::date($inv['dueDate']) : '';
        return [
            'subject' => "Rechnung {$inv['number']} von $company",
            'message' => self::greeting($inv['client']) . "\n\nvielen Dank für Ihren Auftrag. Im Anhang finden Sie die Rechnung {$inv['number']} über "
                . Format::money($inv['totals']['total'], $inv['currency']) . "$due.\n\n"
                . "Bitte überweisen Sie den Betrag unter Angabe der Rechnungsnummer auf das in der Rechnung genannte Konto.\n\nBei Rückfragen melden Sie sich gerne.",
        ];
    }

    /** @return array{subject:string,message:string} */
    public static function portal(array $client, string $link, ?string $expiresAt): array
    {
        $company = DocumentPdf::company()['name'];
        $valid = $expiresAt ? ' Der Link ist bis zum ' . Format::date($expiresAt) . ' gültig.' : '';
        return [
            'subject' => "Ihr Kundenportal bei $company",
            'message' => self::greeting($client) . "\n\nin Ihrem persönlichen Kundenportal finden Sie alle Rechnungen und Angebote und können sie jederzeit als PDF herunterladen:\n\n$link\n\n"
                . "Der Link ist nur für Sie bestimmt – bitte geben Sie ihn nicht weiter.$valid",
        ];
    }

    /** E-Mail-Bestätigung für ein neues Konto. @return array{subject:string,message:string} */
    public static function accountVerify(string $name, string $link): array
    {
        $company = DocumentPdf::company()['name'];
        return [
            'subject' => "Bitte bestätigen Sie Ihre E-Mail-Adresse – $company",
            'message' => "Guten Tag $name,\n\nvielen Dank für Ihre Registrierung im Kundenportal von $company. Bitte bestätigen Sie Ihre E-Mail-Adresse und legen Sie Ihr Passwort fest:\n\n$link\n\n"
                . "Der Link ist 48 Stunden gültig. Wenn Sie sich nicht registriert haben, können Sie diese E-Mail ignorieren.",
        ];
    }

    /** Hinweis, wenn sich jemand mit einer bereits registrierten Adresse anmelden will. @return array{subject:string,message:string} */
    public static function accountExists(string $name, string $portalUrl): array
    {
        $company = DocumentPdf::company()['name'];
        return [
            'subject' => "Ihr Kundenportal bei $company",
            'message' => "Guten Tag $name,\n\nfür diese E-Mail-Adresse besteht bereits ein Konto im Kundenportal. Sie können sich hier anmelden:\n\n$portalUrl\n\n"
                . "Falls Sie Ihr Passwort vergessen haben, nutzen Sie dort „Passwort vergessen“. Wenn Sie sich nicht registriert haben, können Sie diese E-Mail ignorieren.",
        ];
    }

    /** @return array{subject:string,message:string} */
    public static function accountReset(string $name, string $link): array
    {
        $company = DocumentPdf::company()['name'];
        return [
            'subject' => "Passwort zurücksetzen – $company",
            'message' => "Guten Tag $name,\n\nüber diesen Link können Sie ein neues Passwort für Ihr Kundenportal festlegen:\n\n$link\n\n"
                . "Der Link ist zwei Stunden gültig. Wenn Sie das nicht angefordert haben, können Sie diese E-Mail ignorieren – Ihr Passwort bleibt dann unverändert.",
        ];
    }

    /** Benachrichtigung an die Firma über ein neues Kundenkonto. @return array{subject:string,message:string} */
    public static function accountNotify(string $name, string $company, string $email, bool $existing): array
    {
        return [
            'subject' => 'Neue Registrierung im Kundenportal: ' . ($company !== '' ? $company : $name),
            'message' => "$name" . ($company !== '' ? " ($company)" : '') . " hat sich mit $email im Kundenportal registriert.\n\n"
                . ($existing ? 'Die Adresse gehörte bereits zu einem bestehenden Kunden, das Konto wurde mit ihm verknüpft.' : 'Es wurde ein neuer Kunde (Status „Lead“) angelegt.'),
        ];
    }

    /** @param array<string,mixed> $o Bestellung (Schnappschuss) */
    private static function orderLine(array $o): string
    {
        $qty = \App\Support\Format::qty((float) $o['quantity']) . ($o['unit'] ? ' ' . $o['unit'] : '×');
        return "$qty {$o['productName']}";
    }

    /** Eingangsbestätigung an den Kunden. @return array{subject:string,message:string} */
    public static function orderReceived(array $client, array $o): array
    {
        return [
            'subject' => "Ihre Bestellung {$o['number']} ist eingegangen",
            'message' => self::greeting($client) . "\n\nvielen Dank, wir haben Ihre Bestellung {$o['number']} erhalten:\n\n" . self::orderLine($o)
                . "\n\nWir prüfen sie und melden uns in Kürze bei Ihnen. Den Stand sehen Sie jederzeit in Ihrem Kundenportal.",
        ];
    }

    /** Benachrichtigung an die Firma über eine neue Bestellung. @return array{subject:string,message:string} */
    public static function orderNotify(array $client, array $o): array
    {
        $who = $client['company'] ?: $client['name'];
        return [
            'subject' => "Neue Bestellung {$o['number']} von $who",
            'message' => "$who hat bestellt (Bestellung {$o['number']}):\n\n" . self::orderLine($o)
                . ($o['note'] ? "\n\nAnmerkung des Kunden:\n{$o['note']}" : '') . "\n\nBitte im System unter „Bestellungen“ prüfen und annehmen oder ablehnen.",
        ];
    }

    /** Entscheidung (angenommen/abgelehnt) an den Kunden. @return array{subject:string,message:string} */
    public static function orderDecision(array $client, array $o): array
    {
        if ($o['status'] === 'ACCEPTED') {
            return [
                'subject' => "Ihre Bestellung {$o['number']} wurde bestätigt",
                'message' => self::greeting($client) . "\n\nwir haben Ihre Bestellung {$o['number']} bestätigt:\n\n" . self::orderLine($o)
                    . "\n\nDie Rechnung bzw. alle weiteren Informationen erhalten Sie von uns separat. Den Stand sehen Sie in Ihrem Kundenportal.",
            ];
        }
        return [
            'subject' => "Zu Ihrer Bestellung {$o['number']}",
            'message' => self::greeting($client) . "\n\nleider können wir Ihre Bestellung {$o['number']} so nicht ausführen:\n\n" . self::orderLine($o)
                . ($o['rejectReason'] ? "\n\nGrund: {$o['rejectReason']}" : '') . "\n\nBei Fragen melden Sie sich gerne.",
        ];
    }

    /** @return array{subject:string,message:string} */
    public static function quote(array $q): array
    {
        $company = DocumentPdf::company()['name'];
        $valid = $q['validUntil'] ? ' Es ist gültig bis zum ' . Format::date($q['validUntil']) . '.' : '';
        return [
            'subject' => "Angebot {$q['number']} von $company",
            'message' => self::greeting($q['client']) . "\n\nvielen Dank für Ihr Interesse. Im Anhang finden Sie unser Angebot {$q['number']} über "
                . Format::money($q['totals']['total'], $q['currency']) . ".$valid\n\n"
                . 'Wenn Sie Fragen haben oder Anpassungen wünschen, melden Sie sich gerne. Wir freuen uns auf Ihre Rückmeldung.',
        ];
    }

    /** @return array{subject:string,message:string} */
    public static function reminder(array $inv, int $level, float $fee, string $newDueIso): array
    {
        $number = $inv['number'];
        $issue = Format::date($inv['issueDate']);
        $due = Format::date($inv['dueDate']);
        $newDue = Format::date($newDueIso);
        $balance = Format::money($inv['totals']['balance'], $inv['currency']);
        $total = Format::money($inv['totals']['balance'] + $fee, $inv['currency']);
        $feeText = $fee > 0 ? ' (offener Betrag ' . $balance . ' zuzüglich Mahngebühr ' . Format::money($fee, $inv['currency']) . ')' : '';
        $greeting = self::greeting($inv['client']);

        $body = match ($level) {
            1 => "bei der Durchsicht unserer Buchhaltung ist uns aufgefallen, dass die Rechnung $number vom $issue über $balance noch nicht beglichen ist. Sie war am $due fällig.\n\n"
                . "Sicherlich handelt es sich um ein Versehen. Wir bitten Sie, den offenen Betrag bis zum $newDue auf das unten genannte Konto zu überweisen.\n\n"
                . 'Sollte sich Ihre Zahlung mit diesem Schreiben überschnitten haben, betrachten Sie diese Erinnerung bitte als gegenstandslos.',
            2 => "trotz unserer Zahlungserinnerung konnten wir bis heute keinen Zahlungseingang für die Rechnung $number vom $issue feststellen. Der Betrag war am $due fällig.\n\n"
                . "Wir bitten Sie, den Betrag von $total$feeText bis zum $newDue zu überweisen.",
            default => "leider haben Sie auf unsere bisherigen Schreiben nicht reagiert. Die Rechnung $number vom $issue ist weiterhin offen und seit dem $due fällig.\n\n"
                . "Wir fordern Sie letztmalig auf, den Gesamtbetrag von $total$feeText bis zum $newDue zu überweisen. "
                . 'Nach Ablauf dieser Frist behalten wir uns vor, ohne weitere Ankündigung rechtliche Schritte einzuleiten und Verzugskosten geltend zu machen.',
        };

        return [
            'subject' => self::LEVEL_NAMES[$level] . " zu Rechnung $number",
            'message' => "$greeting\n\n$body",
        ];
    }
}
