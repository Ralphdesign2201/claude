<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Services\ReleaseService;
use App\Support\Db;
use App\Support\Validator;

/** Software-Releases verwalten (nur Admins): Paket hochladen, veröffentlichen, zurückziehen. */
final class ReleasesController
{
    public static function index(Request $r): Response
    {
        $rows = Db::all('SELECT "id", "product", "version", "channel", "notes", "size", "sha256", "minPhp", "access", "minFrom", "fullSize", "published", "downloads", "releasedAt" FROM "Release"');
        usort($rows, static fn ($a, $b) => strcmp($a['product'], $b['product']) ?: version_compare($b['version'], $a['version']));

        return Response::json(array_map(static fn ($x) => $x + ['isPublished' => (bool) $x['published']], $rows));
    }

    public static function create(Request $r): Response
    {
        $file = $r->files['file'] ?? null;
        if (!is_array($file) || !is_string($file['tmp_name'] ?? null) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw ApiError::badRequest('Bitte das Release-Paket (ZIP) hochladen (Feld „file“). Ist es sehr groß, begrenzt evtl. upload_max_filesize des Servers.');
        }
        $b = TicketsController::form($r);
        $channel = TicketsController::enum($b, 'channel', ['stable', 'beta'], 'stable');
        $notes = TicketsController::text($b, 'notes', 10000);
        $access = TicketsController::enum($b, 'access', ['public', 'licensed'], 'licensed');
        $minFrom = TicketsController::text($b, 'minFrom', 30);
        $release = ReleaseService::create($file['tmp_name'], $channel, $notes, TicketsController::flag($b, 'published'), $access, $minFrom);

        return Response::json(self::view($release), 201);
    }

    /** Vollpaket (Erstinstallation) zu einer bestehenden Version hochladen oder ersetzen. */
    public static function uploadFull(Request $r): Response
    {
        $file = $r->files['file'] ?? null;
        if (!is_array($file) || !is_string($file['tmp_name'] ?? null) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw ApiError::badRequest('Bitte das Vollpaket (ZIP) hochladen (Feld „file“).');
        }

        return Response::json(self::view(ReleaseService::attachFull($r->param('id'), $file['tmp_name'])));
    }

    public static function update(Request $r): Response
    {
        $d = Validator::validate($r->body(), [
            'notes' => ['max' => 10000, 'emptyOk' => true], 'channel' => ['enum' => ['stable', 'beta']], 'published' => ['type' => 'bool'],
            'access' => ['enum' => ['public', 'licensed']], 'minFrom' => ['max' => 30, 'emptyOk' => true],
        ], partial: true);
        if (isset($d['minFrom'])) {
            if ($d['minFrom'] !== '' && !ReleaseService::validVersion($d['minFrom'])) {
                throw ApiError::badRequest('Die Mindestversion ist ungültig (erwartet z. B. 0.1.0).');
            }
            $d['minFrom'] = $d['minFrom'] === '' ? null : $d['minFrom'];
        }
        if (isset($d['published'])) {
            $d['published'] = (int) $d['published'];
        }
        Db::update('Release', $r->param('id'), $d, 'Release nicht gefunden');

        return Response::json(self::view(Db::require('Release', $r->param('id'), 'Release nicht gefunden')));
    }

    public static function delete(Request $r): Response
    {
        ReleaseService::delete($r->param('id'));

        return Response::noContent();
    }

    public static function file(Request $r): Response
    {
        $release = Db::require('Release', $r->param('id'), 'Release nicht gefunden');

        return Response::file(ReleaseService::path($release), [
            'Content-Type' => 'application/zip', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $release['product'] . '-' . $release['version']) . '.zip"',
        ]);
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function view(array $r): array
    {
        unset($r['fileName'], $r['signature'], $r['fullFileName'], $r['fullSha256']);
        $r['hasFull'] = ($r['fullSize'] ?? null) !== null;

        return $r + ['isPublished' => (bool) $r['published']];
    }
}
