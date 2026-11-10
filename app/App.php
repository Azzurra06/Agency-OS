<?php

declare(strict_types=1);

namespace App;

use App\Cron\CronRunner;
use App\Cron\Jobs\PurgeRateLimitsJob;
use App\Cron\Jobs\PurgeSecurityEventsJob;
use App\Http\Kernel;
use App\Http\Routes;
use App\Infrastructure\Database\Connection;
use App\Mail\CaptureTransport;
use App\Mail\Mailer;
use App\Mail\NullTransport;
use App\Mail\SmtpTransport;
use App\Security\Crypto\CryptoProvider;
use App\Security\Crypto\FieldCrypto;
use App\Security\Crypto\Keyring;
use App\Security\Crypto\OpenSslProvider;
use App\Security\Crypto\SodiumProvider;
use App\Security\CsrfGuard;
use App\Security\IdentityRepository;
use App\Security\PasswordHasher;
use App\Security\RateLimiter;
use App\Security\SecurityEventLogger;
use App\Security\SessionManager;
use App\Support\Clock;
use App\Support\Config;
use App\Support\Logger;

/**
 * Conteneur de services minimal (instanciation paresseuse, une instance par requête).
 * Les tests peuvent remplacer un service avant son premier usage (horloge, session…).
 */
class App
{
    /** @var array<string, object> */
    private array $services = [];

    public function __construct(public readonly Config $config)
    {
    }

    public function set(string $id, object $service): void
    {
        $this->services[$id] = $service;
    }

    public function clock(): Clock
    {
        return $this->resolve('clock', fn () => new Clock($this->config->timezone));
    }

    public function logger(): Logger
    {
        return $this->resolve('logger', fn () => new Logger($this->config->storagePath('logs'), $this->clock()));
    }

    public function db(): Connection
    {
        return $this->resolve('db', fn () => new Connection($this->config));
    }

    public function securityEvents(): SecurityEventLogger
    {
        return $this->resolve('security_events', fn () => new SecurityEventLogger($this->db(), $this->logger(), $this->clock()));
    }

    public function rateLimiter(): RateLimiter
    {
        return $this->resolve('rate_limiter', fn () => new RateLimiter($this->db(), $this->config->appKey, $this->clock()));
    }

    public function sessions(): SessionManager
    {
        return $this->resolve('sessions', fn () => new SessionManager($this->config, $this->clock()));
    }

    public function csrf(): CsrfGuard
    {
        return $this->resolve('csrf', fn () => new CsrfGuard($this->config->appUrl));
    }

    public function identities(): IdentityRepository
    {
        return $this->resolve('identities', fn () => new IdentityRepository($this->db()));
    }

    public function passwords(): PasswordHasher
    {
        return $this->resolve('passwords', fn () => new PasswordHasher($this->config->cryptoProvider === 'sodium'));
    }

    public function cryptoProvider(): CryptoProvider
    {
        return $this->resolve('crypto_provider', fn () => $this->config->cryptoProvider === 'sodium' ? new SodiumProvider() : new OpenSslProvider());
    }

    public function fieldCrypto(): FieldCrypto
    {
        return $this->resolve('field_crypto', function () {
            $readers = [new OpenSslProvider()];
            if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
                $readers[] = new SodiumProvider();
            }

            return new FieldCrypto(Keyring::load($this->config->keysPath), $this->cryptoProvider(), ...$readers);
        });
    }

    public function mailer(): Mailer
    {
        return $this->resolve('mailer', function () {
            $transport = match ($this->config->mailTransport) {
                'smtp' => new SmtpTransport($this->config),
                'null' => new NullTransport(),
                default => new CaptureTransport($this->config->storagePath('mail-capture'), $this->clock()),
            };

            return new Mailer($transport, $this->config, $this->logger());
        });
    }

    public function kernel(): Kernel
    {
        return $this->resolve('kernel', fn () => new Kernel($this, Routes::build($this)));
    }

    public function cronRunner(): CronRunner
    {
        return $this->resolve('cron_runner', fn () => new CronRunner($this->db(), $this->logger(), $this->securityEvents(), $this->clock(), [
            new PurgeRateLimitsJob($this->rateLimiter()),
            new PurgeSecurityEventsJob($this->securityEvents()),
        ]));
    }

    /**
     * @template T of object
     * @param callable(): T $factory
     * @return T
     */
    private function resolve(string $id, callable $factory): object
    {
        if (!isset($this->services[$id])) {
            $this->services[$id] = $factory();
        }

        /** @var T */
        return $this->services[$id];
    }
}
