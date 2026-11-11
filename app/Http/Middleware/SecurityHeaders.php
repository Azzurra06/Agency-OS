<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;

/**
 * En-têtes de sécurité sur TOUTES les réponses PHP (pages, API, erreurs) — docs/04-securite.md §5.
 * Les réponses authentifiées et l'API ne sont jamais mises en cache (Cache-Control: private, no-store).
 */
final class SecurityHeaders implements Middleware
{
    public const CSP = "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; "
        . "connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'; "
        . "require-trusted-types-for 'script'; upgrade-insecure-requests";

    public function __construct(private readonly bool $hsts, private readonly bool $noIndex = false)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        return self::apply($next($request), $this->hsts, $this->noIndex);
    }

    public static function apply(Response $response, bool $hsts, bool $noIndex = false): Response
    {
        $response = $response
            ->withHeader('Content-Security-Policy', self::CSP)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            // Une politique plus stricte posée par le contrôleur (no-referrer sur les pages à jeton) est conservée.
            ->withHeader('Referrer-Policy', $response->header('Referrer-Policy') === 'no-referrer' ? 'no-referrer' : 'strict-origin-when-cross-origin')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()')
            ->withHeader('Cache-Control', 'private, no-store, max-age=0')
            ->withHeader('Vary', 'Cookie');
        if ($hsts) {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        if ($noIndex) {
            $response = $response->withHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        return $response;
    }
}
