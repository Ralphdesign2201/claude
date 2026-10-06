<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\ApiError;
use App\Support\Env;

/**
 * Einstellungen, die in der Oberfläche änderbar sind. Gespeichert werden sie in der Einstellungsdatei (database/settings.json),
 * nicht in der Datenbank – so überleben sie einen Wechsel der Datenbank und lassen sich auch ohne Datenbank lesen.
 * Absichtlich nicht dabei: JWT_SECRET, CRON_TOKEN, Speicherorte (Pfade) und Lizenz-Signaturschlüssel.
 */
final class SettingsService
{
    private const NO_PROXY = ['*'];

    /** @return list<array{id:string,title:string,intro?:string,fields:list<array<string,mixed>>}> */
    public static function schema(): array
    {
        $f = static fn (string $key, string $label, string $type = 'text', array $extra = []): array => ['key' => $key, 'label' => $label, 'type' => $type] + $extra;
        return [
            ['id' => 'company', 'title' => 'Firmendaten', 'intro' => 'Erscheinen auf Rechnungen, Angeboten und Mahnungen sowie in E-Mails.', 'fields' => [
                $f('COMPANY_NAME', 'Firmenname', 'text', ['max' => 120, 'default' => 'Ihr Firmenname']),
                $f('COMPANY_ADDRESS', 'Anschrift', 'lines', ['max' => 300, 'help' => 'Eine Zeile pro Zeile der Anschrift.']),
                $f('COMPANY_EMAIL', 'E-Mail', 'email'),
                $f('COMPANY_PHONE', 'Telefon', 'text', ['max' => 60]),
                $f('COMPANY_WEBSITE', 'Webseite', 'text', ['max' => 200]),
                $f('COMPANY_VAT_ID', 'USt-IdNr.', 'text', ['max' => 40]),
                $f('COMPANY_TAX_NUMBER', 'Steuernummer', 'text', ['max' => 40]),
                $f('COMPANY_BANK', 'Bank', 'text', ['max' => 120]),
                $f('COMPANY_IBAN', 'IBAN', 'text', ['max' => 40, 'pattern' => '/^[A-Za-z0-9 ]{0,40}$/', 'patternError' => 'Die IBAN darf nur Buchstaben, Ziffern und Leerzeichen enthalten.']),
                $f('COMPANY_BIC', 'BIC', 'text', ['max' => 20]),
                $f('INVOICE_ZERO_TAX_NOTE', 'Hinweis bei 0 % Umsatzsteuer', 'text', ['max' => 300, 'help' => 'z. B. Kleinunternehmer-Hinweis – bitte mit dem Steuerberater abstimmen.']),
                $f('INVOICE_CLOSING', 'Schlusssatz auf Rechnungen', 'text', ['max' => 300]),
                $f('QUOTE_CLOSING', 'Schlusssatz auf Angeboten', 'text', ['max' => 300]),
            ]],
            ['id' => 'billing', 'title' => 'Zahlung und Mahnwesen', 'fields' => [
                $f('PAYMENT_DAYS', 'Zahlungsziel (Tage)', 'int', ['min' => 0, 'max' => 365, 'default' => '14']),
                $f('QUOTE_VALID_DAYS', 'Angebote gültig (Tage)', 'int', ['min' => 1, 'max' => 365, 'default' => '30']),
                $f('REMINDER_DAYS', 'Frist je Mahnung (Tage)', 'int', ['min' => 1, 'max' => 90, 'default' => '7']),
                $f('REMINDER_FEE_1', 'Gebühr Zahlungserinnerung (€)', 'number', ['min' => 0, 'max' => 1000, 'default' => '0']),
                $f('REMINDER_FEE_2', 'Gebühr 1. Mahnung (€)', 'number', ['min' => 0, 'max' => 1000, 'default' => '0']),
                $f('REMINDER_FEE_3', 'Gebühr letzte Mahnung (€)', 'number', ['min' => 0, 'max' => 1000, 'default' => '0']),
            ]],
            ['id' => 'mail', 'title' => 'E-Mail-Versand (SMTP)', 'intro' => 'Zugangsdaten bekommst du von deinem Mail-Anbieter. Ohne diese Angaben können weder Rechnungen versendet noch Kunden-Registrierungen bestätigt werden.', 'fields' => [
                $f('SMTP_HOST', 'SMTP-Server', 'text', ['max' => 200, 'help' => 'z. B. smtp.ionos.de']),
                $f('SMTP_PORT', 'Port', 'int', ['min' => 1, 'max' => 65535, 'default' => '587']),
                $f('SMTP_ENCRYPTION', 'Verschlüsselung', 'select', ['options' => [['tls', 'STARTTLS (Port 587)'], ['ssl', 'SSL/TLS (Port 465)'], ['none', 'keine (nur lokal)']], 'default' => 'tls']),
                $f('SMTP_USER', 'Benutzername', 'text', ['max' => 200]),
                $f('SMTP_PASSWORD', 'Passwort', 'secret', ['max' => 200]),
                $f('MAIL_FROM', 'Absenderadresse', 'email', ['help' => 'Muss zum Mail-Konto passen. Leer = Firmen-E-Mail.']),
                $f('MAIL_FROM_NAME', 'Absendername', 'text', ['max' => 120, 'help' => 'Leer = Firmenname.']),
                $f('MAIL_BCC', 'Kopie jeder Mail an', 'email'),
            ]],
            ['id' => 'portal', 'title' => 'Kundenportal', 'fields' => [
                $f('APP_URL', 'Öffentliche Adresse', 'url', ['help' => 'z. B. https://crm.meine-domain.de – wird für Links in E-Mails verwendet.']),
                $f('PORTAL_REGISTRATION', 'Selbstregistrierung', 'select', ['options' => [['open', 'erlaubt (wenn E-Mail eingerichtet ist)'], ['off', 'abgeschaltet']], 'default' => 'open']),
                $f('TERMS_URL', 'Link zu den AGB', 'url'),
                $f('PRIVACY_URL', 'Link zur Datenschutzerklärung', 'url', ['help' => 'Sind AGB- oder Datenschutz-Link gesetzt, muss der Kunde bei der Registrierung zustimmen.']),
                $f('PORTAL_TOKEN_DAYS', 'Portal-Links gültig (Tage, 0 = unbegrenzt)', 'int', ['min' => 0, 'max' => 3650, 'default' => '365']),
                $f('PORTAL_SESSION_DAYS', 'Anmeldung gültig (Tage)', 'int', ['min' => 1, 'max' => 365, 'default' => '14']),
            ]],
            ['id' => 'support', 'title' => 'Support', 'intro' => 'Kunden erreichen den Support im Kundenportal. Die Zeiten steuern die Fälligkeitsanzeige (SLA); bei „Hoch“ gilt die halbe, bei „Dringend“ ein Viertel der Zeit, bei „Niedrig“ die doppelte.', 'fields' => [
                $f('TICKET_CATEGORIES', 'Kategorien (kommagetrennt)', 'text', ['max' => 400, 'default' => 'Allgemein,Rechnung & Zahlung,Technik,Lizenz,Bestellung']),
                $f('TICKET_FIRST_RESPONSE_HOURS', 'Erste Antwort innerhalb (Stunden)', 'int', ['min' => 1, 'max' => 720, 'default' => '24']),
                $f('TICKET_RESOLVE_HOURS', 'Lösung innerhalb (Stunden)', 'int', ['min' => 1, 'max' => 2160, 'default' => '72']),
                $f('TICKET_AUTOCLOSE_DAYS', 'Gelöste Tickets schließen nach (Tage, 0 = nie)', 'int', ['min' => 0, 'max' => 365, 'default' => '7']),
                $f('TICKET_PENDING_DAYS', '„Wartet auf Kunde“ als gelöst markieren nach (Tage, 0 = nie)', 'int', ['min' => 0, 'max' => 365, 'default' => '14']),
                $f('TICKET_NOTIFY_EMAIL', 'Benachrichtigung über neue Tickets an', 'email', ['help' => 'Leer = Firmen-E-Mail. Der zugewiesene Mitarbeiter bekommt zusätzlich eine Mail.']),
                $f('TICKET_ATTACHMENT_MB', 'Größe eines Anhangs (MB)', 'int', ['min' => 1, 'max' => 50, 'default' => '5', 'help' => 'Zusätzlich begrenzt durch die PHP-Einstellung upload_max_filesize deines Servers.']),
            ]],
            ['id' => 'license', 'title' => 'Lizenzen', 'fields' => [
                $f('LICENSE_GRACE_DAYS', 'Kulanzfrist bei Mietlizenzen (Tage)', 'int', ['min' => 0, 'max' => 365, 'default' => '14']),
                $f('LICENSE_CACHE_HOURS', 'Prüfergebnis zwischenspeichern (Stunden)', 'int', ['min' => 1, 'max' => 720, 'default' => '24']),
                $f('LICENSE_OFFLINE_DAYS', 'Weiterlaufen ohne Server (Tage)', 'int', ['min' => 0, 'max' => 90, 'default' => '7']),
                $f('LICENSE_DOMAIN_CHANGES', 'Domainwechsel durch Kunden (Anzahl)', 'int', ['min' => 0, 'max' => 100, 'default' => '2']),
                $f('LICENSE_ALLOW_DEV', 'Entwicklungsadressen erlauben (localhost, *.test)', 'bool', ['default' => 'true']),
            ]],
            ['id' => 'backup', 'title' => 'Datensicherung', 'fields' => [
                $f('BACKUP_AUTO', 'Automatisch sichern (beim Cron-Lauf)', 'bool', ['default' => 'true']),
                $f('BACKUP_INTERVAL_HOURS', 'Sichern, wenn das letzte älter ist als (Stunden)', 'int', ['min' => 1, 'max' => 720, 'default' => '24']),
                $f('BACKUP_KEEP', 'Neueste Backups behalten', 'int', ['min' => 1, 'max' => 1000, 'default' => '14']),
                $f('BACKUP_KEEP_MONTHS', 'Monatsbackups behalten (Monate)', 'int', ['min' => 0, 'max' => 240, 'default' => '12']),
                $f('BACKUP_PASSPHRASE', 'Passwort für verschlüsselte Backups', 'secret', ['max' => 200, 'help' => 'Dringend empfohlen. Ohne dieses Passwort lässt sich ein Backup nicht wiederherstellen – bitte separat aufbewahren.']),
                $f('BACKUP_COPY_DIR', 'Zweitkopie in Ordner', 'text', ['max' => 300, 'help' => 'Absoluter Pfad, z. B. ein Cloud- oder Netzlaufwerk.']),
            ]],
        ];
    }

    /** @return array<string,array<string,mixed>> Schlüssel → Felddefinition */
    private static function fields(): array
    {
        $out = [];
        foreach (self::schema() as $group) {
            foreach ($group['fields'] as $field) {
                $out[$field['key']] = $field + ['group' => $group['id']];
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> Gruppen mit aktuellen Werten (Geheimnisse nie im Klartext) */
    public static function describe(): array
    {
        $groups = self::schema();
        foreach ($groups as &$group) {
            foreach ($group['fields'] as &$field) {
                $key = $field['key'];
                $raw = Env::get($key);
                $source = Env::source($key);
                $field['source'] = $source;
                $field['locked'] = $source === 'env';
                if ($field['type'] === 'secret') {
                    $field['isSet'] = $raw !== null && $raw !== '';
                    $field['value'] = '';
                } else {
                    $field['value'] = $raw === null ? '' : ($field['type'] === 'lines' ? str_replace('|', "\n", $raw) : $raw);
                }
            }
            unset($field);
        }
        unset($group);
        return $groups;
    }

    /**
     * Speichert Änderungen. Gesperrte Felder (von der Server-Umgebung vorgegeben) werden übersprungen.
     *
     * @param array<string,mixed> $values neue Werte; bei Geheimnissen bedeutet "" = unverändert
     * @param list<string> $reset Felder, deren eigene Einstellung entfernt wird (dann gilt .env bzw. Standard)
     * @return array{saved:list<string>,skipped:list<string>}
     */
    public static function save(array $values, array $reset): array
    {
        $fields = self::fields();
        $changes = [];
        $errors = [];
        $skipped = [];
        foreach ($values as $key => $value) {
            if (!isset($fields[$key])) {
                throw ApiError::badRequest("Unbekannte Einstellung: $key");
            }
            $field = $fields[$key];
            if (Env::source($key) === 'env') {
                $skipped[] = $key;
                continue;
            }
            if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
                $errors[$key] = ['Ungültiger Wert'];
                continue;
            }
            $value = is_bool($value) ? ($value ? 'true' : 'false') : trim((string) $value);
            if ($field['type'] === 'secret' && $value === '') {
                continue; // unverändert lassen
            }
            $problem = self::validate($field, $value);
            if ($problem !== null) {
                $errors[$key] = [$problem];
                continue;
            }
            $changes[$key] = $field['type'] === 'lines' ? implode('|', array_filter(array_map('trim', preg_split('/\r?\n/', $value) ?: []), static fn ($l) => $l !== '')) : $value;
        }
        foreach ($reset as $key) {
            if (!isset($fields[$key])) {
                throw ApiError::badRequest("Unbekannte Einstellung: $key");
            }
            if (Env::source($key) !== 'env') {
                $changes[$key] = null;
            }
        }
        if ($errors !== []) {
            throw ApiError::badRequest('Bitte die markierten Felder prüfen', ['formErrors' => [], 'fieldErrors' => (object) $errors]);
        }
        try {
            Env::saveSettings($changes);
        } catch (\RuntimeException $e) {
            throw new ApiError(500, $e->getMessage());
        }
        return ['saved' => array_keys($changes), 'skipped' => $skipped];
    }

    /** @param array<string,mixed> $field */
    private static function validate(array $field, string $value): ?string
    {
        $type = $field['type'];
        if ($value === '') {
            return null; // leer ist erlaubt (Feld ausblenden/leeren)
        }
        if (!in_array($type, ['int', 'number'], true) && isset($field['max']) && mb_strlen($value) > $field['max']) {
            return 'Höchstens ' . $field['max'] . ' Zeichen';
        }
        switch ($type) {
            case 'int':
            case 'number':
                if (!is_numeric($value) || ($type === 'int' && (string) (int) $value !== ltrim($value, '+'))) {
                    return $type === 'int' ? 'Bitte eine ganze Zahl eingeben' : 'Bitte eine Zahl eingeben';
                }
                if ((float) $value < ($field['min'] ?? -INF) || (float) $value > ($field['max'] ?? INF)) {
                    return 'Erlaubt sind Werte von ' . ($field['min'] ?? '−∞') . ' bis ' . ($field['max'] ?? '∞');
                }
                return null;
            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? null : 'Das ist keine gültige E-Mail-Adresse';
            case 'url':
                return preg_match('#^https?://[^\s/$.?\#][^\s]*$#i', $value) === 1 ? null : 'Bitte eine Adresse mit https:// eingeben';
            case 'bool':
                return in_array($value, ['true', 'false'], true) ? null : 'Ungültiger Wert';
            case 'select':
                return in_array($value, array_column($field['options'], 0), true) ? null : 'Ungültige Auswahl';
            default:
                if (isset($field['pattern']) && !preg_match($field['pattern'], $value)) {
                    return $field['patternError'] ?? 'Ungültiger Wert';
                }
                if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) || ($type === 'text' && preg_match('/[\r\n]/', $value))) {
                    return 'Ungültige Zeichen';
                }
                return null;
        }
    }
}
