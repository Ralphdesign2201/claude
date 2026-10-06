<?php

declare(strict_types=1);

namespace App\Http;

use App\Support\Auth;

final class Router
{
    public const PUBLIC = 'public';
    public const USER = 'user';
    public const ADMIN = 'admin';

    /** @var list<array{method:string,regex:string,handler:callable,access:string}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, string $access = self::USER): void
    {
        $regex = preg_replace('#:([A-Za-z]+)#', '(?P<$1>[^/]+)', $pattern);
        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'access' => $access,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }
            $request->params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            if ($route['access'] !== self::PUBLIC) {
                $request->user = Auth::authenticate($request);
                if ($route['access'] === self::ADMIN && $request->user['role'] !== 'ADMIN') {
                    throw ApiError::forbidden('Nur für Administratoren');
                }
            }
            return ($route['handler'])($request);
        }

        if ($pathMatched) {
            throw new ApiError(405, 'Methode nicht erlaubt');
        }
        throw ApiError::notFound('Route nicht gefunden');
    }
}
