<?php

declare(strict_types=1);

namespace App\Http;

use InvalidArgumentException;

/**
 * Déclaration d'une route. Chaque route DOIT déclarer son niveau d'accès :
 * « public » (explicite) ou une permission (ex. « pricing.write »). Refus par défaut.
 */
final class Route
{
    public const ACCESS_PUBLIC = 'public';

    /** @var list<string> */
    public readonly array $paramNames;

    private readonly string $regex;

    /**
     * @param callable(Request): Response $handler
     */
    public function __construct(
        public readonly string $method,
        public readonly string $pattern,
        private readonly mixed $handler,
        public readonly string $access,
        public readonly bool $allowDemo = true,
        public readonly bool $startsSession = false,
    ) {
        if ($access === '') {
            throw new InvalidArgumentException(sprintf('La route %s %s ne déclare aucun niveau d\'accès.', $method, $pattern));
        }
        if (!preg_match('#^/[a-z0-9/_{}\-]*$#', $pattern)) {
            throw new InvalidArgumentException(sprintf('Motif de route invalide : %s', $pattern));
        }
        preg_match_all('/\{([a-z_]+)\}/', $pattern, $matches);
        $this->paramNames = $matches[1];
        // Les paramètres n'acceptent que des identifiants publics ou des codes simples (pas de « / »).
        $this->regex = '#^' . preg_replace('/\\\{[a-z_]+\\\}/', '([A-Za-z0-9_\-]{1,64})', preg_quote($pattern, '#')) . '$#';
    }

    public function isPublic(): bool
    {
        return $this->access === self::ACCESS_PUBLIC;
    }

    /**
     * @return array<string, string>|null paramètres si la route correspond
     */
    public function match(string $method, string $path): ?array
    {
        if ($method !== $this->method || !preg_match($this->regex, $path, $values)) {
            return null;
        }
        array_shift($values);

        return array_combine($this->paramNames, $values) ?: [];
    }

    public function handle(Request $request): Response
    {
        return ($this->handler)($request);
    }
}
