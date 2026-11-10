<?php

declare(strict_types=1);

namespace App\Http;

use App\Support\TypedArray;
use JsonException;

/**
 * Requête HTTP immuable. Le corps est lu avec une taille plafonnée (docs/04-securite.md §5).
 */
final class Request
{
    /** @var array<string, string> */
    private array $routeParams = [];

    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers en minuscules
     * @param array<string, mixed>  $form
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly string $rawBody,
        public readonly array $form,
        public readonly string $clientIp,
        public readonly string $requestId,
        public readonly bool $bodyTooLarge = false,
    ) {
    }

    public static function fromGlobals(int $maxBodyBytes): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_') && is_string($value)) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $server => $header) {
            if (isset($_SERVER[$server]) && is_string($_SERVER[$server])) {
                $headers[$header] = $_SERVER[$server];
            }
        }

        $declared = (int) ($headers['content-length'] ?? 0);
        $tooLarge = $declared > $maxBodyBytes;
        $body = '';
        if (!$tooLarge) {
            $read = file_get_contents('php://input', false, null, 0, max(0, $maxBodyBytes) + 1);
            $body = $read === false ? '' : $read;
            $tooLarge = strlen($body) > $maxBodyBytes;
        }

        $path = parse_url(TypedArray::string($_SERVER, 'REQUEST_URI', '/'), PHP_URL_PATH);
        /** @var array<string, string> $query */
        $query = array_filter($_GET, 'is_string');
        /** @var array<string, mixed> $form */
        $form = $_POST;

        return new self(
            strtoupper(TypedArray::string($_SERVER, 'REQUEST_METHOD', 'GET')),
            is_string($path) && $path !== '' ? $path : '/',
            $query,
            $headers,
            $tooLarge ? '' : $body,
            $tooLarge ? [] : $form,
            // REMOTE_ADDR uniquement : aucun en-tête X-Forwarded-For n'est accepté (pas de proxy de confiance).
            TypedArray::string($_SERVER, 'REMOTE_ADDR', '0.0.0.0'),
            bin2hex(random_bytes(8)),
            $tooLarge,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isApi(): bool
    {
        return str_starts_with($this->path, '/api/');
    }

    public function isJson(): bool
    {
        return str_starts_with(strtolower($this->header('content-type') ?? ''), 'application/json');
    }

    /**
     * Corps JSON décodé (objet attendu), profondeur maximale 16.
     *
     * @return array<string, mixed>
     * @throws JsonException
     */
    public function json(): array
    {
        if ($this->rawBody === '') {
            return [];
        }
        $data = json_decode($this->rawBody, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw new JsonException('Objet JSON attendu.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, string> $params
     */
    public function withRouteParams(array $params): self
    {
        $clone = clone $this;
        $clone->routeParams = $params;

        return $clone;
    }

    public function param(string $name): string
    {
        return $this->routeParams[$name] ?? '';
    }

    public function withAttribute(string $name, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;

        return $clone;
    }

    public function attribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }
}
