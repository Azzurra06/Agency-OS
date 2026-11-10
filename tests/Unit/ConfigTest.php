<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Config;
use App\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfigTest extends TestCase
{
    /** @return array<string, string> */
    private static function base(): array
    {
        return [
            'APP_ENV' => 'production', 'APP_PROFILE' => 'portfolio', 'APP_URL' => 'https://agency-os.azzurra-pro.com',
            'APP_KEY' => str_repeat('f', 64), 'DB_NAME' => 'db', 'DB_USER' => 'user',
        ];
    }

    public function testPortfolioDefaults(): void
    {
        $config = Config::fromEnv(Env::fromArray(self::base()), '/srv/app');
        self::assertTrue($config->isPortfolio());
        self::assertFalse($config->realDataAllowed);
        self::assertSame('capture', $config->mailTransport);
        self::assertSame('openssl', $config->cryptoProvider);
        self::assertTrue($config->sessionSecure);
        self::assertSame('/srv/app/storage/logs', $config->storagePath('logs'));
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function dangerousCombinations(): iterable
    {
        yield 'portfolio avec données réelles' => [['APP_REAL_DATA_ALLOWED' => 'true'], 'données réelles'];
        yield 'portfolio avec SMTP réel' => [['MAIL_TRANSPORT' => 'smtp'], 'email réel'];
        yield 'production sans HTTPS' => [['APP_URL' => 'http://agency-os.azzurra-pro.com'], 'HTTPS'];
        yield 'production sans cookie sécurisé' => [['SESSION_SECURE' => 'false'], 'SESSION_SECURE'];
        yield 'clé d\'application trop courte' => [['APP_KEY' => 'court'], 'APP_KEY'];
        yield 'profil inconnu' => [['APP_PROFILE' => 'test'], 'APP_PROFILE'];
    }

    /**
     * @param array<string, string> $overrides
     */
    #[DataProvider('dangerousCombinations')]
    public function testRefusesDangerousCombinations(array $overrides, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        Config::fromEnv(Env::fromArray($overrides + self::base()), '/srv/app');
    }

    public function testEnvFileParsing(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, "# commentaire\nAPP_URL=\"https://x.test\"\nDB_NAME=agencyos # fin de ligne\nEMPTY=\n");
        $env = Env::load($file);
        unlink($file);
        self::assertSame('https://x.test', $env->get('APP_URL'));
        self::assertSame('agencyos', $env->get('DB_NAME'));
        self::assertSame('', $env->get('EMPTY'));
        self::assertNull($env->get('MISSING'));
    }

    public function testEnvFileRejectsMalformedLines(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, "pas une affectation\n");
        try {
            $this->expectException(RuntimeException::class);
            Env::load($file);
        } finally {
            unlink($file);
        }
    }
}
