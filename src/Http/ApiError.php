<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

final class ApiError extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly mixed $details = null,
    ) {
        parent::__construct($message);
    }

    public static function badRequest(string $message, mixed $details = null): self
    {
        return new self(400, $message, $details);
    }

    public static function unauthorized(string $message = 'Nicht authentifiziert'): self
    {
        return new self(401, $message);
    }

    public static function forbidden(string $message = 'Kein Zugriff'): self
    {
        return new self(403, $message);
    }

    public static function notFound(string $message = 'Nicht gefunden'): self
    {
        return new self(404, $message);
    }

    public static function conflict(string $message): self
    {
        return new self(409, $message);
    }
}
