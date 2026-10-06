<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\ApiError;
use App\Mail\MailException;
use App\Mail\Mailer;
use App\Support\Activity;
use App\Support\Dates;
use App\Support\Db;
use App\Support\Env;
use App\Support\RateLimit;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Domain-Lizenzen für verkaufte Software.
 *
 * So funktioniert es:
 *  1. Der Kunde bestellt ein Lizenzprodukt und nennt seine Domain. Nach der Annahme (auf Wunsch erst nach Zahlung)
 *     entsteht ein Lizenzschlüssel, der an diese Domain gebunden ist.
 *  2. Die verkaufte Software fragt per HTTPS bei dieser Anwendung an (POST /api/license/verify): „Gilt Schlüssel X auf Domain Y?“
 *  3. Die Antwort ist mit Ed25519 digital signiert. Die Software prüft die Signatur mit dem eingebauten öffentlichen Schlüssel,
 *     den zurückgesendeten Zufallswert (gegen Wiederholung alter Antworten) und die Domain.
 *  4. Mietlizenzen gelten, solange die Abo-Rechnungen bezahlt werden (bezahlt bis + Kulanzfrist).
 *
 * Grenzen: Eine Lizenzprüfung in PHP-Quelltext schützt vor versehentlicher oder bequemer Weitergabe, nicht vor jemandem, der den Code der Software
 * verändert. Wirklich durchsetzen lässt sich die Lizenz nur über Funktionen, die ohne Server nicht laufen (Updates, API, Support).
 */
final class LicenseService
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // ohne 0/O und 1/I, damit sich Schlüssel gut abtippen lassen
    private const KEY_GROUPS = 5;
    private const KEY_GROUP_LENGTH = 5;

    /* ---------- Schlüssel und Domains ---------- */

    /** Zufälliger Schlüssel der Form XXXXX-XXXXX-XXXXX-XXXXX-XXXXX (125 Bit). */
    public static function generateKey(): string
    {
        $groups = [];
        for ($g = 0; $g < self::KEY_GROUPS; $g++) {
            $part = '';
            for ($i = 0; $i < self::KEY_GROUP_LENGTH; $i++) {
                $part .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $groups[] = $part;
        }
        return implode('-', $groups);
    }

    /** Vereinheitlicht eingegebene Schlüssel (Groß-/Kleinschreibung, Leerzeichen, Bindestriche); null, wenn das Format nicht passt. */
    public static function normalizeKey(string $input): ?string
    {
        $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $input));
        $length = self::KEY_GROUPS * self::KEY_GROUP_LENGTH;
        if (strlen($clean) !== $length || strspn($clean, self::ALPHABET) !== $length) {
            return null;
        }
        return implode('-', str_split($clean, self::KEY_GROUP_LENGTH));
    }

    /**
     * Vereinheitlicht eine Domain: Kleinbuchstaben, ohne Protokoll, Pfad, Port und führendes „www.“,
     * Umlaut-Domains in Punycode. Gibt null zurück, wenn es keine gültige Domain ist.
     */
    public static function normalizeDomain(string $input): ?string
    {
        $d = mb_strtolower(trim($input));
        $d = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $d);
        $d = (string) preg_replace('#[/?\#].*$#', '', $d);
        $d = (string) preg_replace('#^[^@]*@#', '', $d);
        $d = (string) preg_replace('#:\d+$#', '', $d);
        $d = rtrim($d, '.');
        if (str_starts_with($d, 'www.')) {
            $d = substr($d, 4);
        }
        if ($d !== '' && preg_match('/[^\x00-\x7F]/', $d)) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($d, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;
            if ($ascii === false || $ascii === '') {
                return null;
            }
            $d = $ascii;
        }
        if ($d === '' || strlen($d) > 253) {
            return null;
        }
        $labels = explode('.', $d);
        foreach ($labels as $label) {
            if (!preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)$/', $label)) {
                return null;
            }
        }
        if (count($labels) < 2 && $d !== 'localhost') {
            return null;
        }
        return $d;
    }

    /** Entwicklungsumgebungen (localhost, *.test, *.local, 127.x) dürfen mit gültigem Schlüssel laufen. */
    public static function isDevDomain(string $domain): bool
    {
        return $domain === 'localhost' || (bool) preg_match('/(\.localhost|\.test|\.local|\.localdomain)$/', $domain) || (bool) preg_match('/^127(\.\d{1,3}){3}$/', $domain);
    }

    public static function domainMatches(string $licensed, bool $subdomains, string $requested): bool
    {
        return $requested === $licensed || ($subdomains && str_ends_with($requested, '.' . $licensed));
    }

    /* ---------- Ausstellen und Verlängern ---------- */

    public static function graceDays(): int
    {
        return max(0, Env::int('LICENSE_GRACE_DAYS', 14));
    }

    /**
     * Legt eine Lizenz an (erzeugt einen eindeutigen Schlüssel).
     *
     * @param array<string,mixed> $data clientId, productName, domain (bereits normalisiert) und optionale Felder der Tabelle License
     */
    public static function issue(array $data): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return Db::insert('License', $data + ['licenseKey' => self::generateKey()]);
            } catch (\PDOException $e) {
                if (!Db::isUniqueViolation($e)) {
                    throw $e;
                }
            }
        }
        throw new RuntimeException('Lizenzschlüssel konnte nicht erzeugt werden');
    }

    /**
     * Stellt die Lizenz zu einer angenommenen Bestellung aus. Läuft innerhalb der Transaktion der Annahme.
     *
     * @param array<string,mixed> $order
     */
    public static function issueForOrder(array $order, ?string $invoiceId, ?string $recurringId, ?string $recurringStart): string
    {
        $rental = $order['productType'] === 'RENTAL';
        $free = ((float) $order['unitPrice'] * (float) $order['quantity']) + ($rental ? (float) $order['setupFee'] : 0.0) <= 0.0;
        $payFirst = (bool) $order['licensePayFirst'] && !$free;

        $data = [
            'clientId' => $order['clientId'],
            'orderId' => $order['id'],
            'productId' => $order['productId'],
            'productName' => $order['productName'],
            'domain' => $order['domain'],
            'subdomains' => (int) $order['licenseSubdomains'],
            'payFirst' => (int) $payFirst,
            'invoiceId' => $rental ? null : $invoiceId,
            'recurringId' => $recurringId,
            'intervalUnit' => $rental ? $order['intervalUnit'] : null,
            'licenseDays' => $rental ? null : $order['licenseDays'],
            'slug' => $order['productId'] ? Db::value('SELECT "licenseSlug" FROM "Product" WHERE "id" = ?', [$order['productId']]) : null,
            'plan' => $order['licensePlan'] ?? null,
            'features' => $order['licenseFeatures'] ?? null,
            'supportDays' => $order['licenseSupportDays'] ?? null,
            'updateDays' => $order['licenseUpdateDays'] ?? null,
            'status' => $payFirst ? 'PENDING' : 'ACTIVE',
        ];
        if ($rental) {
            // Bezahlt-bis beginnt am Starttermin des Abos; jede bezahlte Abo-Rechnung verlängert um einen Zeitraum
            $data['paidThrough'] = $recurringStart;
        }
        if (!$payFirst) {
            $data += self::activationFields($data, null);
        }
        return self::issue($data);
    }

    /**
     * Wird aufgerufen, sobald eine Rechnung vollständig bezahlt ist: schaltet wartende Lizenzen frei und verlängert Mietlizenzen.
     * Jede Rechnung wirkt nur einmal.
     */
    public static function onInvoicePaid(string $invoiceId): void
    {
        $invoice = Db::find('Invoice', $invoiceId);
        if ($invoice === null) {
            return;
        }
        $licenses = Db::all(
            'SELECT * FROM "License" WHERE ("invoiceId" = ?) OR ("recurringId" IS NOT NULL AND "recurringId" = ?)',
            [$invoiceId, $invoice['recurringId'] ?? ''],
        );

        foreach ($licenses as $license) {
            $activated = Db::transaction(static function () use ($license, $invoiceId) {
                if (Db::value('SELECT 1 FROM "LicensePayment" WHERE "licenseId" = ? AND "invoiceId" = ?', [$license['id'], $invoiceId])) {
                    return false;
                }
                Db::insert('LicensePayment', ['licenseId' => $license['id'], 'invoiceId' => $invoiceId]);
                if ($license['status'] === 'REVOKED') {
                    return false;
                }

                $fields = self::activationFields($license, $invoiceId);
                if ($license['status'] === 'PENDING') {
                    $fields['status'] = 'ACTIVE';
                }
                Db::update('License', $license['id'], $fields);
                return $license['status'] === 'PENDING';
            });

            if ($activated) {
                self::sendLicenseMail($license['id']);
            }
        }
    }

    /**
     * Berechnet Aktivierungszeitpunkt und Gültigkeit.
     * Miete: bezahlt-bis (+ ein Zeitraum je bezahlter Rechnung) plus Kulanzfrist. Einmalkauf: ab Freischaltung, ggf. befristet.
     *
     * @param array<string,mixed> $license
     * @return array<string,mixed>
     */
    private static function activationFields(array $license, ?string $paidInvoiceId): array
    {
        $now = Dates::now();
        $fields = ['activatedAt' => $license['activatedAt'] ?? $now];

        if (($license['recurringId'] ?? null) !== null && ($license['intervalUnit'] ?? null) !== null) {
            $paid = $license['paidThrough'] ?? $now;
            if ($paidInvoiceId !== null) {
                $paid = RecurringService::iso(RecurringService::addMonths(
                    (new DateTimeImmutable($paid))->setTimezone(new DateTimeZone('UTC')),
                    RecurringService::MONTHS[$license['intervalUnit']],
                ));
            }
            $fields['paidThrough'] = $paid;
            $fields['validUntil'] = (new DateTimeImmutable($paid))->modify('+' . self::graceDays() . ' days')->format(Dates::FORMAT);
            $fields += Entitlements::dates($license, $fields['activatedAt'], $paid, true); // Support und Updates laufen mit der Miete
        } elseif (($license['validUntil'] ?? null) === null && ($license['licenseDays'] ?? null) !== null && ($license['activatedAt'] ?? null) === null) {
            $fields['validUntil'] = (new DateTimeImmutable($now))->modify('+' . (int) $license['licenseDays'] . ' days')->format(Dates::FORMAT);
        }
        if (($license['recurringId'] ?? null) === null && ($license['activatedAt'] ?? null) === null) {
            $fields += Entitlements::dates($license, $fields['activatedAt'], null, false); // Einmalkauf: Zeiträume beginnen mit der Freischaltung
        }
        return $fields;
    }

    /** Anzeigestatus: aus ACTIVE wird EXPIRED, sobald die Gültigkeit überschritten ist. */
    public static function effectiveStatus(array $license): string
    {
        if ($license['status'] === 'ACTIVE' && $license['validUntil'] !== null && $license['validUntil'] <= Dates::now()) {
            return 'EXPIRED';
        }
        return (string) $license['status'];
    }

    /** @return array<string,mixed> Lizenz mit Kunde, Zahlungen und fehlgeschlagenen Prüfungen (für die Verwaltung) */
    public static function detail(string $id): array
    {
        $l = Db::require('License', $id, 'Lizenz nicht gefunden');
        $l['effectiveStatus'] = self::effectiveStatus($l);
        $l['client'] = Db::one('SELECT "id", "name", "company", "email" FROM "Client" WHERE "id" = ?', [$l['clientId']]);
        $l['order'] = $l['orderId'] ? Db::one('SELECT "id", "number" FROM "ProductOrder" WHERE "id" = ?', [$l['orderId']]) : null;
        $l['invoice'] = $l['invoiceId'] ? Db::one('SELECT "id", "number", "status" FROM "Invoice" WHERE "id" = ?', [$l['invoiceId']]) : null;
        $l['recurring'] = $l['recurringId'] ? Db::one('SELECT "id", "title", "active" FROM "Recurring" WHERE "id" = ?', [$l['recurringId']]) : null;
        $l['payments'] = Db::all(
            'SELECT p."createdAt", i."id" AS invoice__id, i."number" AS invoice__number FROM "LicensePayment" p JOIN "Invoice" i ON i.id = p.invoiceId WHERE p."licenseId" = ? ORDER BY p."createdAt" DESC',
            [$id],
        );
        $l['attempts'] = Db::all('SELECT "domain", "reason", "createdAt" FROM "LicenseAttempt" WHERE "licenseId" = ? ORDER BY "createdAt" DESC LIMIT 20', [$id]);
        $l['hosts'] = Db::all('SELECT "domain", "version", "checks", "firstSeenAt", "lastSeenAt" FROM "LicenseHost" WHERE "licenseId" = ? ORDER BY "lastSeenAt" DESC LIMIT 20', [$id]);
        $l['resolvedFeatures'] = Entitlements::features($l);
        $l['supportActive'] = Entitlements::supportActive($l);
        $l['updatesActive'] = Entitlements::updatesActive($l);
        return $l;
    }

    /** Neue Domain für eine Lizenz; null-Fehler bei ungültiger Domain. */
    public static function changeDomain(string $id, string $domain, bool $byCustomer): void
    {
        $normalized = self::normalizeDomain($domain);
        if ($normalized === null) {
            throw ApiError::badRequest('Das ist keine gültige Domain (Beispiel: meine-seite.de)');
        }
        $l = Db::require('License', $id, 'Lizenz nicht gefunden');
        if ($l['status'] === 'REVOKED') {
            throw ApiError::conflict('Diese Lizenz wurde widerrufen');
        }
        if ($normalized === $l['domain']) {
            return;
        }
        if ($byCustomer) {
            $left = self::changesLeft($l);
            if ($left <= 0) {
                throw ApiError::conflict('Die Domain kann nicht mehr geändert werden. Bitte melde dich bei uns.');
            }
            Db::update('License', $id, ['domain' => $normalized, 'domainChanges' => (int) $l['domainChanges'] + 1]);
            Activity::log('LICENSE_DOMAIN', "Lizenz {$l['productName']}: Domain vom Kunden geändert ({$l['domain']} → $normalized)", $l['clientId'], null, null);
            return;
        }
        Db::update('License', $id, ['domain' => $normalized]);
    }

    public static function changesLeft(array $license): int
    {
        return max(0, Env::int('LICENSE_DOMAIN_CHANGES', 2) - (int) $license['domainChanges']);
    }

    /** Schickt dem Kunden den Lizenzschlüssel (nur wenn die Lizenz aktiv ist und E-Mail eingerichtet ist). */
    public static function sendLicenseMail(string $id): bool
    {
        $license = Db::find('License', $id);
        if ($license === null || !Mailer::configured()) {
            return false;
        }
        $client = Db::find('Client', $license['clientId']);
        $to = $client ? MailTemplates::recipient($client) : '';
        if ($to === '') {
            return false;
        }
        try {
            $mail = MailTemplates::license($client, $license);
            Mailer::send($to, $mail['subject'], $mail['message'] . "\n\n" . MailTemplates::signature());
            return true;
        } catch (MailException) {
            return false;
        }
    }

    /* ---------- Prüfung durch die Software ---------- */

    /**
     * Prüft Schlüssel und Domain und liefert die signierte Antwort.
     *
     * @return array{payload:string,signature:string}
     */
    /**
     * Schlägt Schlüssel und Domain nach und nennt den Ablehnungsgrund (null = gültig).
     *
     * @return array{license:?array<string,mixed>,reason:?string,domain:?string}
     */
    public static function lookup(string $key, string $domain): array
    {
        $normalizedDomain = self::normalizeDomain($domain);
        $normalizedKey = self::normalizeKey($key);
        $license = $normalizedKey !== null ? Db::one('SELECT * FROM "License" WHERE "licenseKey" = ?', [$normalizedKey]) : null;

        $reason = null;
        if ($license === null) {
            $reason = 'unknown';
        } elseif ($normalizedDomain === null) {
            $reason = 'domain';
        } else {
            $status = self::effectiveStatus($license);
            $devOk = Env::bool('LICENSE_ALLOW_DEV', true) && self::isDevDomain($normalizedDomain);
            if ($status === 'REVOKED') {
                $reason = 'revoked';
            } elseif ($status === 'SUSPENDED') {
                $reason = 'suspended';
            } elseif ($status === 'PENDING') {
                $reason = 'pending';
            } elseif ($status === 'EXPIRED') {
                $reason = 'expired';
            } elseif (!$devOk && !self::domainMatches($license['domain'], (bool) $license['subdomains'], $normalizedDomain)) {
                $reason = 'domain';
            }
        }

        return ['license' => $license, 'reason' => $reason, 'domain' => $normalizedDomain];
    }

    /** Zählt die Prüfung pro Lizenz (Schutz vor auffällig häufigen Anfragen). */
    public static function throttle(array $license): void
    {
        if (RateLimit::hit('licid:' . $license['id'], 3600) > max(10, Env::int('LICENSE_MAX_CHECKS_HOUR', 120))) {
            throw new ApiError(429, 'Dieser Lizenzschlüssel wird auffällig oft geprüft – bitte später erneut versuchen.');
        }
    }

    /**
     * Prüft Schlüssel und Domain und liefert die signierte Antwort.
     *
     * @return array{payload:string,signature:string}
     */
    public static function verify(string $key, string $domain, string $nonce, ?string $appVersion = null): array
    {
        ['license' => $license, 'reason' => $reason, 'domain' => $normalizedDomain] = self::lookup($key, $domain);

        if ($license !== null) {
            self::throttle($license);
        }
        if ($license !== null) {
            if ($reason === null) {
                self::noteHost($license['id'], $normalizedDomain ?? '', $appVersion);
                if ($license['lastCheckedAt'] === null || $license['lastCheckedAt'] < gmdate(Dates::FORMAT, time() - 60)) {
                    Db::run('UPDATE "License" SET "lastCheckedAt" = ?, "checkCount" = "checkCount" + 1 WHERE "id" = ?', [Dates::now(), $license['id']]);
                }
            } elseif (in_array($reason, ['domain', 'expired', 'suspended', 'pending'], true)) {
                Db::insert('LicenseAttempt', ['licenseId' => $license['id'], 'domain' => mb_substr($normalizedDomain ?? mb_strtolower(trim($domain)), 0, 255), 'reason' => $reason]);
                if (random_int(1, 100) === 1) {
                    Db::run('DELETE FROM "LicenseAttempt" WHERE "createdAt" < ?', [gmdate(Dates::FORMAT, time() - 180 * 86400)]);
                }
            }
        }

        $ok = $reason === null && $license !== null;
        return self::sign([
            'v' => 2,
            'kid' => self::keyId(),
            'valid' => $reason === null,
            'reason' => $reason,
            'product' => $ok ? $license['productName'] : null,
            'domain' => $normalizedDomain,
            'slug' => $ok ? $license['slug'] : null,
            'plan' => $ok ? $license['plan'] : null,
            'features' => $ok ? Entitlements::features($license) : [],
            'supportUntil' => $ok ? $license['supportUntil'] : null,
            'updatesUntil' => $ok ? $license['updatesUntil'] : null,
            'expiresAt' => $ok ? $license['validUntil'] : null,
            'issuedAt' => Dates::now(),
            'nonce' => mb_substr($nonce, 0, 128),
            'cacheHours' => max(1, Env::int('LICENSE_CACHE_HOURS', 24)),
            'graceDays' => max(0, Env::int('LICENSE_OFFLINE_DAYS', 7)),
        ]);
    }

    /** Merkt sich, auf welcher Domain ein Schlüssel benutzt wird (Hinweis auf Weitergabe). */
    public static function noteHost(string $licenseId, string $domain, ?string $version): void
    {
        if ($domain === '') {
            return;
        }
        $version = $version !== null ? mb_substr(preg_replace('/[^0-9A-Za-z._+-]/', '', $version) ?? '', 0, 30) : null;
        $row = Db::one('SELECT "id" FROM "LicenseHost" WHERE "licenseId" = ? AND "domain" = ?', [$licenseId, $domain]);
        if ($row === null) {
            Db::insert('LicenseHost', ['licenseId' => $licenseId, 'domain' => mb_substr($domain, 0, 255), 'version' => $version, 'lastSeenAt' => Dates::now(), 'firstSeenAt' => Dates::now()]);
        } else {
            Db::run('UPDATE "LicenseHost" SET "checks" = "checks" + 1, "lastSeenAt" = ?, "version" = COALESCE(?, "version") WHERE "id" = ?', [Dates::now(), $version, $row['id']]);
        }
    }

    /** Nachrichtenauthentifizierung (HMAC) mit einem aus dem Signaturschlüssel abgeleiteten Geheimnis, z. B. für Download-Tokens. */
    public static function mac(string $data): string
    {
        return self::b64(hash_hmac('sha256', $data, hash('sha256', self::secretKey() . '|mac', true), true));
    }

    /** Signiert beliebige Daten (Base64URL), z. B. die Prüfsumme eines Release-Pakets. */
    public static function signRaw(string $data): string
    {
        return self::b64(sodium_crypto_sign_detached($data, self::secretKey()));
    }

    /** Kurzkennung des Signaturschlüssels (für Schlüsselwechsel in der Software). */
    public static function keyId(): string
    {
        return substr(hash('sha256', (string) base64_decode(self::publicKey())), 0, 12);
    }

    /** @param array<string,mixed> $payload @return array{payload:string,signature:string} */
    public static function sign(array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = sodium_crypto_sign_detached($json, self::secretKey());
        return ['payload' => self::b64($json), 'signature' => self::b64($signature)];
    }

    /* ---------- Signaturschlüssel ---------- */

    public static function keyFile(): string
    {
        return Env::get('LICENSE_KEY_FILE', '') ?: APP_ROOT . '/database/license.key';
    }

    /** Öffentlicher Schlüssel (Base64) für die Prüfklasse in der verkauften Software. */
    public static function publicKey(): string
    {
        return base64_encode(sodium_crypto_sign_publickey_from_secretkey(self::secretKey()));
    }

    /**
     * Geheimer Signaturschlüssel: aus LICENSE_SECRET_KEY (Base64), sonst aus der Schlüsseldatei.
     * Die Datei wird beim ersten Bedarf erzeugt. Geht der Schlüssel verloren, lassen sich bereits ausgelieferte
     * Software-Versionen nicht mehr überprüfen – er ist deshalb Teil der Backups.
     */
    private static function secretKey(): string
    {
        $fromEnv = trim(Env::get('LICENSE_SECRET_KEY', '') ?? '');
        if ($fromEnv !== '') {
            $key = base64_decode($fromEnv, true);
            if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                throw new RuntimeException('LICENSE_SECRET_KEY ist ungültig (erwartet: Base64 eines Ed25519-Schlüssels, 64 Byte)');
            }
            return $key;
        }

        $file = self::keyFile();
        if (!is_file($file)) {
            $dir = dirname($file);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Ordner für den Lizenzschlüssel konnte nicht angelegt werden');
            }
            $handle = @fopen($file, 'x'); // „x“: nur anlegen, wenn es die Datei noch nicht gibt (kein Überschreiben bei gleichzeitigem Start)
            if ($handle !== false) {
                fwrite($handle, base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
                fclose($handle);
                @chmod($file, 0600);
            }
        }
        $key = base64_decode(trim((string) file_get_contents($file)), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Der Lizenzschlüssel in ' . $file . ' ist beschädigt');
        }
        return $key;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
