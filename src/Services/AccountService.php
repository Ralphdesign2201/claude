<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\ApiError;
use App\Mail\MailException;
use App\Mail\Mailer;
use App\Pdf\DocumentPdf;
use App\Support\Activity;
use App\Support\Dates;
use App\Support\Db;
use App\Support\Env;
use DateTimeImmutable;

/**
 * Kundenkonten: Registrierung mit E-Mail-Bestätigung, Anmeldung, Passwort vergessen.
 *
 * Ablauf: Name und E-Mail eintragen → Link per E-Mail → über den Link das Passwort festlegen. Das Passwort wird bewusst
 * erst nach dem Bestätigen gewählt: So kann niemand mit der Adresse eines anderen ein Konto samt bekanntem Passwort
 * „vorregistrieren“. Erst dann wird der Kunde angelegt (oder ein bestehender Kunde mit derselben E-Mail-Adresse
 * verknüpft), damit Spam das CRM nicht füllt.
 */
final class AccountService
{
    public const MIN_PASSWORD = 10;
    private const VERIFY_HOURS = 48;
    private const RESET_HOURS = 2;

    public static function registrationOpen(): bool
    {
        return strtolower(Env::get('PORTAL_REGISTRATION', 'open') ?? 'open') !== 'off' && Mailer::configured();
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Legt ein Konto an und verschickt den Bestätigungslink. Antwortet für Außenstehende immer gleich,
     * damit niemand herausfinden kann, welche Adressen bereits registriert sind.
     */
    public static function register(string $name, string $company, string $email, bool $termsAccepted): void
    {
        $email = self::normalizeEmail($email);
        $existing = Db::one('SELECT * FROM "PortalAccount" WHERE "email" = ?', [$email]);

        if ($existing !== null) {
            if ($existing['verifiedAt'] !== null) {
                self::mail($email, MailTemplates::accountExists($existing['name'], PortalService::baseUrl() . '/portal'));
                return;
            }
            // Noch nicht bestätigt: neuen Link senden (der alte wird ungültig)
            self::mail($email, MailTemplates::accountVerify($existing['name'], self::issueVerifyLink($existing['id'])));
            return;
        }

        $id = Db::insert('PortalAccount', [
            'email' => $email,
            'name' => $name,
            'company' => $company !== '' ? $company : null,
            'passwordHash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT), // unbrauchbar, bis der Kunde sein Passwort festlegt
            'termsAcceptedAt' => $termsAccepted ? Dates::now() : null,
        ]);
        self::mail($email, MailTemplates::accountVerify($name, self::issueVerifyLink($id)));
    }

    /** Daten zum Vorbelegen der Seite „Passwort festlegen“ (ändert nichts). @return array{name:string,company:string,email:string} */
    public static function peekVerify(string $token): array
    {
        $account = self::findByVerifyToken($token);
        return ['name' => $account['name'], 'company' => (string) $account['company'], 'email' => $account['email']];
    }

    /**
     * Bestätigt die E-Mail-Adresse, setzt das gewählte Passwort, legt den Kunden an bzw. verknüpft ihn und meldet das Konto an.
     *
     * @return array{token:string,expiresAt:string,name:string}
     */
    public static function verify(string $token, string $password, string $name = ''): array
    {
        $account = self::findByVerifyToken($token);
        if (strcasecmp($password, $account['email']) === 0) {
            throw ApiError::badRequest('Das Passwort darf nicht gleich der E-Mail-Adresse sein');
        }
        if ($name !== '') {
            $account['name'] = $name;
        }

        $linked = false;
        Db::transaction(static function () use ($account, $password, &$linked) {
            $client = Db::one('SELECT "id" FROM "Client" WHERE LOWER("email") = ? ORDER BY "createdAt" ASC LIMIT 1', [$account['email']]);
            if ($client !== null) {
                $clientId = $client['id'];
                $linked = true;
            } else {
                $clientId = Db::insert('Client', [
                    'name' => $account['name'],
                    'company' => $account['company'],
                    'email' => $account['email'],
                    'status' => 'LEAD',
                    'source' => 'Portal-Registrierung',
                ]);
            }
            Db::update('PortalAccount', $account['id'], [
                'clientId' => $clientId, 'name' => $account['name'], 'passwordHash' => password_hash($password, PASSWORD_BCRYPT),
                'verifiedAt' => Dates::now(), 'verifyTokenHash' => null, 'verifyExpiresAt' => null,
            ]);
            Activity::log('PORTAL_REGISTERED', 'Registrierung im Kundenportal (' . $account['email'] . ')' . ($linked ? ', mit bestehendem Kunden verknüpft' : ', neuer Kunde angelegt'), $clientId, null, null);
        });

        $notify = DocumentPdf::company()['email'];
        if ($notify !== '') {
            self::mail($notify, MailTemplates::accountNotify($account['name'], (string) $account['company'], $account['email'], $linked));
        }

        return self::startSession(Db::require('PortalAccount', $account['id'], 'Konto nicht gefunden'));
    }

    /** @return array<string,mixed> */
    private static function findByVerifyToken(string $token): array
    {
        $account = Db::one('SELECT * FROM "PortalAccount" WHERE "verifyTokenHash" = ?', [hash('sha256', $token)]);
        if ($account === null || $account['verifyExpiresAt'] === null || $account['verifyExpiresAt'] <= Dates::now()) {
            throw ApiError::badRequest('Der Bestätigungslink ist ungültig oder abgelaufen. Bitte registriere dich erneut, dann senden wir einen neuen.');
        }
        return $account;
    }

    /** @return array{token:string,expiresAt:string,name:string} */
    public static function login(string $email, string $password): array
    {
        $login = self::normalizeEmail($email);
        $account = Db::one('SELECT * FROM "PortalAccount" WHERE "email" = ? OR "username" = ?', [$login, $login]);
        // Auch bei unbekannter Adresse einen Hash prüfen, damit die Antwortzeit nichts verrät
        $hash = $account['passwordHash'] ?? '$2y$10$usesomesillystringforsaltuYmQ7vTn0g6L1a8kQ1k0j0dQn3rJ6a';
        $ok = password_verify($password, $hash) && $account !== null;
        if (!$ok) {
            throw ApiError::unauthorized('Benutzername/E-Mail oder Passwort falsch');
        }
        if ($account['verifiedAt'] === null) {
            throw ApiError::forbidden('Bitte bestätige zuerst deine E-Mail-Adresse (Link in der Bestätigungs-E-Mail).');
        }
        if (!$account['active'] || $account['clientId'] === null) {
            throw ApiError::forbidden('Dieses Konto ist gesperrt. Bitte melde dich bei uns.');
        }
        Db::run('UPDATE "PortalAccount" SET "lastLoginAt" = ? WHERE "id" = ?', [Dates::now(), $account['id']]);
        return self::startSession($account);
    }

    /** Sendet einen Link zum Zurücksetzen (nur wenn es das Konto gibt; die Antwort verrät das nicht). */
    public static function forgot(string $email): void
    {
        $account = Db::one('SELECT * FROM "PortalAccount" WHERE "email" = ?', [self::normalizeEmail($email)]);
        if ($account === null || !$account['active']) {
            return;
        }
        if ($account['verifiedAt'] === null) {
            self::mail($account['email'], MailTemplates::accountVerify($account['name'], self::issueVerifyLink($account['id'])));
            return;
        }
        $token = self::randomToken();
        Db::update('PortalAccount', $account['id'], [
            'resetTokenHash' => hash('sha256', $token),
            'resetExpiresAt' => (new DateTimeImmutable('now'))->modify('+' . self::RESET_HOURS . ' hours')->format(Dates::FORMAT),
        ]);
        self::mail($account['email'], MailTemplates::accountReset($account['name'], PortalService::baseUrl() . '/portal#reset=' . $token));
    }

    public static function reset(string $token, string $password): void
    {
        $account = Db::one('SELECT * FROM "PortalAccount" WHERE "resetTokenHash" = ?', [hash('sha256', $token)]);
        if ($account === null || $account['resetExpiresAt'] === null || $account['resetExpiresAt'] <= Dates::now()) {
            throw ApiError::badRequest('Der Link ist ungültig oder abgelaufen. Bitte fordere einen neuen an.');
        }
        Db::transaction(static function () use ($account, $password) {
            Db::update('PortalAccount', $account['id'], [
                'passwordHash' => password_hash($password, PASSWORD_BCRYPT), 'resetTokenHash' => null, 'resetExpiresAt' => null,
            ]);
            // Nach einem Zurücksetzen sind alle bisherigen Anmeldungen ungültig
            Db::run('UPDATE "PortalToken" SET "revokedAt" = ? WHERE "accountId" = ? AND "revokedAt" IS NULL', [Dates::now(), $account['id']]);
        });
    }

    /** Ändert das Passwort; alle anderen Anmeldungen des Kontos werden beendet. */
    public static function changePassword(array $account, string $currentTokenId, string $current, string $new): void
    {
        if (!password_verify($current, $account['passwordHash'])) {
            throw ApiError::badRequest('Das aktuelle Passwort ist nicht richtig');
        }
        Db::transaction(static function () use ($account, $currentTokenId, $new) {
            Db::update('PortalAccount', $account['id'], ['passwordHash' => password_hash($new, PASSWORD_BCRYPT)]);
            Db::run('UPDATE "PortalToken" SET "revokedAt" = ? WHERE "accountId" = ? AND "id" != ? AND "revokedAt" IS NULL', [Dates::now(), $account['id'], $currentTokenId]);
        });
    }

    public static function logout(string $tokenId): void
    {
        Db::run('UPDATE "PortalToken" SET "revokedAt" = ? WHERE "id" = ?', [Dates::now(), $tokenId]);
    }

    /** @return array{token:string,expiresAt:string,name:string} */
    private static function startSession(array $account): array
    {
        $token = self::randomToken();
        $days = max(1, Env::int('PORTAL_SESSION_DAYS', 14));
        $expires = (new DateTimeImmutable('now'))->modify("+$days days")->format(Dates::FORMAT);
        Db::insert('PortalToken', [
            'clientId' => $account['clientId'], 'accountId' => $account['id'], 'kind' => 'SESSION',
            'tokenHash' => hash('sha256', $token), 'expiresAt' => $expires,
        ]);
        return ['token' => $token, 'expiresAt' => $expires, 'name' => $account['name']];
    }

    private static function issueVerifyLink(string $accountId): string
    {
        $token = self::randomToken();
        Db::update('PortalAccount', $accountId, [
            'verifyTokenHash' => hash('sha256', $token),
            'verifyExpiresAt' => (new DateTimeImmutable('now'))->modify('+' . self::VERIFY_HOURS . ' hours')->format(Dates::FORMAT),
        ]);
        return PortalService::baseUrl() . '/portal#verify=' . $token;
    }

    private static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** @param array{subject:string,message:string} $mail */
    private static function mail(string $to, array $mail): void
    {
        if (!Mailer::configured()) {
            return;
        }
        try {
            Mailer::send($to, $mail['subject'], $mail['message'] . "\n\n" . MailTemplates::signature());
        } catch (MailException) {
            // Der Versandfehler soll nicht verraten, ob die Adresse bekannt ist; Details stehen im Server-Protokoll.
            error_log('Kontomail an ' . $to . ' konnte nicht gesendet werden');
        }
    }
}
