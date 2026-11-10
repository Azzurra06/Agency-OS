<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Routeur minimal. Expose la liste de ses routes pour les tests d'accès générés
 * (docs/04-securite.md §4, « Tests d'accès croisé »).
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /**
     * @param callable(Request): Response $handler
     */
    public function add(string $method, string $pattern, callable $handler, string $access, bool $allowDemo = true): void
    {
        $this->routes[] = new Route(strtoupper($method), $pattern, $handler, $access, $allowDemo);
    }

    /**
     * @return array{0: Route, 1: array<string, string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        $lookup = $method === 'HEAD' ? 'GET' : $method;
        foreach ($this->routes as $route) {
            $params = $route->match($lookup, rtrim($path, '/') === '' ? '/' : rtrim($path, '/'));
            if ($params !== null) {
                return [$route, $params];
            }
        }

        return null;
    }

    public function pathExists(string $path): bool
    {
        foreach ($this->routes as $route) {
            foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                if ($route->match($method, $path) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }
}
