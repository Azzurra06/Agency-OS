<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\PasswordHasher;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PasswordHasherTest extends TestCase
{
    public function testBcryptCost12ByDefault(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash('un-mot-de-passe-solide');
        self::assertSame('bcrypt', $hasher->algorithmName());
        self::assertStringStartsWith('$2y$12$', $hash);
        self::assertTrue($hasher->verify('un-mot-de-passe-solide', $hash));
        self::assertFalse($hasher->verify('un-mot-de-passe-faux', $hash));
        self::assertFalse($hasher->needsRehash($hash));
    }

    public function testPolicy(): void
    {
        $hasher = new PasswordHasher();
        self::assertSame('password_too_short', $hasher->policyError('court'));
        self::assertSame('password_too_long', $hasher->policyError(str_repeat('a', 129)));
        // Plus de 72 octets en bcrypt : refusé plutôt que tronqué silencieusement.
        self::assertSame('password_too_long', $hasher->policyError(str_repeat('é', 40)));
        self::assertNull($hasher->policyError('douze-caract'));
    }

    public function testShortPasswordCannotBeHashed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PasswordHasher())->hash('court');
    }

    public function testArgon2idWhenAvailableTriggersRehashOfBcrypt(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            self::markTestSkipped('Argon2id indisponible sur ce PHP.');
        }
        $bcrypt = (new PasswordHasher())->hash('un-mot-de-passe-solide');
        $argon = new PasswordHasher(true);
        self::assertSame('argon2id', $argon->algorithmName());
        self::assertTrue($argon->verify('un-mot-de-passe-solide', $bcrypt));
        self::assertTrue($argon->needsRehash($bcrypt));
    }
}
