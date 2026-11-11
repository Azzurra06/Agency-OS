<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Request;

final class RequestFactory
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $form
     */
    public static function make(string $method, string $path, array $headers = [], string $body = '', array $form = [], bool $tooLarge = false): Request
    {
        $query = [];
        if (str_contains($path, '?')) {
            [$path, $queryString] = explode('?', $path, 2);
            parse_str($queryString, $parsed);
            $query = array_filter($parsed, 'is_string');
        }

        return new Request(
            strtoupper($method),
            $path,
            $query,
            array_change_key_case($headers, CASE_LOWER),
            $body,
            $form,
            '203.0.113.10',
            'testrequest00001',
            $tooLarge,
        );
    }
}
