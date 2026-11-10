<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Escaper;
use App\Support\FrozenClock;
use App\Support\Logger;
use App\Support\PublicId;
use PHPUnit\Framework\TestCase;

final class SupportTest extends TestCase
{
    public function testPublicIdsAreRandomAndWellFormed(): void
    {
        $ids = [];
        for ($i = 0; $i < 200; $i++) {
            $id = PublicId::generate();
            self::assertTrue(PublicId::isValid($id), $id);
            $ids[$id] = true;
        }
        self::assertCount(200, $ids);
        self::assertFalse(PublicId::isValid('01ARZ3NDEKTSV4RRFFQ69G5FAI')); // « I » exclu de l'alphabet
    }

    public function testEscaping(): void
    {
        self::assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', Escaper::html('<script>alert("x")</script>'));
        self::assertSame('&apos; onmouseover=&apos;x', Escaper::attr("' onmouseover='x"));
        $json = Escaper::jsonScript(['name' => '</script><script>alert(1)</script>', 'q' => "l'agence & co"]);
        self::assertStringNotContainsString('</script>', $json);
        self::assertStringNotContainsString("'", $json);
        self::assertStringNotContainsString('&', $json);
        self::assertStringContainsString('\\u0026', $json);
        self::assertStringContainsString('\\u003C', $json);
    }

    public function testLoggerRedactsSecretsAmountsAndUnknownKeys(): void
    {
        $context = Logger::sanitize([
            'event' => 'test', 'route' => '/api/v1/x', 'password' => 'secret', 'revenue' => '180000',
            'email' => 'claire@example.com', 'error_ref' => 'ABCD1234', 'random' => 'x', 'status' => 500,
        ]);
        self::assertSame(['event' => 'test', 'route' => '/api/v1/x', 'error_ref' => 'ABCD1234', 'status' => 500], $context);
    }

    public function testLoggerScrubsNumbersAndEmailsFromMessages(): void
    {
        $scrubbed = Logger::scrub('Échec pour claire@example.com : CA 180 000,00 € > 150000');
        self::assertStringNotContainsString('claire@example.com', $scrubbed);
        self::assertStringNotContainsString('180', $scrubbed);
        self::assertStringNotContainsString('150000', $scrubbed);
    }

    public function testLoggerNeverWritesFinancialValues(): void
    {
        $dir = sys_get_temp_dir() . '/agencyos-log-' . bin2hex(random_bytes(4));
        $logger = new Logger($dir, new FrozenClock('2026-11-12 09:00:00'));
        $logger->exception(new \RuntimeException('Montant invalide : 24400.00 pour claire@example.com'), 'REF00001');
        $logger->info('Profil enregistré', ['event' => 'x', 'revenue' => '180000.00', 'body' => '{"revenue":"180000.00"}']);
        $content = (string) file_get_contents($dir . '/app-2026-11-12.log');
        self::assertStringNotContainsString('24400', $content);
        self::assertStringNotContainsString('180000', $content);
        self::assertStringNotContainsString('claire@example.com', $content);
        self::assertSame(0600, fileperms($dir . '/app-2026-11-12.log') & 0777);
    }
}
