<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Services\ProductLicense;
use App\Services\UpdateService;
use App\Support\Product;
use App\Support\Validator;
use RuntimeException;

/** Lizenz und Updates der Software selbst (nur Admins, nur in der lizenzierten Produktversion). */
final class SystemController
{
    public static function status(Request $r): Response
    {
        return Response::json([
            'license' => ProductLicense::publicState(),
            'update' => UpdateService::lastState(),
            'history' => UpdateService::history(),
            'channel' => \App\Support\Env::get('UPDATE_CHANNEL', 'stable') === 'beta' ? 'beta' : 'stable',
        ]);
    }

    public static function refresh(Request $r): Response
    {
        self::requireProduct();
        ProductLicense::state(true);

        return Response::json(['license' => ProductLicense::publicState()]);
    }

    public static function setKey(Request $r): Response
    {
        self::requireProduct();
        $d = Validator::validate($r->body(), ['key' => ['required' => true, 'min' => 5, 'max' => 64]]);
        $result = ProductLicense::setKey($d['key']);
        if (!$result['ok']) {
            throw ApiError::badRequest($result['message'], ['code' => 'LICENSE_REJECTED', 'reason' => $result['reason']]);
        }

        return Response::json(['license' => ProductLicense::publicState()]);
    }

    public static function checkUpdate(Request $r): Response
    {
        try {
            $info = UpdateService::check();
        } catch (RuntimeException $e) {
            throw new ApiError(502, $e->getMessage());
        }
        unset($info['download']);

        return Response::json($info);
    }

    public static function installUpdate(Request $r): Response
    {
        set_time_limit(300);
        try {
            return Response::json(UpdateService::install());
        } catch (RuntimeException $e) {
            throw new ApiError(409, $e->getMessage());
        }
    }

    private static function requireProduct(): void
    {
        if (!Product::enforced()) {
            throw ApiError::badRequest('Das gibt es nur in der lizenzierten Produktversion.');
        }
    }
}
