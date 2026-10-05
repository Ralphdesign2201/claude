<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Request;

final class Pagination
{
    /** @return array{page:int,pageSize:int,offset:int} */
    public static function from(Request $request): array
    {
        $page = max(1, (int) ($request->q('page') ?? 1));
        $pageSize = min(100, max(1, (int) ($request->q('pageSize') ?? 20)));
        return ['page' => $page, 'pageSize' => $pageSize, 'offset' => ($page - 1) * $pageSize];
    }

    /** @return array{items:list<array<string,mixed>>,meta:array<string,int>} */
    public static function wrap(array $items, int $total, int $page, int $pageSize): array
    {
        return [
            'items' => $items,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'pageSize' => $pageSize,
                'totalPages' => max(1, (int) ceil($total / $pageSize)),
            ],
        ];
    }
}
