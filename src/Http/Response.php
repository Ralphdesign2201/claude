<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    /** @param array<string,string> $headers */
    private function __construct(
        public readonly int $status,
        private readonly mixed $data,
        private array $headers = [],
        private readonly ?string $file = null,
    ) {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self($status, $data, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function noContent(): self
    {
        return new self(204, null);
    }

    /** @param array<string,string> $headers */
    public static function file(string $path, array $headers): self
    {
        return new self(200, null, $headers, $path);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        if ($this->file !== null) {
            header('Content-Length: ' . filesize($this->file));
            readfile($this->file);
            return;
        }
        if ($this->data !== null) {
            echo json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }
    }
}
