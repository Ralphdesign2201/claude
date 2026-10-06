<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\ApiError;
use App\Support\Dates;
use App\Support\Db;
use App\Support\Env;

/**
 * Rechtstexte: Impressum, Datenschutzerklärung und AGB werden aus Angaben erzeugt, lassen sich ergänzen oder komplett
 * von Hand überschreiben und werden als öffentliche Seiten ausgeliefert (/impressum, /datenschutz, /agb).
 *
 * Wichtig: Das sind sorgfältig formulierte Vorlagen nach deutschem Recht (DDG, MStV, DSGVO, BGB) – keine Rechtsberatung.
 * Die Verantwortung für Richtigkeit und Vollständigkeit bleibt beim Betreiber; bei Unsicherheit bitte anwaltlich prüfen lassen.
 */
final class LegalService
{
    public const TYPES = ['IMPRESSUM', 'DATENSCHUTZ', 'AGB'];
    public const PATHS = ['IMPRESSUM' => '/impressum', 'DATENSCHUTZ' => '/datenschutz', 'AGB' => '/agb'];
    public const TITLES = ['IMPRESSUM' => 'Impressum', 'DATENSCHUTZ' => 'Datenschutzerklärung', 'AGB' => 'Allgemeine Geschäftsbedingungen'];

    /* ---------- Feldbeschreibung für die Oberfläche ---------- */

    /** @return array<string,list<array<string,mixed>>> */
    public static function schema(): array
    {
        $f = static fn (string $key, string $label, string $type = 'text', array $x = []): array => ['key' => $key, 'label' => $label, 'type' => $type] + $x;
        $yn = [['0', 'Nein'], ['1', 'Ja']];
        return [
            'IMPRESSUM' => [
                $f('form', 'Rechtsform', 'select', ['options' => [['einzel', 'Einzelunternehmen / Freiberufler'], ['gbr', 'GbR / Partnerschaft'], ['ug', 'UG (haftungsbeschränkt)'], ['gmbh', 'GmbH'], ['ag', 'AG / SE'], ['ek', 'e. K. (eingetragener Kaufmann)'], ['other', 'Sonstige']]]),
                $f('name', 'Name des Unternehmens', 'text', ['help' => 'Bei Einzelunternehmen der volle Name des Inhabers, ggf. mit Geschäftsbezeichnung.']),
                $f('owner', 'Inhaber bzw. Vertretungsberechtigte(r)', 'text', ['help' => 'Bei GmbH/UG: Geschäftsführer mit vollem Namen.']),
                $f('street', 'Straße und Hausnummer'),
                $f('zip', 'PLZ'),
                $f('city', 'Ort'),
                $f('country', 'Land', 'text', ['default' => 'Deutschland']),
                $f('email', 'E-Mail', 'email'),
                $f('phone', 'Telefon', 'text', ['help' => 'Empfohlen als zweiter schneller Kontaktweg.']),
                $f('website', 'Webseite', 'text'),
                $f('registerCourt', 'Registergericht', 'text', ['help' => 'Pflicht bei Eintragung im Handelsregister (e. K., UG, GmbH, AG).']),
                $f('registerNumber', 'Registernummer', 'text', ['help' => 'z. B. HRB 12345']),
                $f('capital', 'Stammkapital (nur wenn angegeben werden soll)', 'text'),
                $f('vatId', 'USt-IdNr.', 'text', ['help' => 'Nur eintragen, wenn vorhanden. Die Steuernummer gehört nicht ins Impressum.']),
                $f('wId', 'Wirtschafts-Identifikationsnummer', 'text', ['help' => 'Optional, sobald vergeben.']),
                $f('contentResponsible', 'Verantwortlich für redaktionelle Inhalte (§ 18 Abs. 2 MStV)', 'text', ['help' => 'Nur bei journalistisch-redaktionellen Inhalten (z. B. Blog/News). Name und Anschrift.']),
                $f('profession', 'Berufsrechtliche Angaben', 'lines', ['help' => 'Nur bei reglementierten Berufen: Berufsbezeichnung, zuständige Kammer, berufsrechtliche Regelungen.']),
                $f('odr', 'Verbraucherschlichtung', 'select', ['options' => [['unwilling', 'Wir nehmen nicht teil und sind nicht bereit'], ['willing', 'Wir sind zur Teilnahme bereit'], ['none', 'Keine Angabe (z. B. nur Geschäftskunden)']], 'default' => 'unwilling', 'help' => 'Pflicht für Unternehmen mit Verbrauchern ab 11 Beschäftigten (§ 36 VSBG); sonst freiwillig.']),
            ],
            'DATENSCHUTZ' => [
                $f('dpoName', 'Datenschutzbeauftragte(r)', 'text', ['help' => 'Nur wenn bestellt (Pflicht meist ab 20 Personen, die ständig Daten verarbeiten).']),
                $f('dpoEmail', 'E-Mail der/des Datenschutzbeauftragten', 'email'),
                $f('hostingProvider', 'Hosting-Anbieter (Name und Anschrift)', 'text', ['help' => 'Wo die Seite und die Daten liegen, z. B. „Hostinger International Ltd., Kaunas, Litauen“. Pflichtangabe.']),
                $f('hostingAvv', 'Auftragsverarbeitungsvertrag mit dem Hoster abgeschlossen', 'select', ['options' => $yn, 'default' => '1']),
                $f('logDays', 'Server-Logfiles werden gelöscht nach (Tagen)', 'int', ['default' => '14', 'min' => 1, 'max' => 365]),
                $f('portal', 'Kundenportal mit Benutzerkonto', 'select', ['options' => $yn, 'default' => '1']),
                $f('registration', 'Kunden können sich selbst registrieren', 'select', ['options' => $yn]),
                $f('tickets', 'Support-Tickets mit Anhängen', 'select', ['options' => $yn, 'default' => '1']),
                $f('shop', 'Produkt-Bestellungen (Shop)', 'select', ['options' => $yn]),
                $f('licenses', 'Software-Lizenzen und Updates', 'select', ['options' => $yn]),
                $f('mail', 'E-Mail-Versand (Rechnungen, Benachrichtigungen)', 'select', ['options' => $yn]),
                $f('mailProvider', 'E-Mail-Anbieter (SMTP)', 'text', ['help' => 'Name des Anbieters, der die Mails versendet, z. B. „IONOS SE“.']),
                $f('contactForm', 'Kontaktformular auf der Webseite', 'select', ['options' => $yn]),
                $f('newsletter', 'Newsletter', 'select', ['options' => $yn]),
                $f('newsletterProvider', 'Newsletter-Dienstleister', 'text'),
                $f('analytics', 'Webanalyse', 'select', ['options' => [['none', 'Keine'], ['self', 'Selbst gehostet (z. B. Matomo, ohne Cookies)'], ['other', 'Externer Dienst (Einwilligung nötig)']], 'default' => 'none']),
                $f('analyticsName', 'Name der Analyse-Software', 'text'),
                $f('fonts', 'Schriftarten', 'select', ['options' => [['local', 'Lokal eingebunden'], ['google', 'Von Google Fonts geladen']], 'default' => 'local']),
                $f('maps', 'Karten (z. B. Google Maps / OpenStreetMap) eingebunden', 'select', ['options' => $yn]),
                $f('video', 'Videos (YouTube/Vimeo) eingebunden', 'select', ['options' => $yn]),
                $f('paypal', 'Zahlung per PayPal', 'select', ['options' => $yn]),
                $f('stripe', 'Zahlung per Kreditkarte/Stripe', 'select', ['options' => $yn]),
                $f('thirdCountry', 'Dienstleister außerhalb der EU (z. B. USA)', 'select', ['options' => $yn]),
                $f('authority', 'Zuständige Aufsichtsbehörde', 'text', ['help' => 'Landesdatenschutzbehörde deines Bundeslandes, z. B. „Der Bayerische Landesbeauftragte für den Datenschutz, Wagmüllerstraße 18, 80538 München“.']),
            ],
            'AGB' => [
                $f('audience', 'Zielgruppe', 'select', ['options' => [['both', 'Unternehmer und Verbraucher'], ['b2b', 'Nur Unternehmer (B2B)']], 'default' => 'both']),
                $f('paymentDays', 'Zahlungsziel (Tage)', 'int', ['default' => '14', 'min' => 0, 'max' => 90]),
                $f('deposit', 'Anzahlung bei Projektbeginn (%)', 'int', ['default' => '30', 'min' => 0, 'max' => 100]),
                $f('revisions', 'Enthaltene Korrekturschleifen', 'int', ['default' => '2', 'min' => 0, 'max' => 20]),
                $f('approvalDays', 'Abnahmefrist (Tage)', 'int', ['default' => '14', 'min' => 1, 'max' => 60]),
                $f('warrantyMonths', 'Gewährleistung gegenüber Unternehmern (Monate)', 'int', ['default' => '12', 'min' => 1, 'max' => 60, 'help' => 'Gegenüber Verbrauchern gelten gesetzlich mindestens 24 Monate.']),
                $f('jurisdiction', 'Gerichtsstand (Ort)', 'text', ['help' => 'Nur gegenüber Kaufleuten wirksam, meist der Firmensitz.']),
                $f('reference', 'Darf der Kunde als Referenz genannt werden', 'select', ['options' => $yn, 'default' => '1']),
                $f('hosting', 'Hosting und Wartung als Leistung anbieten', 'select', ['options' => $yn]),
                $f('recurring', 'Laufende Leistungen mit Mindestlaufzeit (Abo)', 'select', ['options' => $yn]),
                $f('noticeMonths', 'Kündigungsfrist laufender Leistungen (Monate)', 'int', ['default' => '1', 'min' => 0, 'max' => 12]),
                $f('licenses', 'Software-Lizenzen und Updates verkaufen', 'select', ['options' => $yn]),
                $f('withdrawal', 'Hinweis auf das Widerrufsrecht für Verbraucher aufnehmen', 'select', ['options' => $yn, 'default' => '1']),
            ],
        ];
    }

    /* ---------- Vorbelegung ---------- */

    /** @return array<string,string> */
    public static function defaults(string $type): array
    {
        $out = [];
        foreach (self::schema()[$type] as $f) {
            if (isset($f['default'])) {
                $out[$f['key']] = (string) $f['default'];
            }
        }
        $addr = array_values(array_filter(array_map('trim', explode('|', Env::get('COMPANY_ADDRESS', '') ?? ''))));
        $name = Env::get('COMPANY_NAME', '') ?? '';
        if ($name === 'Ihr Firmenname' || $name === 'Ihr Studio') {
            $name = '';
        }
        $zip = $city = '';
        foreach ($addr as $line) {
            if (preg_match('/^(\d{4,5})\s+(.+)$/u', $line, $m)) {
                [$zip, $city] = [$m[1], $m[2]];
            }
        }
        if ($type === 'IMPRESSUM') {
            $out += ['name' => $name, 'owner' => '', 'street' => $addr[0] ?? '', 'zip' => $zip, 'city' => $city, 'email' => Env::get('COMPANY_EMAIL', '') ?? '',
                'phone' => Env::get('COMPANY_PHONE', '') ?? '', 'website' => Env::get('COMPANY_WEBSITE', '') ?? '', 'vatId' => Env::get('COMPANY_VAT_ID', '') ?? '', 'form' => 'einzel'];
        } elseif ($type === 'DATENSCHUTZ') {
            $out['registration'] = (Env::get('PORTAL_REGISTRATION', 'open') ?? 'open') === 'open' ? '1' : '0';
            $out['mail'] = (Env::get('SMTP_HOST', '') ?? '') !== '' ? '1' : '0';
            $out['shop'] = (int) Db::value('SELECT COUNT(*) FROM "Product" WHERE "active" = 1') > 0 ? '1' : '0';
            $out['licenses'] = (int) Db::value('SELECT COUNT(*) FROM "License"') > 0 ? '1' : '0';
        } else {
            $out['jurisdiction'] = $city;
            $out['licenses'] = (int) Db::value('SELECT COUNT(*) FROM "License"') > 0 ? '1' : '0';
        }
        return $out;
    }

    /* ---------- Speicherung ---------- */

    /** @return array<string,mixed> */
    public static function load(string $type): array
    {
        self::assertType($type);
        $row = Db::one('SELECT * FROM "LegalDocument" WHERE "type" = ?', [$type]);
        $saved = $row !== null && is_string($row['data']) ? (json_decode($row['data'], true) ?: []) : [];
        $data = array_merge(self::defaults($type), array_map('strval', array_filter($saved, 'is_scalar')));
        return [
            'type' => $type, 'data' => $data, 'extra' => (string) ($row['extra'] ?? ''), 'override' => $row !== null && $row['override'] !== null && $row['override'] !== '' ? (string) $row['override'] : null,
            'published' => $row !== null && (int) $row['published'] === 1, 'version' => (int) ($row['version'] ?? 0), 'updatedAt' => $row['updatedAt'] ?? null, 'saved' => $row !== null,
        ];
    }

    /**
     * @param array<string,mixed> $input data (Felder), extra, override, published
     * @return array<string,mixed>
     */
    public static function save(string $type, array $input): array
    {
        self::assertType($type);
        $cur = self::load($type);
        $allowed = array_column(self::schema()[$type], null, 'key');
        $data = $cur['data'];
        foreach (is_array($input['data'] ?? null) ? $input['data'] : [] as $k => $v) {
            if (isset($allowed[$k]) && is_scalar($v)) {
                $data[$k] = mb_substr(trim((string) $v), 0, 1000);
            }
        }
        $extra = array_key_exists('extra', $input) ? mb_substr((string) $input['extra'], 0, 50000) : $cur['extra'];
        $override = array_key_exists('override', $input) ? ($input['override'] === null || trim((string) $input['override']) === '' ? null : mb_substr((string) $input['override'], 0, 100000)) : $cur['override'];
        $published = array_key_exists('published', $input) ? (int) (bool) $input['published'] : (int) $cur['published'];
        $row = ['data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'extra' => $extra, 'override' => $override, 'published' => $published];
        $existing = Db::one('SELECT "id", "version" FROM "LegalDocument" WHERE "type" = ?', [$type]);
        if ($existing === null) {
            Db::insert('LegalDocument', $row + ['type' => $type, 'version' => 1]);
        } else {
            Db::update('LegalDocument', $existing['id'], $row + ['version' => (int) $existing['version'] + 1]);
        }
        return self::describe($type);
    }

    /** @return array<string,mixed> Dokument samt fertigem Text, Hinweisen und HTML */
    public static function describe(string $type, ?array $draft = null): array
    {
        $doc = self::load($type);
        if ($draft !== null) {
            $allowed = array_column(self::schema()[$type], null, 'key');
            foreach (is_array($draft['data'] ?? null) ? $draft['data'] : [] as $k => $v) {
                if (isset($allowed[$k]) && is_scalar($v)) {
                    $doc['data'][$k] = trim((string) $v);
                }
            }
            if (array_key_exists('extra', $draft)) {
                $doc['extra'] = (string) $draft['extra'];
            }
            if (array_key_exists('override', $draft)) {
                $doc['override'] = $draft['override'] === null || trim((string) $draft['override']) === '' ? null : (string) $draft['override'];
            }
        }
        $generated = self::generate($type, $doc['data']);
        $markdown = $doc['override'] ?? self::withExtra($generated, $doc['extra']);
        return $doc + ['generated' => $generated, 'markdown' => $markdown, 'html' => self::html($markdown), 'missing' => self::missing($type, $doc['data']), 'path' => self::PATHS[$type], 'title' => self::TITLES[$type]];
    }

    /** Veröffentlichter Text für die öffentliche Seite, sonst null. @return array{title:string,html:string,updatedAt:?string}|null */
    public static function published(string $type): ?array
    {
        $doc = self::describe($type);
        if (!$doc['published']) {
            return null;
        }
        return ['title' => $doc['title'], 'html' => $doc['html'], 'updatedAt' => $doc['updatedAt']];
    }

    public static function isPublished(string $type): bool
    {
        return (int) Db::value('SELECT "published" FROM "LegalDocument" WHERE "type" = ?', [$type]) === 1;
    }

    private static function assertType(string $type): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw ApiError::notFound('Unbekannter Rechtstext');
        }
    }

    private static function withExtra(string $generated, string $extra): string
    {
        $extra = trim($extra);
        return $extra === '' ? $generated : rtrim($generated) . "\n\n" . $extra . "\n";
    }

    /* ---------- Prüfung auf fehlende Angaben ---------- */

    /** @param array<string,string> $d @return list<array{field:string,label:string,level:string}> level: red = rechtlich nötig, yellow = empfohlen */
    public static function missing(string $type, array $d): array
    {
        $labels = array_column(self::schema()[$type], 'label', 'key');
        $out = [];
        $need = static function (string $key, string $level) use (&$out, $d, $labels): void {
            if (trim($d[$key] ?? '') === '') {
                $out[] = ['field' => $key, 'label' => $labels[$key] ?? $key, 'level' => $level];
            }
        };
        if ($type === 'IMPRESSUM') {
            foreach (['name', 'owner', 'street', 'zip', 'city', 'email'] as $k) {
                $need($k, 'red');
            }
            $need('phone', 'yellow');
            if (in_array($d['form'] ?? 'einzel', ['ek', 'ug', 'gmbh', 'ag'], true)) {
                $need('registerCourt', 'red');
                $need('registerNumber', 'red');
            }
            $need('vatId', 'yellow');
        } elseif ($type === 'DATENSCHUTZ') {
            $need('hostingProvider', 'red');
            $imp = self::load('IMPRESSUM')['data'];
            foreach (['name', 'street', 'zip', 'city', 'email'] as $k) {
                if (trim($imp[$k] ?? '') === '') {
                    $out[] = ['field' => 'impressum', 'label' => 'Verantwortlicher: Angaben im Impressum fehlen (' . ($labels[$k] ?? $k) . ')', 'level' => 'red'];
                    break;
                }
            }
            if (($d['mail'] ?? '0') === '1') {
                $need('mailProvider', 'yellow');
            }
            if (($d['newsletter'] ?? '0') === '1') {
                $need('newsletterProvider', 'red');
            }
            if (($d['analytics'] ?? 'none') !== 'none') {
                $need('analyticsName', 'red');
            }
            $need('authority', 'yellow');
            if (trim($d['dpoName'] ?? '') !== '') {
                $need('dpoEmail', 'red');
            }
        } else {
            $imp = self::load('IMPRESSUM')['data'];
            if (trim($imp['name'] ?? '') === '') {
                $out[] = ['field' => 'impressum', 'label' => 'Anbieter: Firmenname fehlt (Impressum)', 'level' => 'red'];
            }
            $need('jurisdiction', 'yellow');
        }
        return $out;
    }

    /* ---------- Generatoren ---------- */

    /** @param array<string,string> $d */
    public static function generate(string $type, array $d): string
    {
        return match ($type) {
            'IMPRESSUM' => self::impressum($d),
            'DATENSCHUTZ' => self::datenschutz($d),
            default => self::agb($d),
        };
    }

    private static function v(array $d, string $k, string $fallback = '…'): string
    {
        $x = trim($d[$k] ?? '');
        return $x === '' ? "[$fallback]" : $x;
    }

    private static function on(array $d, string $k): bool
    {
        return ($d[$k] ?? '0') === '1';
    }

    private static function stand(): string
    {
        return 'Stand: ' . date('d.m.Y');
    }

    /** @param array<string,string> $d */
    private static function impressum(array $d): string
    {
        $form = $d['form'] ?? 'einzel';
        $juristic = in_array($form, ['ug', 'gmbh', 'ag'], true);
        $L = ["# Impressum", '', '## Angaben gemäß § 5 DDG', ''];
        $L[] = self::v($d, 'name', 'Firmenname') . '  ';
        $L[] = ($juristic ? 'Vertreten durch: ' : 'Inhaber: ') . self::v($d, 'owner', 'Name') . '  ';
        $L[] = self::v($d, 'street', 'Straße Nr.') . '  ';
        $L[] = self::v($d, 'zip', 'PLZ') . ' ' . self::v($d, 'city', 'Ort') . '  ';
        $L[] = trim($d['country'] ?? '') ?: 'Deutschland';
        $L[] = '';
        $L[] = '## Kontakt';
        $L[] = '';
        if (trim($d['phone'] ?? '') !== '') {
            $L[] = 'Telefon: ' . $d['phone'] . '  ';
        }
        $L[] = 'E-Mail: ' . self::v($d, 'email', 'E-Mail') . '  ';
        if (trim($d['website'] ?? '') !== '') {
            $L[] = 'Webseite: ' . $d['website'];
        }
        if (in_array($form, ['ek', 'ug', 'gmbh', 'ag'], true) || trim($d['registerNumber'] ?? '') !== '') {
            $L = array_merge($L, ['', '## Registereintrag', '', 'Eintragung im Handelsregister.  ', 'Registergericht: ' . self::v($d, 'registerCourt', 'Amtsgericht') . '  ', 'Registernummer: ' . self::v($d, 'registerNumber', 'HRB …')]);
            if (trim($d['capital'] ?? '') !== '') {
                $L[] = 'Stammkapital: ' . $d['capital'];
            }
        }
        if (trim($d['vatId'] ?? '') !== '' || trim($d['wId'] ?? '') !== '') {
            $L = array_merge($L, ['', '## Umsatzsteuer', '']);
            if (trim($d['vatId'] ?? '') !== '') {
                $L[] = 'Umsatzsteuer-Identifikationsnummer gemäß § 27 a Umsatzsteuergesetz: ' . $d['vatId'] . '  ';
            }
            if (trim($d['wId'] ?? '') !== '') {
                $L[] = 'Wirtschafts-Identifikationsnummer gemäß § 139 c Abgabenordnung: ' . $d['wId'];
            }
        }
        if (trim($d['profession'] ?? '') !== '') {
            $L = array_merge($L, ['', '## Berufsrechtliche Angaben', '', ...array_map(static fn ($l) => trim($l) . '  ', preg_split('/\R|\|/', trim($d['profession'])) ?: [])]);
        }
        if (trim($d['contentResponsible'] ?? '') !== '') {
            $L = array_merge($L, ['', '## Verantwortlich für den Inhalt nach § 18 Abs. 2 MStV', '', $d['contentResponsible']]);
        }
        $odr = $d['odr'] ?? 'unwilling';
        if ($odr !== 'none') {
            $L = array_merge($L, ['', '## Verbraucherstreitbeilegung', '', $odr === 'willing'
                ? 'Wir sind bereit, an Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle teilzunehmen.'
                : 'Wir sind nicht bereit und nicht verpflichtet, an Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle teilzunehmen.']);
        }
        $L = array_merge($L, [
            '', '## Haftung für Inhalte', '',
            'Als Diensteanbieter sind wir gemäß § 7 Abs. 1 DDG für eigene Inhalte auf diesen Seiten nach den allgemeinen Gesetzen verantwortlich. Nach §§ 8 bis 10 DDG sind wir als Diensteanbieter jedoch nicht verpflichtet, übermittelte oder gespeicherte fremde Informationen zu überwachen oder nach Umständen zu forschen, die auf eine rechtswidrige Tätigkeit hinweisen. Verpflichtungen zur Entfernung oder Sperrung der Nutzung von Informationen nach den allgemeinen Gesetzen bleiben hiervon unberührt. Eine diesbezügliche Haftung ist jedoch erst ab dem Zeitpunkt der Kenntnis einer konkreten Rechtsverletzung möglich. Bei Bekanntwerden entsprechender Rechtsverletzungen entfernen wir diese Inhalte umgehend.',
            '', '## Haftung für Links', '',
            'Unser Angebot enthält Links zu externen Webseiten Dritter, auf deren Inhalte wir keinen Einfluss haben. Für diese fremden Inhalte können wir daher keine Gewähr übernehmen. Für die Inhalte der verlinkten Seiten ist stets der jeweilige Anbieter oder Betreiber der Seiten verantwortlich. Die verlinkten Seiten wurden zum Zeitpunkt der Verlinkung auf mögliche Rechtsverstöße überprüft; rechtswidrige Inhalte waren zu diesem Zeitpunkt nicht erkennbar. Bei Bekanntwerden von Rechtsverletzungen entfernen wir derartige Links umgehend.',
            '', '## Urheberrecht', '',
            'Die durch die Seitenbetreiber erstellten Inhalte und Werke auf diesen Seiten unterliegen dem deutschen Urheberrecht. Beiträge Dritter sind als solche gekennzeichnet. Die Vervielfältigung, Bearbeitung, Verbreitung und jede Art der Verwertung außerhalb der Grenzen des Urheberrechtes bedürfen der schriftlichen Zustimmung des jeweiligen Autors bzw. Erstellers.',
            '', self::stand(),
        ]);
        return implode("\n", $L) . "\n";
    }

    /** @param array<string,string> $d */
    private static function datenschutz(array $d): string
    {
        $imp = self::load('IMPRESSUM')['data'];
        $on = static fn (string $k): bool => ($d[$k] ?? '0') === '1';
        $analytics = $d['analytics'] ?? 'none';
        $L = ['# Datenschutzerklärung', '', '## 1. Verantwortlicher und Überblick', '',
            'Verantwortlich für die Verarbeitung personenbezogener Daten auf dieser Webseite und in diesem Kundenbereich im Sinne der Datenschutz-Grundverordnung (DSGVO) ist:', '',
            self::v($imp, 'name', 'Firmenname') . '  ', self::v($imp, 'street', 'Straße Nr.') . '  ', self::v($imp, 'zip', 'PLZ') . ' ' . self::v($imp, 'city', 'Ort') . '  ',
            'E-Mail: ' . self::v($imp, 'email', 'E-Mail') . (trim($imp['phone'] ?? '') !== '' ? '  ' . "\n" . 'Telefon: ' . $imp['phone'] : ''), '',
            'Diese Erklärung informiert darüber, welche personenbezogenen Daten wir zu welchen Zwecken verarbeiten, auf welcher Rechtsgrundlage das geschieht und welche Rechte du hast. Personenbezogene Daten sind alle Angaben, mit denen du direkt oder indirekt identifiziert werden kannst.'];
        if (trim($d['dpoName'] ?? '') !== '') {
            $L = array_merge($L, ['', '**Datenschutzbeauftragte(r):** ' . $d['dpoName'] . ', E-Mail: ' . self::v($d, 'dpoEmail', 'E-Mail')]);
        }
        $n = 2;
        $h = static function (string $title) use (&$L, &$n): void {
            $L[] = '';
            $L[] = '## ' . $n++ . '. ' . $title;
            $L[] = '';
        };

        $h('Hosting und Server-Logfiles');
        $L[] = 'Diese Webseite wird bei ' . self::v($d, 'hostingProvider', 'Hosting-Anbieter') . ' gehostet. Beim Aufruf werden vom Server automatisch Informationen erfasst und in Logfiles gespeichert: IP-Adresse, Datum und Uhrzeit, aufgerufene Seite bzw. Datei, übertragene Datenmenge, Statuscode sowie Browser- und Betriebssysteminformationen.';
        $L[] = '';
        $L[] = 'Zweck ist die technisch fehlerfreie Bereitstellung, Stabilität und Sicherheit der Seite. Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO (berechtigtes Interesse am sicheren und effizienten Betrieb). Die Logfiles werden nach ' . ((int) ($d['logDays'] ?? 14) ?: 14) . ' Tagen gelöscht.';
        if ($on('hostingAvv')) {
            $L[] = '';
            $L[] = 'Mit dem Hosting-Anbieter besteht ein Vertrag zur Auftragsverarbeitung nach Art. 28 DSGVO.';
        }

        if ($on('portal')) {
            $h('Kundenportal und Benutzerkonto');
            $L[] = 'Für Kunden bieten wir einen geschützten Bereich an. Dafür verarbeiten wir Name, Firma, E-Mail-Adresse, Benutzername, das (nur als Hash gespeicherte) Passwort sowie Zugriffszeitpunkte. Im Portal siehst du deine Rechnungen, Angebote, Verträge, Lizenzen, Bestellungen und Support-Anfragen. Rechtsgrundlage ist die Durchführung des Vertrags bzw. vorvertraglicher Maßnahmen (Art. 6 Abs. 1 lit. b DSGVO). Dein Konto kannst du jederzeit zur Löschung freigeben lassen; gesetzliche Aufbewahrungspflichten bleiben unberührt.';
            if ($on('registration')) {
                $L[] = '';
                $L[] = '**Registrierung:** Bei der Selbstregistrierung erheben wir Name, Firma und E-Mail-Adresse. Zur Bestätigung senden wir einen Link an die angegebene Adresse (Double-Opt-in); erst danach wird das Konto aktiv. Die Zustimmung zu dieser Erklärung wird mit Zeitpunkt gespeichert (Art. 6 Abs. 1 lit. b und f DSGVO, Nachweis der Einwilligung nach Art. 7 Abs. 1 DSGVO). Nicht bestätigte Registrierungen werden gelöscht.';
            }
        }
        if ($on('tickets')) {
            $h('Support-Anfragen');
            $L[] = 'Wenn du den Support nutzt, verarbeiten wir Betreff, Nachrichten, hochgeladene Anhänge, Kategorie, Priorität, Status und Zeitpunkte sowie – wenn du es angibst – die zugehörige Lizenz oder Domain. Mitarbeiter von uns sehen diese Angaben, um die Anfrage zu bearbeiten. Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO. Bitte lade keine Dateien mit Zugangsdaten oder besonders sensiblen Daten hoch, die nicht erforderlich sind.';
        }

        $h('Angebote, Aufträge, Rechnungen und Verträge');
        $L[] = 'Zur Erstellung von Angeboten, zur Projektabwicklung, Rechnungsstellung, Zahlungsüberwachung und Vertragsverwaltung verarbeiten wir Stammdaten (Name, Firma, Anschrift, E-Mail, Telefon, ggf. USt-IdNr.), Vertrags-, Projekt- und Zahlungsdaten. Rechtsgrundlagen sind Art. 6 Abs. 1 lit. b DSGVO (Vertrag) und Art. 6 Abs. 1 lit. c DSGVO (steuer- und handelsrechtliche Pflichten).';
        if ($on('shop')) {
            $h('Bestellungen');
            $L[] = 'Bei einer Bestellung im Kundenportal verarbeiten wir die bestellten Produkte, Preise, Laufzeiten und deine Rechnungsdaten, um die Bestellung abzuwickeln und die Rechnung zu erstellen (Art. 6 Abs. 1 lit. b DSGVO).';
        }
        if ($on('licenses')) {
            $h('Software-Lizenzen und Update-Prüfung');
            $L[] = 'Für lizenzierte Software verarbeiten wir Lizenzschlüssel, die hinterlegte(n) Domain(s), Versionsnummern, Zeitpunkte der Prüfungen sowie – aus technischen Gründen – die IP-Adresse der anfragenden Installation. Das dient der Lizenzprüfung, der Missbrauchsabwehr und der Bereitstellung von Updates (Art. 6 Abs. 1 lit. b und f DSGVO). Weitere Daten aus deiner Installation, insbesondere Inhalte oder Kundendaten, werden dabei nicht übertragen.';
        }
        if ($on('mail')) {
            $h('E-Mail-Versand');
            $L[] = 'Rechnungen, Angebote, Mahnungen, Bestätigungen und Benachrichtigungen versenden wir per E-Mail' . (trim($d['mailProvider'] ?? '') !== '' ? ' über den Dienstleister ' . $d['mailProvider'] : '') . '. Dabei werden Empfängeradresse, Inhalt, Anhänge und Zeitpunkt verarbeitet (Art. 6 Abs. 1 lit. b DSGVO). Der Transport erfolgt, soweit der Empfängerserver dies unterstützt, verschlüsselt.';
        }
        {
            $h('Kontaktaufnahme');
            $L[] = 'Wenn du uns per ' . ($on('contactForm') ? 'Kontaktformular, ' : '') . 'E-Mail oder Telefon kontaktierst, verarbeiten wir deine Angaben, um die Anfrage zu beantworten. Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO, wenn sie mit einem Vertrag zusammenhängt, sonst Art. 6 Abs. 1 lit. f DSGVO. Wir löschen die Daten, sobald die Anfrage erledigt ist und keine Aufbewahrungspflichten entgegenstehen.';
        }
        if ($on('paypal') || $on('stripe')) {
            $h('Zahlungsdienste');
            $parts = [];
            if ($on('paypal')) {
                $parts[] = 'PayPal (PayPal (Europe) S.à r.l. et Cie, S.C.A., 22–24 Boulevard Royal, L-2449 Luxemburg)';
            }
            if ($on('stripe')) {
                $parts[] = 'Stripe (Stripe Payments Europe, Ltd., 1 Grand Canal Street Lower, Dublin, Irland)';
            }
            $L[] = 'Für Zahlungen können wir folgende Dienste anbieten: ' . implode(' und ', $parts) . '. Bei Nutzung werden die zur Zahlung nötigen Daten an den jeweiligen Anbieter übermittelt (Art. 6 Abs. 1 lit. b DSGVO). Es gilt zusätzlich die Datenschutzerklärung des Anbieters.';
        }
        if ($on('newsletter')) {
            $h('Newsletter');
            $L[] = 'Wenn du dich für unseren Newsletter anmeldest, verarbeiten wir deine E-Mail-Adresse und den Zeitpunkt der Anmeldung und Bestätigung (Double-Opt-in) auf Grundlage deiner Einwilligung (Art. 6 Abs. 1 lit. a DSGVO). Der Versand erfolgt über ' . self::v($d, 'newsletterProvider', 'Dienstleister') . '. Du kannst die Einwilligung jederzeit mit Wirkung für die Zukunft widerrufen, z. B. über den Abmeldelink in jeder Ausgabe.';
        }
        $h('Webanalyse');
        if ($analytics === 'none') {
            $L[] = 'Wir setzen keine Webanalyse- oder Tracking-Dienste ein.';
        } elseif ($analytics === 'self') {
            $L[] = 'Wir nutzen ' . self::v($d, 'analyticsName', 'Software') . ' auf unserem eigenen Server, ohne Cookies und mit anonymisierten IP-Adressen. Es werden keine Daten an Dritte weitergegeben. Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO (statistische Auswertung zur Verbesserung des Angebots). Du kannst der Auswertung jederzeit widersprechen.';
        } else {
            $L[] = 'Wir nutzen ' . self::v($d, 'analyticsName', 'Dienst') . ' zur Analyse der Nutzung. Das geschieht nur mit deiner Einwilligung (Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TDDDG), die du jederzeit mit Wirkung für die Zukunft widerrufen kannst. [Hinweis für den Betreiber: Dafür ist ein Einwilligungsbanner auf der Webseite nötig.]';
        }
        if (($d['fonts'] ?? 'local') === 'google' || $on('maps') || $on('video')) {
            $h('Externe Inhalte');
            if (($d['fonts'] ?? 'local') === 'google') {
                $L[] = '**Google Fonts:** Zur einheitlichen Darstellung laden wir Schriftarten von Google (Google Ireland Limited, Gordon House, Barrow Street, Dublin 4, Irland). Dabei wird deine IP-Adresse an Google übertragen. Rechtsgrundlage ist deine Einwilligung (Art. 6 Abs. 1 lit. a DSGVO).';
                $L[] = '';
            }
            if ($on('maps')) {
                $L[] = '**Karten:** Eingebundene Karten werden von einem Drittanbieter geladen; dabei wird deine IP-Adresse übertragen. Das geschieht nur nach deiner Einwilligung (Art. 6 Abs. 1 lit. a DSGVO).';
                $L[] = '';
            }
            if ($on('video')) {
                $L[] = '**Videos:** Eingebundene Videos werden vom jeweiligen Anbieter geladen; dabei wird deine IP-Adresse übertragen und der Anbieter kann Cookies setzen. Das geschieht nur nach deiner Einwilligung (Art. 6 Abs. 1 lit. a DSGVO).';
            }
        } else {
            $h('Schriftarten und externe Inhalte');
            $L[] = 'Alle Schriftarten und Skripte werden lokal von unserem Server geladen. Es werden keine Inhalte von Drittanbietern nachgeladen.';
        }
        $h('Cookies und Browser-Speicher');
        $L[] = 'Im Kundenbereich speichert dein Browser technisch notwendige Informationen (Anmelde-Token im lokalen Speicher), damit du angemeldet bleibst. Diese Speicherung ist für die Bereitstellung des ausdrücklich gewünschten Dienstes unbedingt erforderlich und bedarf nach § 25 Abs. 2 Nr. 2 TDDDG keiner Einwilligung. Mit dem Abmelden wird sie entfernt. Cookies zu Werbe- oder Analysezwecken setzen wir nicht ein' . ($analytics === 'other' ? ', außer nach deiner Einwilligung' : '') . '.';
        $h('Speicherdauer');
        $L[] = 'Wir speichern personenbezogene Daten nur so lange, wie es für den jeweiligen Zweck erforderlich ist. Rechnungen, Buchungsbelege und Geschäftsunterlagen müssen wir nach Handels- und Steuerrecht (§ 257 HGB, § 147 AO) sechs bis zehn Jahre aufbewahren; die Verarbeitung dieser Daten wird danach eingeschränkt bzw. beendet. Support-Anfragen löschen wir spätestens nach Ablauf der Verjährungsfristen (in der Regel drei Jahre nach Jahresende). Server-Logfiles werden nach kurzer Frist gelöscht (siehe oben).';
        $h('Empfänger und Drittländer');
        $L[] = 'Wir geben Daten nur weiter, wenn das zur Vertragserfüllung nötig ist, eine Rechtspflicht besteht oder du eingewilligt hast: an Hosting- und E-Mail-Dienstleister, Zahlungsdienste, Steuerberater, Banken und ggf. Behörden. Dienstleister werden als Auftragsverarbeiter nach Art. 28 DSGVO verpflichtet.';
        $L[] = '';
        $L[] = $on('thirdCountry')
            ? 'Soweit wir Dienstleister außerhalb der EU/des EWR einsetzen, geschieht die Übermittlung nur auf Grundlage eines Angemessenheitsbeschlusses (z. B. EU-US Data Privacy Framework) oder von EU-Standardvertragsklauseln (Art. 44 ff. DSGVO).'
            : 'Eine Übermittlung in Länder außerhalb der EU/des EWR findet nicht statt.';
        $h('Deine Rechte');
        $L[] = 'Du hast nach der DSGVO das Recht auf Auskunft (Art. 15), Berichtigung (Art. 16), Löschung (Art. 17), Einschränkung der Verarbeitung (Art. 18), Datenübertragbarkeit (Art. 20) und Widerspruch (Art. 21). Eine erteilte Einwilligung kannst du jederzeit mit Wirkung für die Zukunft widerrufen (Art. 7 Abs. 3); die Rechtmäßigkeit der bis dahin erfolgten Verarbeitung bleibt unberührt.';
        $L[] = '';
        $L[] = '**Widerspruchsrecht:** Soweit wir Daten auf Grundlage berechtigter Interessen (Art. 6 Abs. 1 lit. f DSGVO) verarbeiten, kannst du aus Gründen, die sich aus deiner besonderen Situation ergeben, jederzeit Widerspruch einlegen.';
        $L[] = '';
        $L[] = 'Zur Ausübung deiner Rechte genügt eine Nachricht an ' . self::v($imp, 'email', 'E-Mail') . '. Außerdem hast du das Recht, dich bei einer Datenschutz-Aufsichtsbehörde zu beschweren (Art. 77 DSGVO)' . (trim($d['authority'] ?? '') !== '' ? ', z. B. bei: ' . $d['authority'] : '') . '.';
        $h('Sicherheit');
        $L[] = 'Die Übertragung erfolgt per TLS-Verschlüsselung (erkennbar an „https://“ in der Adresszeile). Passwörter speichern wir nur als sichere Hashwerte. Wir treffen angemessene technische und organisatorische Maßnahmen, um Daten gegen Verlust und unbefugten Zugriff zu schützen.';
        $L[] = '';
        $L[] = self::stand();
        return implode("\n", $L) . "\n";
    }

    /** @param array<string,string> $d */
    private static function agb(array $d): string
    {
        $imp = self::load('IMPRESSUM')['data'];
        $on = static fn (string $k): bool => ($d[$k] ?? '0') === '1';
        $b2b = ($d['audience'] ?? 'both') === 'b2b';
        $pay = (int) ($d['paymentDays'] ?? 14);
        $dep = (int) ($d['deposit'] ?? 30);
        $rev = (int) ($d['revisions'] ?? 2);
        $appr = (int) ($d['approvalDays'] ?? 14) ?: 14;
        $war = (int) ($d['warrantyMonths'] ?? 12) ?: 12;
        $name = self::v($imp, 'name', 'Anbieter');
        $L = ['# Allgemeine Geschäftsbedingungen', '', '**' . $name . '**'];
        $n = 1;
        $h = static function (string $title) use (&$L, &$n): void {
            $L[] = '';
            $L[] = '## § ' . $n++ . ' ' . $title;
            $L[] = '';
        };
        $h('Geltungsbereich');
        $L[] = '(1) Diese Bedingungen gelten für alle Verträge zwischen ' . $name . ' (nachfolgend „Auftragnehmer“) und seinen Kunden (nachfolgend „Auftraggeber“) über Webdesign, Webentwicklung, Gestaltung, Beratung' . ($on('hosting') ? ', Hosting und Wartung' : '') . ($on('licenses') ? ' sowie die Überlassung von Software' : '') . '.';
        $L[] = '';
        $L[] = $b2b
            ? '(2) Das Angebot richtet sich ausschließlich an Unternehmer im Sinne des § 14 BGB. Abweichende oder ergänzende Bedingungen des Auftraggebers gelten nur, wenn der Auftragnehmer ihnen schriftlich zugestimmt hat.'
            : '(2) Verbraucher im Sinne dieser Bedingungen ist jede natürliche Person, die ein Rechtsgeschäft zu Zwecken abschließt, die überwiegend weder ihrer gewerblichen noch ihrer selbständigen beruflichen Tätigkeit zugerechnet werden können (§ 13 BGB); Unternehmer ist, wer in Ausübung seiner gewerblichen oder selbständigen beruflichen Tätigkeit handelt (§ 14 BGB). Abweichende Bedingungen des Auftraggebers gelten nur, wenn der Auftragnehmer ihnen schriftlich zugestimmt hat.';

        $h('Vertragsschluss');
        $L[] = '(1) Angebote des Auftragnehmers sind freibleibend, sofern sie nicht ausdrücklich eine Bindungsfrist enthalten. Der Vertrag kommt durch die schriftliche oder in Textform erklärte Annahme des Angebots durch den Auftraggeber (auch über das Kundenportal) oder durch Auftragsbestätigung des Auftragnehmers zustande.';
        $L[] = '';
        $L[] = '(2) Der Auftragnehmer darf für die Leistung qualifizierte Dritte (Unterauftragnehmer) einsetzen.';

        $h('Leistungsumfang und Mitwirkung');
        $L[] = '(1) Art und Umfang der Leistung ergeben sich aus dem Angebot bzw. der Auftragsbestätigung. Nachträgliche Änderungswünsche, die darüber hinausgehen, werden gesondert angeboten und vergütet.';
        $L[] = '';
        $L[] = '(2) Der Auftraggeber stellt Texte, Bilder, Logos und sonstige Inhalte rechtzeitig, vollständig und in verwendbarer Form bereit und sichert zu, dass er zur Nutzung berechtigt ist und Rechte Dritter nicht verletzt werden. Der Auftraggeber stellt den Auftragnehmer von Ansprüchen Dritter frei, die auf rechtswidrig überlassenen Inhalten beruhen.';
        $L[] = '';
        $L[] = '(3) Verzögert sich die Mitwirkung des Auftraggebers, verschieben sich vereinbarte Termine angemessen. Mehraufwand durch verspätete oder mangelhafte Mitwirkung kann nach dem vereinbarten Stundensatz abgerechnet werden.';
        $L[] = '';
        $L[] = '(4) Im Preis sind ' . $rev . ' Korrekturschleife(n) je Gestaltungsphase enthalten. Weitere Korrekturen werden nach Aufwand berechnet. Die rechtliche Prüfung der Inhalte (z. B. Impressum, Datenschutz, Wettbewerbs- und Markenrecht) ist nicht geschuldet, sofern nicht ausdrücklich vereinbart.';

        $h('Vergütung und Zahlung');
        $L[] = '(1) Es gelten die im Angebot genannten Preise zuzüglich der gesetzlichen Umsatzsteuer, soweit diese anfällt.';
        $L[] = '';
        $L[] = '(2) ' . ($dep > 0 ? 'Bei Projektbeginn wird eine Anzahlung von ' . $dep . ' % der Auftragssumme fällig; der Rest wird nach Abnahme berechnet. ' : '') . 'Rechnungen sind ' . ($pay > 0 ? 'innerhalb von ' . $pay . ' Tagen ab Rechnungsdatum' : 'sofort') . ' ohne Abzug zahlbar. Bei längeren Projekten dürfen Teilrechnungen nach Leistungsfortschritt gestellt werden.';
        $L[] = '';
        $L[] = '(3) Bei Zahlungsverzug gelten die gesetzlichen Regeln (§ 288 BGB); gegenüber Unternehmern beträgt der Verzugszins neun Prozentpunkte über dem Basiszinssatz und der Auftragnehmer kann die Pauschale nach § 288 Abs. 5 BGB verlangen. Bis zur vollständigen Zahlung darf der Auftragnehmer weitere Leistungen zurückhalten.';

        $h('Nutzungsrechte und Urheberrecht');
        $L[] = '(1) Der Auftragnehmer räumt dem Auftraggeber mit vollständiger Bezahlung das einfache, zeitlich und räumlich unbeschränkte Recht ein, die vereinbarten Arbeitsergebnisse für den vertraglich vorgesehenen Zweck zu nutzen. Weitergehende Rechte (z. B. Bearbeitung durch Dritte, Weitergabe von Quellcode) bedürfen einer gesonderten Vereinbarung, soweit sie nicht Vertragsinhalt sind.';
        $L[] = '';
        $L[] = '(2) Bis zur vollständigen Zahlung bleiben alle Rechte beim Auftragnehmer (Eigentums- und Rechtevorbehalt). Verwendete Open-Source-Komponenten und Fremdlizenzen (z. B. Schriften, Bilder, Plugins) unterliegen den jeweiligen Lizenzbedingungen; erforderliche Lizenzgebühren trägt der Auftraggeber, sofern nicht anders vereinbart.';
        $L[] = '';
        $L[] = $on('reference')
            ? '(3) Der Auftragnehmer darf das Projekt mit Namen und Abbildungen als Referenz nennen. Der Auftraggeber kann dem jederzeit in Textform widersprechen.'
            : '(3) Eine Nennung des Auftraggebers oder des Projekts als Referenz erfolgt nur mit dessen Zustimmung.';

        $h('Abnahme');
        $L[] = '(1) Nach Fertigstellung teilt der Auftragnehmer dies mit. Der Auftraggeber prüft das Werk und erklärt die Abnahme innerhalb von ' . $appr . ' Tagen. Die Abnahme darf nicht wegen unwesentlicher Mängel verweigert werden.';
        $L[] = '';
        $L[] = '(2) Die Abnahme gilt als erfolgt, wenn der Auftraggeber das Werk produktiv nutzt oder nicht innerhalb der Frist unter Angabe konkreter Mängel widerspricht; auf diese Folge weist der Auftragnehmer bei Fristsetzung hin.';

        $h('Gewährleistung');
        $L[] = '(1) Mängel sind unverzüglich in nachvollziehbarer Form schriftlich oder in Textform mitzuteilen. Der Auftragnehmer beseitigt Mängel nach seiner Wahl durch Nachbesserung oder Neuerstellung.';
        $L[] = '';
        $L[] = $b2b
            ? '(2) Die Gewährleistungsfrist beträgt ' . $war . ' Monate ab Abnahme. Bei Fehlschlagen der Nacherfüllung kann der Auftraggeber nach den gesetzlichen Vorschriften mindern oder vom Vertrag zurücktreten.'
            : '(2) Gegenüber Unternehmern beträgt die Gewährleistungsfrist ' . $war . ' Monate ab Abnahme; gegenüber Verbrauchern gilt die gesetzliche Frist. Bei Fehlschlagen der Nacherfüllung kann der Auftraggeber nach den gesetzlichen Vorschriften mindern oder vom Vertrag zurücktreten.';
        $L[] = '';
        $L[] = '(3) Keine Mängel sind Abweichungen, die auf der unterschiedlichen Darstellung in Browsern, Endgeräten oder Bildschirmen beruhen, soweit die zum Zeitpunkt der Abnahme gängigen Browser in ihren aktuellen Versionen unterstützt werden, sowie Funktionsänderungen von Drittanbietern nach der Abnahme.';

        $h('Haftung');
        $L[] = '(1) Der Auftragnehmer haftet unbeschränkt bei Vorsatz und grober Fahrlässigkeit, bei Verletzung von Leben, Körper und Gesundheit, nach dem Produkthaftungsgesetz sowie im Umfang übernommener Garantien.';
        $L[] = '';
        $L[] = '(2) Bei leicht fahrlässiger Verletzung wesentlicher Vertragspflichten (Pflichten, deren Erfüllung die ordnungsgemäße Durchführung des Vertrags erst ermöglicht und auf deren Einhaltung der Auftraggeber regelmäßig vertrauen darf) ist die Haftung auf den vertragstypischen, vorhersehbaren Schaden begrenzt. Im Übrigen ist die Haftung bei leichter Fahrlässigkeit ausgeschlossen.';
        $L[] = '';
        $L[] = '(3) Für Datenverlust haftet der Auftragnehmer nur, wenn der Auftraggeber regelmäßige, dem Risiko angemessene Datensicherungen vorgenommen hat und der Schaden bei deren Nutzung nicht vermeidbar gewesen wäre.';

        if ($on('hosting')) {
            $h('Hosting und Wartung');
            $L[] = '(1) Hosting- und Wartungsleistungen werden als Dauerschuldverhältnis erbracht. Der Auftragnehmer bemüht sich um hohe Verfügbarkeit, schuldet aber keine ununterbrochene Erreichbarkeit; geplante Wartungsarbeiten werden nach Möglichkeit vorher angekündigt.';
            $L[] = '';
            $L[] = '(2) Soweit personenbezogene Daten im Auftrag des Auftraggebers verarbeitet werden, schließen die Parteien einen Vertrag zur Auftragsverarbeitung nach Art. 28 DSGVO. Für die Rechtmäßigkeit der Inhalte bleibt der Auftraggeber verantwortlich; rechtswidrige Inhalte darf der Auftragnehmer nach Kenntnis sperren.';
        }
        if ($on('recurring')) {
            $h('Laufzeit und Kündigung laufender Leistungen');
            $L[] = 'Laufende Leistungen (z. B. Wartung, Hosting, Pflege) laufen auf unbestimmte Zeit, sofern nichts anderes vereinbart ist, und können mit einer Frist von ' . (int) ($d['noticeMonths'] ?? 1) . ' Monat(en) zum Ende der jeweiligen Abrechnungsperiode in Textform gekündigt werden. Das Recht zur außerordentlichen Kündigung aus wichtigem Grund bleibt unberührt.';
        }
        if ($on('licenses')) {
            $h('Software-Lizenzen, Updates und Support');
            $L[] = '(1) Der Auftragnehmer räumt dem Auftraggeber das nicht ausschließliche, nicht übertragbare Recht ein, die lizenzierte Software für die im Lizenzschlüssel genannte Domain bzw. den vereinbarten Umfang zu nutzen. Eine Weitergabe, Vervielfältigung oder Vermietung der Software ist nicht gestattet.';
            $L[] = '';
            $L[] = '(2) Updates und Support stehen für den im Angebot bzw. der Lizenz angegebenen Zeitraum zur Verfügung. Mietlizenzen enden mit Ablauf der Laufzeit, sofern sie nicht verlängert werden; danach kann die Software eingeschränkt (nur lesend) laufen. Daten des Auftraggebers bleiben exportierbar.';
            $L[] = '';
            $L[] = '(3) Die Lizenzprüfung überträgt Lizenzschlüssel, Domain und Versionsnummer an den Lizenzserver des Auftragnehmers (siehe Datenschutzerklärung).';
        }
        if (!$b2b && $on('withdrawal')) {
            $h('Widerrufsrecht für Verbraucher');
            $L[] = 'Verbrauchern, die Verträge außerhalb von Geschäftsräumen oder im Fernabsatz schließen, steht ein gesetzliches Widerrufsrecht zu. Über Bedingungen, Fristen und das Verfahren informiert der Auftragnehmer vor Vertragsschluss gesondert in einer Widerrufsbelehrung mit Muster-Widerrufsformular. Bei Dienstleistungen erlischt das Widerrufsrecht vorzeitig, wenn der Auftragnehmer auf ausdrücklichen Wunsch des Verbrauchers mit der Leistung begonnen hat und die Leistung vollständig erbracht ist (§ 356 Abs. 4 BGB).';
        }
        $h('Vertraulichkeit und Datenschutz');
        $L[] = 'Beide Parteien behandeln vertrauliche Informationen der jeweils anderen Seite vertraulich. Die Verarbeitung personenbezogener Daten richtet sich nach der Datenschutzerklärung des Auftragnehmers.';
        $h('Schlussbestimmungen');
        $L[] = '(1) Es gilt das Recht der Bundesrepublik Deutschland unter Ausschluss des UN-Kaufrechts' . ($b2b ? '' : '; gegenüber Verbrauchern gilt diese Rechtswahl nur, soweit dadurch nicht zwingende Verbraucherschutzvorschriften des Aufenthaltsstaates entzogen werden') . '.';
        $L[] = '';
        if (trim($d['jurisdiction'] ?? '') !== '') {
            $L[] = '(2) Ist der Auftraggeber Kaufmann, juristische Person des öffentlichen Rechts oder öffentlich-rechtliches Sondervermögen, ist Gerichtsstand ' . $d['jurisdiction'] . '.';
            $L[] = '';
        }
        $L[] = '(' . (trim($d['jurisdiction'] ?? '') !== '' ? '3' : '2') . ') Änderungen und Ergänzungen bedürfen der Textform. Sollte eine Bestimmung unwirksam sein, bleibt die Wirksamkeit der übrigen Bestimmungen unberührt; an die Stelle der unwirksamen Regelung tritt die gesetzliche.';
        $L[] = '';
        $L[] = self::stand();
        return implode("\n", $L) . "\n";
    }

    /* ---------- Markdown-light → HTML ---------- */

    public static function html(string $md): string
    {
        $out = [];
        $list = false;
        $para = [];
        $flush = static function () use (&$para, &$out): void {
            if ($para !== []) {
                $out[] = '<p>' . implode('<br>', $para) . '</p>';
                $para = [];
            }
        };
        foreach (preg_split('/\R/', str_replace("\r", '', $md)) ?: [] as $line) {
            $t = rtrim($line);
            $t = trim($t);
            if ($t === '') {
                $flush();
                if ($list) {
                    $out[] = '</ul>';
                    $list = false;
                }
                continue;
            }
            if (preg_match('/^(#{1,3})\s+(.+)$/u', $t, $m)) {
                $flush();
                if ($list) {
                    $out[] = '</ul>';
                    $list = false;
                }
                $lvl = strlen($m[1]);
                $out[] = "<h$lvl>" . self::inline($m[2]) . "</h$lvl>";
            } elseif (preg_match('/^[-*]\s+(.+)$/u', $t, $m)) {
                $flush();
                if (!$list) {
                    $out[] = '<ul>';
                    $list = true;
                }
                $out[] = '<li>' . self::inline($m[1]) . '</li>';
            } else {
                $para[] = self::inline($t);
            }
        }
        $flush();
        if ($list) {
            $out[] = '</ul>';
        }
        return implode("\n", $out);
    }

    private static function inline(string $s): string
    {
        $s = htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $s = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s) ?? $s;
        $s = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+)\)/u', '<a href="$2" rel="noopener noreferrer">$1</a>', $s) ?? $s;
        // Platzhalter [Angabe] fehlender Pflichtfelder hervorheben
        return preg_replace('/\[([^\]<>]{1,60})\](?!\()/u', '<mark>[$1]</mark>', $s) ?? $s;
    }

    /** Vollständige öffentliche Seite. */
    public static function page(string $title, string $htmlBody, ?string $updatedAt): string
    {
        $company = htmlspecialchars((string) (Env::get('COMPANY_NAME', '') ?? ''), ENT_QUOTES, 'UTF-8');
        $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $nav = '';
        foreach (self::TYPES as $type) {
            if (self::isPublished($type)) {
                $nav .= '<a href="' . self::PATHS[$type] . '">' . htmlspecialchars(self::TITLES[$type], ENT_QUOTES, 'UTF-8') . '</a> ';
            }
        }
        return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $t . ($company !== '' ? ' – ' . $company : '') . '</title>'
            . '<style>body{font:16px/1.65 system-ui,-apple-system,Segoe UI,sans-serif;color:#1c1d22;background:#fafafa;margin:0}main{max-width:780px;margin:0 auto;padding:32px 20px 64px}'
            . 'h1{font-size:2rem;margin:.2em 0 .6em}h2{font-size:1.2rem;margin:1.8em 0 .4em}h3{font-size:1.05rem}p{margin:.6em 0}mark{background:#fff1b8;padding:0 .2em;border-radius:3px}'
            . 'a{color:#2b59c3}nav{margin-top:48px;padding-top:16px;border-top:1px solid #ddd;font-size:.9rem}nav a{margin-right:16px}@media(prefers-color-scheme:dark){body{background:#16171b;color:#e6e6ea}a{color:#8fb0ff}nav{border-color:#333}mark{background:#5b4a00;color:#fff}}</style></head><body><main>'
            . $htmlBody . '<nav>' . $nav . '</nav></main></body></html>';
    }
}
