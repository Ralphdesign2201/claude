<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Mail\Mailer;
use App\Pdf\DocumentPdf;
use App\Http\ApiError;
use App\Mail\MailException;
use App\Services\DatabaseSwitch;
use App\Services\SettingsService;
use App\Support\Db;
use App\Support\Env;
use App\Support\Validator;

/** Nicht-geheime Einstellungen, damit die Oberfläche fehlende Konfiguration erklären kann. */
final class SettingsController
{
    public static function show(Request $r): Response
    {
        $co = DocumentPdf::company();
        return Response::json([
            'pendingOrders' => (int) Db::value('SELECT COUNT(*) FROM "ProductOrder" WHERE "status" = \'PENDING\''),
            'mailConfigured' => Mailer::configured(),
            'mailDriver' => Mailer::driver(),
            'mailFrom' => Mailer::fromAddress(),
            'company' => [
                'name' => $co['name'],
                'configured' => $co['name'] !== 'Ihr Firmenname' && $co['name'] !== 'Ihr Studio' && $co['iban'] !== '',
            ],
        ]);
    }

    /** Alle Einstellungen für die Einstellungsseite (nur Admin). */
    public static function all(Request $r): Response
    {
        return Response::json([
            'groups' => SettingsService::describe(),
            'mail' => ['configured' => Mailer::configured(), 'from' => Mailer::fromAddress()],
            'database' => DatabaseSwitch::status(),
            'file' => str_starts_with(Env::settingsPath(), APP_ROOT . '/') ? substr(Env::settingsPath(), strlen(APP_ROOT) + 1) : Env::settingsPath(),
        ]);
    }

    public static function save(Request $r): Response
    {
        $body = $r->body();
        $values = is_array($body) && is_array($body['values'] ?? null) && !array_is_list($body['values']) ? $body['values'] : [];
        $reset = is_array($body) && is_array($body['reset'] ?? null) ? array_values(array_filter($body['reset'], 'is_string')) : [];
        $result = SettingsService::save($values, $reset);

        return self::all($r)->withHeader('X-Saved', (string) count($result['saved']));
    }

    /** Schickt eine Testnachricht mit den aktuell gespeicherten Mail-Einstellungen. */
    public static function testMail(Request $r): Response
    {
        $data = Validator::validate($r->body() ?: [], ['to' => ['email' => true, 'max' => 255, 'emptyOk' => true]], partial: true);
        $to = $data['to'] ?? $r->user['email'];
        if (!Mailer::configured()) {
            throw new ApiError(503, 'E-Mail ist noch nicht eingerichtet: Bitte SMTP-Server und Absenderadresse speichern.');
        }
        try {
            Mailer::send($to, 'Testnachricht aus deinem CRM', "Hallo,\n\ndiese Nachricht zeigt, dass der E-Mail-Versand funktioniert.\n\nViele Grüße\nDein CRM");
        } catch (MailException $e) {
            throw new ApiError(502, $e->getMessage());
        }

        return Response::json(['sent' => true, 'to' => $to]);
    }

    public static function databaseTest(Request $r): Response
    {
        $data = $r->body();
        $target = is_array($data) ? (string) ($data['target'] ?? '') : '';

        return Response::json(DatabaseSwitch::test($target, is_array($data) ? $data : []));
    }

    public static function databaseSwitch(Request $r): Response
    {
        $data = $r->body();
        $data = is_array($data) ? $data : [];
        if (($data['confirm'] ?? '') !== 'WECHSELN') {
            throw ApiError::badRequest('Bitte den Wechsel ausdrücklich bestätigen.');
        }
        $result = DatabaseSwitch::switchTo((string) ($data['target'] ?? ''), $data, (bool) ($data['overwrite'] ?? false));

        return Response::json($result);
    }
}
