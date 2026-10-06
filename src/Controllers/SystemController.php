<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Services\UpdateService;
use App\Support\Env;
use App\Support\Product;
use RuntimeException;

/** Version und Updates dieser Installation (nur Admins). */
final class SystemController
{
    public static function status(Request $r): Response
    {
        return Response::json([
            'version' => Product::version(),
            'updates' => ['configured' => Product::updatesConfigured()],
            'update' => UpdateService::lastState(),
            'history' => UpdateService::history(),
            'channel' => Env::get('UPDATE_CHANNEL', 'stable') === 'beta' ? 'beta' : 'stable',
        ]);
    }

    public static function checkUpdate(Request $r): Response
    {
        try {
            $info = UpdateService::check();
        } catch (RuntimeException $e) {
            throw new ApiError(502, $e->getMessage());
        }
        unset($info['download'], $info['target']['signature']);

        return Response::json($info);
    }

    public static function installUpdate(Request $r): Response
    {
        set_time_limit(300);
        try {
            return Response::json(UpdateService::installAll());
        } catch (RuntimeException $e) {
            throw new ApiError(409, $e->getMessage());
        }
    }
}
