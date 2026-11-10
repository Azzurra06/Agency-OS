<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\Crypto\CryptoProvider;
use App\Security\Crypto\FieldContext;
use App\Security\Crypto\FieldCrypto;
use App\Security\Crypto\IntegrityException;
use App\Security\Crypto\Keyring;
use App\Security\Crypto\OpenSslProvider;
use App\Security\Crypto\SodiumProvider;
use App\Support\Decimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests de contrat exécutés sur LES DEUX implémentations (docs/04-securite.md §17.4).
 */
final class CryptoTest extends TestCase
{
    /** @return iterable<string, array{CryptoProvider}> */
    public static function providers(): iterable
    {
        yield 'openssl' => [new OpenSslProvider()];
        if (extension_loaded('sodium')) {
            yield 'sodium' => [new SodiumProvider()];
        }
    }

    #[DataProvider('providers')]
    public function testRoundTrip(CryptoProvider $provider): void
    {
        $key = random_bytes(32);
        $cipher = $provider->encrypt('180000.00', 'aad', $key);
        self::assertNotSame('180000.00', $cipher);
        self::assertSame('180000.00', $provider->decrypt($cipher, 'aad', $key));
    }

    #[DataProvider('providers')]
    public function testNonceIsRandom(CryptoProvider $provider): void
    {
        $key = random_bytes(32);
        self::assertNotSame($provider->encrypt('x', 'a', $key), $provider->encrypt('x', 'a', $key));
    }

    #[DataProvider('providers')]
    public function testRejectsModifiedAssociatedData(CryptoProvider $provider): void
    {
        $key = random_bytes(32);
        $cipher = $provider->encrypt('secret', 'org:1', $key);
        $this->expectException(IntegrityException::class);
        $provider->decrypt($cipher, 'org:2', $key);
    }

    #[DataProvider('providers')]
    public function testRejectsTamperedCiphertext(CryptoProvider $provider): void
    {
        $key = random_bytes(32);
        $cipher = $provider->encrypt('secret', 'a', $key);
        $cipher[strlen($cipher) - 1] = chr(ord($cipher[strlen($cipher) - 1]) ^ 1);
        $this->expectException(IntegrityException::class);
        $provider->decrypt($cipher, 'a', $key);
    }

    #[DataProvider('providers')]
    public function testRejectsWrongKey(CryptoProvider $provider): void
    {
        $cipher = $provider->encrypt('secret', 'a', random_bytes(32));
        $this->expectException(IntegrityException::class);
        $provider->decrypt($cipher, 'a', random_bytes(32));
    }

    public function testFieldCryptoBindsValueToRowAndOrganisation(): void
    {
        $crypto = new FieldCrypto(new Keyring('k1', ['k1' => random_bytes(32)]), new OpenSslProvider());
        $context = new FieldContext('financial_profiles', 'revenue', 1, 'ROW1');
        $stored = $crypto->encrypt('180000.00', $context);

        self::assertStringStartsWith('k1.gcm:', $stored);
        self::assertSame('180000.00', $crypto->decrypt($stored, $context));

        // Valeur recopiée vers une autre organisation : refus.
        $this->expectException(IntegrityException::class);
        $crypto->decrypt($stored, new FieldContext('financial_profiles', 'revenue', 2, 'ROW1'));
    }

    public function testFieldCryptoRejectsValueMovedToAnotherColumn(): void
    {
        $crypto = new FieldCrypto(new Keyring('k1', ['k1' => random_bytes(32)]), new OpenSslProvider());
        $stored = $crypto->encrypt('50000.00', new FieldContext('financial_profiles', 'owner_comp_total_cost', 1, 'ROW1'));
        $this->expectException(IntegrityException::class);
        $crypto->decrypt($stored, new FieldContext('financial_profiles', 'revenue', 1, 'ROW1'));
    }

    public function testKeyRotationKeepsOldValuesReadable(): void
    {
        $k1 = random_bytes(32);
        $k2 = random_bytes(32);
        $context = new FieldContext('kpi_snapshots', 'revenue', 7, 'SNAP');
        $old = (new FieldCrypto(new Keyring('k1', ['k1' => $k1]), new OpenSslProvider()))->encrypt('1', $context);

        $rotated = new FieldCrypto(new Keyring('k2', ['k1' => $k1, 'k2' => $k2]), new OpenSslProvider());
        self::assertSame('1', $rotated->decrypt($old, $context));
        self::assertTrue($rotated->needsReencryption($old));
        $new = $rotated->encrypt('1', $context);
        self::assertStringStartsWith('k2.gcm:', $new);
        self::assertFalse($rotated->needsReencryption($new));
    }

    public function testRetiredKeyIsRejected(): void
    {
        $context = new FieldContext('t', 'c', 1, 'R');
        $old = (new FieldCrypto(new Keyring('k1', ['k1' => random_bytes(32)]), new OpenSslProvider()))->encrypt('1', $context);
        $this->expectException(IntegrityException::class);
        (new FieldCrypto(new Keyring('k2', ['k2' => random_bytes(32)]), new OpenSslProvider()))->decrypt($old, $context);
    }

    public function testValuesFromSodiumRemainReadableWithOpenSslActive(): void
    {
        if (!extension_loaded('sodium')) {
            self::markTestSkipped('Extension sodium absente.');
        }
        $keyring = new Keyring('k1', ['k1' => random_bytes(32)]);
        $context = new FieldContext('t', 'c', 1, 'R');
        $stored = (new FieldCrypto($keyring, new SodiumProvider()))->encrypt('42', $context);
        self::assertStringStartsWith('k1.xc20:', $stored);
        self::assertSame('42', (new FieldCrypto($keyring, new OpenSslProvider(), new SodiumProvider()))->decrypt($stored, $context));
    }

    public function testDecimalHelpers(): void
    {
        $crypto = new FieldCrypto(new Keyring('k1', ['k1' => random_bytes(32)]), new OpenSslProvider());
        $context = new FieldContext('t', 'c', 1, 'R');
        self::assertNull($crypto->encryptDecimal(null, $context));
        $stored = $crypto->encryptDecimal(Decimal::of('24400.00'), $context);
        self::assertNotNull($stored);
        self::assertTrue(Decimal::of('24400')->equals($crypto->decryptDecimal($stored, $context) ?? Decimal::zero()));
    }

    public function testMalformedStoredValueIsRejected(): void
    {
        $crypto = new FieldCrypto(new Keyring('k1', ['k1' => random_bytes(32)]), new OpenSslProvider());
        $this->expectException(IntegrityException::class);
        $crypto->decrypt('180000.00', new FieldContext('t', 'c', 1, 'R'));
    }
}
