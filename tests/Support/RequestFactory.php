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
        return new Request(
            strtoupper($method),
            $path,
            [],
            array_change_key_case($headers, CASE_LOWER),
            $body,
            $form,
            '203.0.113.10',
            'testrequest00001',
            $tooLarge,
        );
    }
}
