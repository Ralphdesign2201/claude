<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Services\BackupService;
use App\Support\Activity;
use App\Support\Env;
use RuntimeException;

/** Backups verwalten – nur für Administratoren (ein Backup enthält alle Kundendaten). */
final class BackupController
{
    public static function index(Request $r): Response
    {
        $items = BackupService::list();
        return Response::json([
            'items' => $items,
            'lastBackupAt' => $items[0]['createdAt'] ?? null,
            'settings' => [
                'auto' => Env::bool('BACKUP_AUTO', true),
                'intervalHours' => max(1, Env::int('BACKUP_INTERVAL_HOURS', 24)),
                'keep' => max(1, Env::int('BACKUP_KEEP', 14)),
                'keepMonths' => max(0, Env::int('BACKUP_KEEP_MONTHS', 12)),
                'encrypted' => BackupService::isEncrypted(),
                'copyConfigured' => trim(Env::get('BACKUP_COPY_DIR', '') ?? '') !== '',
            ],
        ]);
    }

    public static function create(Request $r): Response
    {
        try {
            $backup = BackupService::create();
        } catch (RuntimeException $e) {
            throw new ApiError(500, 'Backup fehlgeschlagen: ' . $e->getMessage());
        }
        Activity::log('BACKUP_CREATED', "Backup {$backup['name']} erstellt", null, null, $r->user['id']);
        return Response::json($backup, 201);
    }

    public static function download(Request $r): Response
    {
        $path = BackupService::path($r->param('name'));
        return Response::file($path, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . basename($path) . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public static function delete(Request $r): Response
    {
        BackupService::delete($r->param('name'));
        return Response::noContent();
    }
}
