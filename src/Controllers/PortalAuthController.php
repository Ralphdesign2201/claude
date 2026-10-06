<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Pdf\DocumentPdf;
use App\Services\AccountService;
use App\Services\PortalService;
use App\Support\Db;
use App\Support\Env;
use App\Support\RateLimit;
use App\Support\Validator;

/** Öffentliche Konto-Funktionen des Kundenportals: Registrierung, Anmeldung, Passwort. */
final class PortalAuthController
{
    private const HOUR = 3600;
    private const QUARTER = 900;

    /** Request-Body mit getrimmter E-Mail-Adresse (Leerzeichen beim Eintippen oder Einfügen sind harmlos). */
    private static function body(Request $r): mixed
    {
        $body = $r->body();
        if (is_array($body) && isset($body['email']) && is_string($body['email'])) {
            $body['email'] = trim($body['email']);
        }
        return $body;
    }

    /** Was die Anmeldeseite wissen muss (öffentlich, enthält nichts Vertrauliches). */
    public static function config(Request $r): Response
    {
        return Response::json([
            'registration' => AccountService::registrationOpen(),
            'company' => DocumentPdf::company()['name'],
            'termsUrl' => self::url('TERMS_URL'),
            'privacyUrl' => self::url('PRIVACY_URL'),
            'minPassword' => AccountService::MIN_PASSWORD,
        ]);
    }

    public static function register(Request $r): Response
    {
        if (!AccountService::registrationOpen()) {
            throw new ApiError(503, 'Die Registrierung ist derzeit nicht verfügbar. Bitte melde dich bei uns.');
        }
        $data = Validator::validate(self::body($r), [
            'name' => ['required' => true, 'min' => 2, 'max' => 200],
            'company' => ['max' => 200],
            'email' => ['required' => true, 'email' => true, 'max' => 255],
            'terms' => ['type' => 'bool'],
            'website' => ['max' => 500], // Falle für Bots: echte Nutzer sehen das Feld nicht
        ]);
        $needsTerms = self::url('TERMS_URL') !== null || self::url('PRIVACY_URL') !== null;
        if ($needsTerms && empty($data['terms'])) {
            throw ApiError::badRequest('Bitte bestätige die Datenschutzerklärung bzw. die AGB');
        }

        // Erst nach gültiger Eingabe zählen, damit Tippfehler das Kontingent nicht aufbrauchen
        self::limit('reg:' . $r->ip(), max(1, Env::int('REGISTER_RATE_LIMIT_MAX', 10)), self::HOUR, 'Zu viele Registrierungen von dieser Adresse – bitte später erneut versuchen');
        if (($data['website'] ?? '') === '') {
            self::limit('regmail:' . hash('sha256', AccountService::normalizeEmail($data['email'])), 3, self::HOUR, 'Für diese Adresse wurden zu viele E-Mails verschickt – bitte später erneut versuchen');
            AccountService::register(trim($data['name']), trim((string) ($data['company'] ?? '')), $data['email'], !empty($data['terms']));
        }

        return Response::json(['ok' => true, 'message' => 'Fast geschafft: Wir haben dir eine E-Mail geschickt. Bitte klicke auf den Link darin, um deine Adresse zu bestätigen und dein Passwort festzulegen.'], 202);
    }

    /** Prüft einen Bestätigungslink und liefert die Daten zum Vorbelegen (ändert nichts). */
    public static function verifyInfo(Request $r): Response
    {
        $data = Validator::validate($r->body(), ['token' => ['required' => true, 'min' => 20, 'max' => 128]]);
        self::limit('verify:' . $r->ip(), 30, self::QUARTER, 'Zu viele Versuche – bitte später erneut versuchen');
        return Response::json(AccountService::peekVerify($data['token']));
    }

    public static function verify(Request $r): Response
    {
        $data = Validator::validate($r->body(), [
            'token' => ['required' => true, 'min' => 20, 'max' => 128],
            'password' => ['required' => true, 'min' => AccountService::MIN_PASSWORD, 'max' => 72],
            'name' => ['min' => 2, 'max' => 200],
        ]);
        self::limit('verify:' . $r->ip(), 30, self::QUARTER, 'Zu viele Versuche – bitte später erneut versuchen');
        return Response::json(AccountService::verify($data['token'], $data['password'], trim((string) ($data['name'] ?? ''))));
    }

    public static function login(Request $r): Response
    {
        $data = Validator::validate(self::body($r), [
            'email' => ['required' => true, 'email' => true, 'max' => 255],
            'password' => ['required' => true, 'min' => 1, 'max' => 1000],
        ]);
        $ip = 'plogin:' . $r->ip();
        $acct = 'plogin-acct:' . hash('sha256', AccountService::normalizeEmail($data['email']));
        $max = Env::int('LOGIN_RATE_LIMIT_MAX', 10);
        if (RateLimit::count($ip, self::QUARTER) >= $max * 3 || RateLimit::count($acct, self::QUARTER) >= $max) {
            throw new ApiError(429, 'Zu viele fehlgeschlagene Anmeldeversuche – bitte später erneut versuchen');
        }
        try {
            $session = AccountService::login($data['email'], $data['password']);
        } catch (ApiError $e) {
            if ($e->status === 401) {
                RateLimit::hit($ip, self::QUARTER);
                RateLimit::hit($acct, self::QUARTER);
            }
            throw $e;
        }
        RateLimit::clear($acct);
        return Response::json($session);
    }

    public static function logout(Request $r): Response
    {
        $auth = PortalService::authenticateFull($r);
        if ($auth['token']['kind'] === 'SESSION') {
            AccountService::logout($auth['token']['id']);
        }
        return Response::noContent();
    }

    public static function forgot(Request $r): Response
    {
        $data = Validator::validate(self::body($r), ['email' => ['required' => true, 'email' => true, 'max' => 255]]);
        self::limit('forgot:' . $r->ip(), 5, self::HOUR, 'Zu viele Anfragen – bitte später erneut versuchen');
        self::limit('forgotmail:' . hash('sha256', AccountService::normalizeEmail($data['email'])), 3, self::HOUR, 'Für diese Adresse wurden zu viele E-Mails verschickt – bitte später erneut versuchen');
        if (AccountService::registrationOpen() || \App\Mail\Mailer::configured()) {
            AccountService::forgot($data['email']);
        }
        return Response::json(['ok' => true, 'message' => 'Falls es ein Konto mit dieser Adresse gibt, haben wir dir eine E-Mail mit einem Link geschickt.'], 202);
    }

    public static function reset(Request $r): Response
    {
        $data = Validator::validate($r->body(), [
            'token' => ['required' => true, 'min' => 20, 'max' => 128],
            'password' => ['required' => true, 'min' => AccountService::MIN_PASSWORD, 'max' => 72],
        ]);
        self::limit('reset:' . $r->ip(), 30, self::QUARTER, 'Zu viele Versuche – bitte später erneut versuchen');
        AccountService::reset($data['token'], $data['password']);
        return Response::json(['ok' => true]);
    }

    public static function changePassword(Request $r): Response
    {
        $auth = PortalService::authenticateFull($r);
        if ($auth['account'] === null) {
            throw ApiError::forbidden('Das Passwort kann nur nach einer Anmeldung mit Konto geändert werden');
        }
        $data = Validator::validate($r->body(), [
            'current' => ['required' => true, 'min' => 1, 'max' => 1000],
            'password' => ['required' => true, 'min' => AccountService::MIN_PASSWORD, 'max' => 72],
        ]);
        AccountService::changePassword($auth['account'], $auth['token']['id'], $data['current'], $data['password']);
        return Response::json(['ok' => true]);
    }

    /** Konten verwalten (Mitarbeiter): sperren/entsperren, löschen, Zurücksetzen-Link senden. */
    public static function setActive(Request $r): Response
    {
        $account = Db::require('PortalAccount', $r->param('id'), 'Konto nicht gefunden');
        $data = Validator::validate($r->body(), ['active' => ['required' => true, 'type' => 'bool']]);
        Db::update('PortalAccount', $account['id'], ['active' => (int) $data['active']]);
        if (!$data['active']) {
            Db::run('UPDATE "PortalToken" SET "revokedAt" = ? WHERE "accountId" = ? AND "revokedAt" IS NULL', [\App\Support\Dates::now(), $account['id']]);
        }
        return Response::json(['ok' => true]);
    }

    public static function deleteAccount(Request $r): Response
    {
        Db::delete('PortalAccount', $r->param('id'), 'Konto nicht gefunden');
        return Response::noContent();
    }

    public static function sendReset(Request $r): Response
    {
        $account = Db::require('PortalAccount', $r->param('id'), 'Konto nicht gefunden');
        if (!\App\Mail\Mailer::configured()) {
            throw new ApiError(503, 'E-Mail-Versand ist nicht eingerichtet');
        }
        AccountService::forgot($account['email']);
        return Response::json(['ok' => true]);
    }

    /** Zählt den Versuch und bricht ab, wenn das Limit im Zeitfenster überschritten ist. */
    private static function limit(string $key, int $max, int $window, string $message): void
    {
        if (RateLimit::hit($key, $window) > $max) {
            throw new ApiError(429, $message);
        }
    }

    private static function url(string $name): ?string
    {
        $value = trim(Env::get($name, '') ?? '');
        return preg_match('#^https?://#i', $value) ? $value : null;
    }
}
