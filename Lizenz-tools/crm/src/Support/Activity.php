<?php

declare(strict_types=1);

namespace App\Support;

final class Activity
{
    public static function log(string $type, string $message, ?string $clientId = null, ?string $projectId = null, ?string $userId = null): void
    {
        Db::insert('Activity', [
            'clientId' => $clientId,
            'projectId' => $projectId,
            'userId' => $userId,
            'type' => $type,
            'message' => $message,
        ]);
    }
}
