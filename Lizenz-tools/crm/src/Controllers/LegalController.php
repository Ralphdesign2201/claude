<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Services\HealthService;
use App\Services\LegalService;

/** Rechtstexte verwalten (Admin) und öffentlich ausliefern. */
final class LegalController
{
    private static function type(Request $r): string
    {
        $t = strtoupper($r->param('type'));
        if (!in_array($t, LegalService::TYPES, true)) {
            throw ApiError::notFound('Unbekannter Rechtstext');
        }
        return $t;
    }

    public static function index(Request $r): Response
    {
        $docs = [];
        foreach (LegalService::TYPES as $t) {
            $docs[$t] = LegalService::describe($t);
        }
        return Response::json(['docs' => $docs, 'schema' => LegalService::schema()]);
    }

    public static function save(Request $r): Response
    {
        $body = $r->body();
        return Response::json(LegalService::save(self::type($r), is_array($body) ? $body : []));
    }

    public static function preview(Request $r): Response
    {
        $body = $r->body();
        return Response::json(LegalService::describe(self::type($r), is_array($body) ? $body : []));
    }

    public static function health(Request $r): Response
    {
        return Response::json(HealthService::check());
    }

    /** Öffentliche Seite: /impressum, /datenschutz, /agb */
    public static function page(Request $r): Response
    {
        $type = array_search($r->path, LegalService::PATHS, true);
        $doc = is_string($type) ? LegalService::published($type) : null;
        if ($doc === null) {
            return Response::html(LegalService::page('Nicht gefunden', '<h1>Nicht gefunden</h1><p>Dieser Text ist nicht veröffentlicht.</p>', null), 404);
        }
        return Response::html(LegalService::page($doc['title'], $doc['html'], $doc['updatedAt']), 200, [
            'Cache-Control' => 'no-cache',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
        ]);
    }
}
