<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Services\BackupService;
use App\Services\RecurringService;
use App\Support\Env;

/** Auslöser für Webspaces ohne Shell-Cron: ein externer Cron-Dienst ruft POST /api/cron/run mit dem Token auf. */
final class CronController
{
    public static function run(Request $r): Response
    {
        $expected = Env::get('CRON_TOKEN', '') ?? '';
        if (strlen($expected) < 16) {
            throw ApiError::forbidden('Cron-Endpunkt ist deaktiviert (CRON_TOKEN mit mindestens 16 Zeichen in der .env setzen)');
        }
        if (!hash_equals($expected, (string) $r->header('x-cron-token'))) {
            throw ApiError::unauthorized('Ungültiges Cron-Token');
        }

        $results = RecurringService::runDue();
        $backup = null;
        $backupError = null;
        try {
            $backup = BackupService::runIfDue();
        } catch (\Throwable $e) {
            $backupError = $e->getMessage();
        }
        return Response::json(['created' => count($results), 'runs' => $results, 'backup' => $backup, 'backupError' => $backupError]);
    }
}
