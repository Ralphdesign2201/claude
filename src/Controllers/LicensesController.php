<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Services\LicenseService;
use App\Support\Activity;
use App\Support\Dates;
use App\Support\Db;
use App\Support\Pagination;
use App\Support\Validator;
use App\Support\Where;

/** Lizenzen verwalten (Mitarbeiter): ansehen, manuell ausstellen, Domain/Status/Gültigkeit ändern, Schlüssel erneuern. */
final class LicensesController
{
    private const ISSUE_SCHEMA = [
        'clientId' => ['required' => true, 'min' => 1],
        'productName' => ['required' => true, 'min' => 1, 'max' => 255],
        'domain' => ['required' => true, 'min' => 1, 'max' => 300],
        'subdomains' => ['type' => 'bool'],
        'validUntil' => ['type' => 'datetime', 'emptyOk' => true],
        'note' => ['max' => 2000],
    ];

    private const UPDATE_SCHEMA = [
        'domain' => ['min' => 1, 'max' => 300],
        'subdomains' => ['type' => 'bool'],
        'status' => ['enum' => ['ACTIVE', 'SUSPENDED', 'REVOKED']],
        'validUntil' => ['type' => 'datetime', 'emptyOk' => true],
        'note' => ['max' => 2000],
    ];

    public static function index(Request $r): Response
    {
        $p = Pagination::from($r);
        $where = (new Where())
            ->eq('l.clientId', $r->q('clientId'))
            ->search(['l.licenseKey', 'l.domain', 'l.productName'], $r->q('search'));
        $status = $r->q('status');
        if ($status === 'EXPIRED') {
            $where->raw('l."status" = \'ACTIVE\' AND l."validUntil" IS NOT NULL AND l."validUntil" <= ?', [Dates::now()]);
        } elseif ($status === 'ACTIVE') {
            $where->raw('l."status" = \'ACTIVE\' AND (l."validUntil" IS NULL OR l."validUntil" > ?)', [Dates::now()]);
        } elseif ($status !== null) {
            $where->eq('l.status', $status);
        }

        $rows = Db::all(
            'SELECT l.*, c.id AS client__id, c.name AS client__name, c.company AS client__company
             FROM "License" l JOIN "Client" c ON c.id = l.clientId'
            . $where->sql() . ' ORDER BY l."createdAt" DESC LIMIT ? OFFSET ?',
            [...$where->params(), $p['pageSize'], $p['offset']],
        );
        $total = (int) Db::value('SELECT COUNT(*) FROM "License" l' . $where->sql(), $where->params());
        $rows = array_map(static fn ($l) => $l + ['effectiveStatus' => LicenseService::effectiveStatus($l)], $rows);

        return Response::json(Pagination::wrap($rows, $total, $p['page'], $p['pageSize']));
    }

    public static function show(Request $r): Response
    {
        return Response::json(LicenseService::detail($r->param('id')));
    }

    /** Lizenz ohne Bestellung ausstellen (z. B. für einen Altkunden oder als Geschenk). */
    public static function create(Request $r): Response
    {
        $data = Validator::validate($r->body(), self::ISSUE_SCHEMA);
        Db::require('Client', $data['clientId'], 'Kunde nicht gefunden');
        $domain = LicenseService::normalizeDomain($data['domain']) ?? throw ApiError::badRequest('Das ist keine gültige Domain (Beispiel: meine-seite.de)');

        $id = LicenseService::issue([
            'clientId' => $data['clientId'],
            'productName' => $data['productName'],
            'domain' => $domain,
            'subdomains' => (int) ($data['subdomains'] ?? false),
            'payFirst' => 0,
            'status' => 'ACTIVE',
            'activatedAt' => Dates::now(),
            'validUntil' => $data['validUntil'] ?? null,
            'note' => $data['note'] ?? null,
        ]);
        Activity::log('LICENSE_ISSUED', "Lizenz für {$data['productName']} ($domain) ausgestellt", $data['clientId'], null, $r->user['id']);

        return Response::json(LicenseService::detail($id), 201);
    }

    public static function update(Request $r): Response
    {
        $id = $r->param('id');
        $license = Db::require('License', $id, 'Lizenz nicht gefunden');
        $data = Validator::validate($r->body(), self::UPDATE_SCHEMA, partial: true);

        if (isset($data['domain'])) {
            LicenseService::changeDomain($id, $data['domain'], false);
            unset($data['domain']);
        }
        if (isset($data['status']) && $data['status'] === 'ACTIVE' && $license['activatedAt'] === null) {
            $data['activatedAt'] = Dates::now(); // manuell freigeschaltet (z. B. Zahlung auf anderem Weg erhalten)
        }
        if ($data !== []) {
            Db::update('License', $id, array_map(static fn ($v) => is_bool($v) ? (int) $v : $v, $data), 'Lizenz nicht gefunden');
        }
        if (isset($data['status'])) {
            Activity::log('LICENSE_STATUS', "Lizenz {$license['productName']} ({$license['domain']}): Status " . $data['status'], $license['clientId'], null, $r->user['id']);
        }
        if (isset($data['status']) && $data['status'] === 'ACTIVE' && $license['status'] === 'PENDING') {
            LicenseService::sendLicenseMail($id);
        }

        return Response::json(LicenseService::detail($id));
    }

    /** Erzeugt einen neuen Schlüssel (der alte wird sofort ungültig), z. B. wenn er weitergegeben wurde. */
    public static function regenerate(Request $r): Response
    {
        $id = $r->param('id');
        $license = Db::require('License', $id, 'Lizenz nicht gefunden');
        for ($i = 0; $i < 5; $i++) {
            try {
                Db::update('License', $id, ['licenseKey' => LicenseService::generateKey()]);
                break;
            } catch (\PDOException $e) {
                if (!str_contains($e->getMessage(), 'UNIQUE')) {
                    throw $e;
                }
            }
        }
        Activity::log('LICENSE_KEY', "Neuer Schlüssel für Lizenz {$license['productName']} ({$license['domain']})", $license['clientId'], null, $r->user['id']);
        return Response::json(LicenseService::detail($id));
    }

    public static function send(Request $r): Response
    {
        $license = Db::require('License', $r->param('id'), 'Lizenz nicht gefunden');
        if ($license['status'] === 'PENDING') {
            throw ApiError::badRequest('Die Lizenz ist noch nicht freigeschaltet (wartet auf Zahlung)');
        }
        if (!LicenseService::sendLicenseMail($license['id'])) {
            throw new ApiError(502, 'Die E-Mail konnte nicht gesendet werden (E-Mail eingerichtet? E-Mail-Adresse beim Kunden hinterlegt?)');
        }
        return Response::json(['ok' => true]);
    }

    public static function delete(Request $r): Response
    {
        Db::delete('License', $r->param('id'), 'Lizenz nicht gefunden');
        return Response::noContent();
    }
}
