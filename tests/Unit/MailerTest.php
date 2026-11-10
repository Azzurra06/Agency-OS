<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Mail\CaptureTransport;
use App\Mail\Mailer;
use App\Mail\Message;
use App\Support\Config;
use App\Support\Env;
use App\Support\FrozenClock;
use App\Support\Logger;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MailerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/agencyos-mail-' . bin2hex(random_bytes(4));
    }

    private function mailer(string $allowlist = ''): array
    {
        $config = Config::fromEnv(Env::fromArray([
            'APP_ENV' => 'testing', 'APP_URL' => 'https://x.test', 'APP_KEY' => str_repeat('a', 64),
            'DB_NAME' => 'x', 'DB_USER' => 'x', 'MAIL_ALLOWLIST' => $allowlist, 'MAIL_FROM' => 'no-reply@azzurra-pro.com',
        ]), $this->dir);
        $transport = new CaptureTransport($this->dir . '/capture', new FrozenClock('2026-11-12 09:00:00'));

        return [new Mailer($transport, $config, new Logger($this->dir . '/logs')), $transport];
    }

    public function testCaptureWritesPrivateFileAndSendsNothing(): void
    {
        [$mailer, $transport] = $this->mailer();
        self::assertSame('capture', $mailer->transportName());
        self::assertTrue($mailer->send(new Message('test@example.com', 'Vérifiez votre adresse', 'Lien : https://x.test/v/abc')));
        $files = $transport->captured();
        self::assertCount(1, $files);
        self::assertSame(0600, fileperms($files[0]) & 0777);
        $mail = json_decode((string) file_get_contents($files[0]), true, 4, JSON_THROW_ON_ERROR);
        self::assertSame('no-reply@azzurra-pro.com', $mail['from']);
        self::assertSame('test@example.com', $mail['to']);
    }

    public function testAllowlistBlocksOtherRecipients(): void
    {
        [$mailer, $transport] = $this->mailer('@azzurra-pro.com');
        self::assertFalse($mailer->send(new Message('client@example.com', 'Objet', 'Corps')));
        self::assertTrue($mailer->send(new Message('moi@azzurra-pro.com', 'Objet', 'Corps')));
        self::assertCount(1, $transport->captured());
    }

    public function testHeaderInjectionInSubjectIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Message('test@example.com', "Objet\r\nBcc: victime@example.com", 'Corps');
    }
}
