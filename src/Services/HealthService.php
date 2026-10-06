<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\Mailer;
use App\Support\Db;
use App\Support\Env;
use App\Support\Product;

/**
 * Betriebs-Check für das Dashboard: sammelt alles, was fehlt oder bald Probleme macht.
 * red = muss behoben werden (rechtlich nötig oder Funktion gestört), yellow = empfohlen.
 */
final class HealthService
{
    private static function cronFile(): string
    {
        return APP_ROOT . '/database/cron-state.json';
    }

    /** Vom Cron-Lauf aufgerufen: merkt sich, dass der automatische Betrieb läuft. */
    public static function touchCron(): void
    {
        @file_put_contents(self::cronFile(), json_encode(['at' => time()]));
    }

    private static function cronAge(): ?int
    {
        $d = is_file(self::cronFile()) ? json_decode((string) @file_get_contents(self::cronFile()), true) : null;
        return is_array($d) && isset($d['at']) ? time() - (int) $d['at'] : null;
    }

    /** @return array{items:list<array<string,string>>,red:int,yellow:int} */
    public static function check(): array
    {
        $items = [];
        $add = static function (string $level, string $title, string $text, string $view = '', string $tab = '') use (&$items): void {
            $items[] = ['level' => $level, 'title' => $title, 'text' => $text, 'view' => $view, 'tab' => $tab];
        };

        // Rechtstexte
        foreach (LegalService::TYPES as $type) {
            $doc = LegalService::describe($type);
            $title = LegalService::TITLES[$type];
            $red = array_values(array_filter($doc['missing'], static fn ($m) => $m['level'] === 'red'));
            $yellow = array_values(array_filter($doc['missing'], static fn ($m) => $m['level'] === 'yellow'));
            $names = static fn (array $l): string => implode(', ', array_map(static fn ($m) => $m['label'], array_slice($l, 0, 4))) . (count($l) > 4 ? ' u. a.' : '');
            if ($type !== 'AGB' && !$doc['published']) {
                $add('red', "$title ist nicht veröffentlicht", $type === 'IMPRESSUM' ? 'Ein Impressum ist für geschäftsmäßige Webseiten Pflicht (§ 5 DDG).' : 'Sobald personenbezogene Daten verarbeitet werden, ist eine Datenschutzerklärung Pflicht (Art. 13 DSGVO).', 'legal', $type);
            } elseif ($type === 'AGB' && !$doc['published']) {
                $add('yellow', 'AGB sind nicht veröffentlicht', 'AGB sind nicht vorgeschrieben, aber empfohlen – sie regeln Zahlung, Abnahme, Haftung und Nutzungsrechte.', 'legal', $type);
            }
            if ($red !== [] && ($doc['published'] || $type !== 'AGB')) {
                $add('red', "$title: Pflichtangaben fehlen", $names($red), 'legal', $type);
            }
            if ($yellow !== []) {
                $add('yellow', "$title: empfohlene Angaben fehlen", $names($yellow), 'legal', $type);
            }
            if ($doc['override'] !== null && $doc['published']) {
                $add('yellow', "$title: eigener Text aktiv", 'Der Text wird nicht mehr automatisch aus deinen Angaben erzeugt – Änderungen an Einstellungen wirken sich dort nicht aus.', 'legal', $type);
            }
        }

        // Firmendaten und Zahlungsangaben (stehen auf jeder Rechnung)
        $co = \App\Pdf\DocumentPdf::company();
        if (in_array($co['name'], ['Ihr Firmenname', 'Ihr Studio', ''], true)) {
            $add('red', 'Firmenname nicht gesetzt', 'Rechnungen und E-Mails tragen sonst einen Platzhalter.', 'settings');
        }
        if ($co['iban'] === '') {
            $add('yellow', 'IBAN fehlt', 'Ohne Bankverbindung kann der Kunde die Rechnung nicht bezahlen.', 'settings');
        }
        if (trim((string) ($co['vat'] ?? '')) === '' && trim((string) (Env::get('COMPANY_TAX_NUMBER', '') ?? '')) === '') {
            $add('red', 'Steuernummer oder USt-IdNr. fehlt', 'Eine Rechnung muss nach § 14 UStG eine davon enthalten.', 'settings');
        }

        // Technik
        if (!Mailer::configured()) {
            $add('yellow', 'E-Mail-Versand nicht eingerichtet', 'Rechnungen, Registrierungsbestätigungen und Ticket-Benachrichtigungen können nicht verschickt werden.', 'settings');
        }
        $url = Env::get('APP_URL', '') ?? '';
        if ($url === '') {
            $add('yellow', 'Öffentliche Adresse fehlt', 'Links in E-Mails (Portal, Bestätigung) brauchen die Adresse der Installation.', 'settings');
        } elseif (!str_starts_with(strtolower($url), 'https://') && !preg_match('#^https?://(localhost|127\.|\[::1\])#i', $url)) {
            $add('red', 'Seite nicht über HTTPS', 'Anmeldedaten würden unverschlüsselt übertragen. Bitte HTTPS aktivieren und die Adresse anpassen.', 'settings');
        }
        $cron = self::cronAge();
        if ($cron === null) {
            $add('yellow', 'Automatischer Betrieb (Cron) noch nie gelaufen', 'Abo-Rechnungen, Ticket-Aufräumen und Backups laufen nur, wenn bin/cron.php täglich aufgerufen wird.', 'settings');
        } elseif ($cron > 3 * 86400) {
            $add('red', 'Cron läuft nicht mehr', 'Der letzte Lauf ist ' . floor($cron / 86400) . ' Tage her – Abrechnung und Backups sind ausgesetzt.', 'settings');
        } elseif ($cron > 36 * 3600) {
            $add('yellow', 'Cron war länger nicht aktiv', 'Der letzte Lauf ist über 36 Stunden her.', 'settings');
        }
        $last = BackupService::list()[0]['createdAt'] ?? null;
        if ($last === null) {
            $add('yellow', 'Noch kein Backup', 'Bitte unter Einstellungen → Backups eine erste Sicherung anlegen.', 'backups');
        } elseif (strtotime($last) < time() - 7 * 86400) {
            $add('yellow', 'Letztes Backup ist älter als 7 Tage', 'Sicherungen vom ' . substr($last, 0, 10) . '.', 'backups');
        } elseif (!BackupService::isEncrypted()) {
            $add('yellow', 'Backups sind nicht verschlüsselt', 'Ein Backup-Passwort schützt Kundendaten, falls die Datei verloren geht.', 'settings');
        }

        // Updates
        $state = UpdateService::lastState();
        if (is_array($state['latest'] ?? null) && ($state['latest']['version'] ?? '') !== '') {
            $add('yellow', 'Update verfügbar: Version ' . $state['latest']['version'], 'Installiert ist ' . Product::version() . '. Du findest es unter Einstellungen → Version & Updates.', 'settings');
        }

        // Support
        $open = (int) Db::value('SELECT COUNT(*) FROM "Ticket" WHERE "status" = \'OPEN\'');
        if ($open > 0) {
            $add('yellow', $open . ' unbeantwortete Support-' . ($open === 1 ? 'Anfrage' : 'Anfragen'), 'Neue Tickets warten auf eine erste Antwort.', 'tickets');
        }

        $red = count(array_filter($items, static fn ($i) => $i['level'] === 'red'));
        usort($items, static fn ($a, $b) => ($a['level'] === 'red' ? 0 : 1) <=> ($b['level'] === 'red' ? 0 : 1));

        return ['items' => $items, 'red' => $red, 'yellow' => count($items) - $red];
    }
}
