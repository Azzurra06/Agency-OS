<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Http\Route;
use PHPUnit\Framework\TestCase;
use Tests\Support\RequestFactory;
use Tests\Support\TestApp;

/**
 * Garde-fous transverses (docs/04-securite.md §12.1).
 */
final class SecurityBaselineTest extends TestCase
{
    private const BASE = __DIR__ . '/../..';

    public function testNoForbiddenPatterns(): void
    {
        exec(PHP_BINARY . ' ' . escapeshellarg(self::BASE . '/tools/check-forbidden.php') . ' 2>&1', $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
    }

    public function testEveryRouteDeclaresAccessAndPrivateRoutesRequireAuthentication(): void
    {
        $_SESSION = [];
        $app = TestApp::make();
        $routes = $app->kernel()->router()->routes();
        self::assertNotEmpty($routes);
        foreach ($routes as $route) {
            self::assertNotSame('', $route->access, $route->method . ' ' . $route->pattern);
            if ($route->isPublic()) {
                continue;
            }
            // Route privée appelée sans session : 401 (API) ou redirection vers la connexion (pages), jamais 200.
            $path = (string) preg_replace('/\{[a-z_]+\}/', '01ABCDEFGHJKMNPQRSTVWXYZ00', $route->pattern);
            $response = $app->kernel()->handle(RequestFactory::make($route->method, $path, ['Origin' => 'https://agency-os.test']));
            self::assertContains($response->status, [401, 303, 403], $route->method . ' ' . $route->pattern);
        }
    }

    public function testPublicRoutesAreAnExplicitAllowList(): void
    {
        $app = TestApp::make();
        $public = [];
        foreach ($app->kernel()->router()->routes() as $route) {
            if ($route->access === Route::ACCESS_PUBLIC) {
                $public[] = $route->method . ' ' . $route->pattern;
            }
        }
        // Toute nouvelle route publique doit être ajoutée ici consciemment (revue de sécurité).
        self::assertSame([
            'GET /', 'GET /api/v1/health', 'GET /demo', 'POST /demo', 'GET /demo/expiree',
            // Lot 6 : pages d'information publiques et liens reçus par email (jeton à usage unique, action en POST avec CSRF).
            'GET /methodologie', 'GET /glossaire', 'GET /mentions-legales', 'GET /confidentialite',
            'GET /compte/suppression/{token}', 'POST /compte/suppression', 'GET /compte/annulation/{token}', 'POST /compte/annulation',
            'GET /connexion', 'POST /connexion',
            'GET /inscription', 'GET /mot-de-passe-oublie', 'POST /mot-de-passe-oublie', 'GET /reinitialisation/{token}',
            'GET /reinitialisation', 'POST /reinitialisation', 'GET /verification/{token}',
        ], $public);
    }

    public function testPublicDirectoryExposesOnlyTheFrontController(): void
    {
        $phpFiles = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::BASE . '/public_html', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (str_ends_with($file->getFilename(), '.php')) {
                $phpFiles[] = $file->getFilename();
            }
        }
        self::assertSame(['index.php'], $phpFiles);

        $htaccess = (string) file_get_contents(self::BASE . '/public_html/.htaccess');
        self::assertStringContainsString('Options -Indexes', $htaccess);
        self::assertStringContainsString('RewriteRule (^|/)\. - [F,L]', $htaccess);
        foreach (['sql', 'log', 'env', 'bak', 'key'] as $extension) {
            self::assertStringContainsString($extension, $htaccess);
        }
    }

    public function testSensitiveDirectoriesAreDeniedEvenIfMisconfigured(): void
    {
        foreach (['app', 'config', 'database', 'bin', 'storage', 'tests', 'tools'] as $dir) {
            self::assertSame("Require all denied\n", file_get_contents(self::BASE . '/' . $dir . '/.htaccess'), $dir);
        }
    }

    public function testSecretsAreIgnoredByGit(): void
    {
        $gitignore = (string) file_get_contents(self::BASE . '/.gitignore');
        foreach (['/config/.env', '/config/keys/*', '/storage/logs/*', '/storage/backups/*', '/vendor/'] as $entry) {
            self::assertStringContainsString($entry, $gitignore);
        }
        self::assertFileDoesNotExist(self::BASE . '/config/.env.production');
    }

    public function testNoSecretLooksCommittedInTrackedSources(): void
    {
        $patterns = [
            '/-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----/',
            '/\b(ghp|gho|github_pat)_[A-Za-z0-9_]{20,}/',
            '/\bAKIA[0-9A-Z]{16}\b/',
            '/DB_PASSWORD\s*=\s*\S{6,}/',
        ];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::BASE, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (preg_match('#/(vendor|\.git|storage|node_modules)/#', $path) || !preg_match('/\.(php|js|json|md|example|yml|neon|xml|sql|css|html)$/', $path)) {
                continue;
            }
            $content = (string) file_get_contents($path);
            foreach ($patterns as $pattern) {
                self::assertDoesNotMatchRegularExpression($pattern, $content, $path);
            }
        }
    }
}
