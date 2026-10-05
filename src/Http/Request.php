<?php

declare(strict_types=1);

namespace App\Http;

use App\Support\Dates;
use JsonException;

final class Request
{
    /** @var array<string,string> */
    public array $params = [];
    /** @var array{id:string,email:string,role:string}|null */
    public ?array $user = null;
    private mixed $body = null;
    private bool $bodyParsed = false;

    /**
     * @param array<string,mixed> $query
     * @param array<string,string> $headers
     * @param array<string,mixed> $files
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly array $files,
        private readonly string $rawBody,
        private readonly array $form,
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
        $path = '/' . trim($path, '/');

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_string($value)) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $server => $name) {
            if (isset($_SERVER[$server])) {
                $headers[$name] = (string) $_SERVER[$server];
            }
        }
        if (!isset($headers['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $maxBody = 5 * 1024 * 1024;
        $isMultipart = str_starts_with($headers['content-type'] ?? '', 'multipart/form-data');
        if (!$isMultipart && (int) ($headers['content-length'] ?? 0) > $maxBody) {
            throw new ApiError(413, 'Request zu groß');
        }
        $raw = $isMultipart ? '' : (string) file_get_contents('php://input', false, null, 0, $maxBody + 1);
        if (strlen($raw) > $maxBody) {
            throw new ApiError(413, 'Request zu groß');
        }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            $headers,
            $_FILES,
            $raw,
            $_POST,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('authorization');
        if ($header !== null && preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return $m[1];
        }
        return null;
    }

    public function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    /** Query-Parameter als String (Arrays und leere Werte werden ignoriert). */
    public function q(string $key): ?string
    {
        $value = $this->query[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Query-Parameter als normalisiertes Datum (UTC-ISO). */
    public function qDate(string $key): ?string
    {
        $value = $this->q($key);
        if ($value === null) {
            return null;
        }
        $date = Dates::normalize($value);
        if ($date === null) {
            throw ApiError::badRequest("Ungültiges Datum für Parameter \"$key\"");
        }
        return $date;
    }

    public function param(string $name): string
    {
        return $this->params[$name];
    }

    /** JSON-Body (oder Formularfelder bei multipart). */
    public function body(): mixed
    {
        if ($this->bodyParsed) {
            return $this->body;
        }
        $this->bodyParsed = true;

        if (str_starts_with($this->header('content-type') ?? '', 'multipart/form-data')) {
            return $this->body = $this->form;
        }
        if (trim($this->rawBody) === '') {
            return $this->body = [];
        }
        try {
            return $this->body = json_decode($this->rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ApiError::badRequest('Ungültiges JSON im Request-Body');
        }
    }
}
