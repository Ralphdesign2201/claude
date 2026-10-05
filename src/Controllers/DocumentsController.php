<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Support\Db;
use App\Support\Where;

final class DocumentsController
{
    private const MAX_SIZE = 25 * 1024 * 1024;

    /** Dateiendung => MIME-Typ. Unbekannte Endungen werden als .bin gespeichert, damit nie ausführbarer Code im Upload-Ordner landet. */
    private const TYPES = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'txt' => 'text/plain',
        'md' => 'text/markdown',
        'csv' => 'text/csv',
        'json' => 'application/json',
        'zip' => 'application/zip',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
    ];

    /** Nur diese Typen dürfen im Browser direkt angezeigt werden; alles andere wird als Download ausgeliefert. */
    private const INLINE = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp'];

    public static function uploadDir(): string
    {
        return APP_ROOT . '/uploads';
    }

    public static function index(Request $r): Response
    {
        $where = (new Where())->eq('"clientId"', $r->q('clientId'))->eq('"projectId"', $r->q('projectId'));
        return Response::json(Db::all('SELECT * FROM "Document"' . $where->sql() . ' ORDER BY "createdAt" DESC', $where->params()));
    }

    public static function create(Request $r): Response
    {
        $file = $r->files['file'] ?? null;
        if (!is_array($file) || !is_string($file['tmp_name'] ?? null)) {
            throw ApiError::badRequest('Keine Datei hochgeladen (Feld "file" erwartet)');
        }
        match ($file['error']) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => throw new ApiError(413, 'Datei zu groß (maximal 25 MB)'),
            UPLOAD_ERR_NO_FILE => throw ApiError::badRequest('Keine Datei hochgeladen'),
            default => throw new ApiError(500, 'Upload fehlgeschlagen'),
        };
        if ($file['size'] > self::MAX_SIZE) {
            throw new ApiError(413, 'Datei zu groß (maximal 25 MB)');
        }

        $clientId = self::optionalString($r, 'clientId');
        $projectId = self::optionalString($r, 'projectId');
        if ($clientId !== null) {
            Db::require('Client', $clientId, 'Kunde nicht gefunden');
        }
        if ($projectId !== null) {
            Db::require('Project', $projectId, 'Projekt nicht gefunden');
        }

        $originalName = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', (string) $file['name']))) ?? '');
        $originalName = mb_substr($originalName === '' ? 'datei' : $originalName, 0, 255);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $ext = isset(self::TYPES[$ext]) ? $ext : 'bin';

        if (!is_dir(self::uploadDir())) {
            mkdir(self::uploadDir(), 0775, true);
        }
        $stored = sprintf('%d-%s.%s', time(), bin2hex(random_bytes(8)), $ext);
        if (!move_uploaded_file($file['tmp_name'], self::uploadDir() . '/' . $stored)) {
            throw new ApiError(500, 'Datei konnte nicht gespeichert werden');
        }

        $id = Db::insert('Document', [
            'clientId' => $clientId,
            'projectId' => $projectId,
            'name' => $originalName,
            'url' => '/uploads/' . $stored,
            'mimeType' => self::TYPES[$ext] ?? 'application/octet-stream',
            'size' => (int) $file['size'],
        ]);
        return Response::json(Db::find('Document', $id), 201);
    }

    public static function delete(Request $r): Response
    {
        $doc = Db::require('Document', $r->param('id'), 'Dokument nicht gefunden');
        Db::delete('Document', $doc['id']);

        $path = self::pathFor(basename($doc['url']));
        if ($path !== null && is_file($path)) {
            unlink($path);
        }
        return Response::noContent();
    }

    /** Liefert eine hochgeladene Datei aus; Dateinamen sind zufällig und nicht erratbar. */
    public static function serve(Request $r): Response
    {
        $name = $r->param('name');
        $path = self::pathFor($name);
        if ($path === null || !is_file($path)) {
            throw ApiError::notFound('Datei nicht gefunden');
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return Response::file($path, [
            'Content-Type' => self::TYPES[$ext] ?? 'application/octet-stream',
            'Content-Disposition' => (in_array($ext, self::INLINE, true) ? 'inline' : 'attachment') . '; filename="' . $name . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    private static function pathFor(string $name): ?string
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name) ? self::uploadDir() . '/' . $name : null;
    }

    private static function optionalString(Request $r, string $key): ?string
    {
        $value = $r->body()[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }
}
