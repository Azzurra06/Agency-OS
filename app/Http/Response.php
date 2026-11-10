<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Réponse HTTP. Les réponses JSON suivent l'enveloppe { data, meta } ou { error } (docs/02-architecture.md §6).
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status = 200,
        public readonly string $body = '',
        private array $headers = [],
    ) {
    }

    /**
     * @param array<mixed>|null $data
     * @param array<string, mixed> $meta
     */
    public static function json(?array $data, int $status = 200, array $meta = []): self
    {
        $payload = ['data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return new self($status, self::encode($payload), ['Content-Type' => 'application/json; charset=utf-8']);
    }

    /**
     * @param array<string, string> $fields
     */
    public static function error(int $status, string $code, string $message, array $fields = [], ?string $errorRef = null): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }
        if ($errorRef !== null) {
            $error['error_ref'] = $errorRef;
        }

        return new self($status, self::encode(['error' => $error]), ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirect(string $location, int $status = 303): self
    {
        // Redirections internes uniquement : chemin relatif commençant par « / » (pas d'open redirect).
        if (!str_starts_with($location, '/') || str_starts_with($location, '//')) {
            $location = '/';
        }

        return new self($status, '', ['Location' => $location]);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . str_replace(["\r", "\n"], '', $value));
            }
        }
        echo $this->body;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function encode(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
