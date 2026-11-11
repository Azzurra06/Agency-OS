<?php

declare(strict_types=1);

namespace App;

use App\Application\AccountService;
use App\Application\AuthService;
use App\Application\CatalogService;
use App\Application\DemoService;
use App\Application\DiagnosticService;
use App\Application\ExportService;
use App\Application\FinancialProfileService;
use App\Application\HistoryService;
use App\Application\PricingService;
use App\Application\RecurringService;
use App\Application\StrategyService;
use App\Cron\CronRunner;
use App\Cron\Jobs\DailyMaintenanceJob;
use App\Cron\Jobs\PurgeDemoJob;
use App\Cron\Jobs\PurgeRateLimitsJob;
use App\Cron\Jobs\PurgeSecurityEventsJob;
use App\Cron\Jobs\SnapshotDailyJob;
use App\Http\Kernel;
use App\Http\Routes;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Repositories\DiagnosticRepository;
use App\Infrastructure\Repositories\FinancialProfileRepository;
use App\Infrastructure\Repositories\OrganizationRepository;
use App\Infrastructure\Repositories\PricingRepository;
use App\Infrastructure\Repositories\RecurringRepository;
use App\Infrastructure\Repositories\ServiceRepository;
use App\Infrastructure\Repositories\SnapshotRepository;
use App\Infrastructure\Repositories\StrategyRepository;
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

    public function organizations(): OrganizationRepository
    {
        return $this->resolve('organizations', fn () => new OrganizationRepository($this->db(), $this->clock()));
    }

    public function serviceRepository(): ServiceRepository
    {
        return $this->resolve('service_repository', fn () => new ServiceRepository($this->db(), $this->clock()));
    }

    public function financialProfiles(): FinancialProfileService
    {
        return $this->resolve('financial_profiles', fn () => new FinancialProfileService(
            $this->db(),
            new FinancialProfileRepository($this->db(), $this->fieldCrypto(), $this->clock()),
            $this->organizations(),
            $this->serviceRepository(),
        ));
    }

    public function catalog(): CatalogService
    {
        return $this->resolve('catalog', fn () => new CatalogService($this->serviceRepository()));
    }

    public function auth(): AuthService
    {
        return $this->resolve('auth', fn () => new AuthService(
            $this->db(), $this->passwords(), $this->rateLimiter(), $this->securityEvents(), $this->sessions(), $this->mailer(), $this->config, $this->clock(),
        ));
    }

    public function diagnosticRepository(): DiagnosticRepository
    {
        return $this->resolve('diagnostic_repository', fn () => new DiagnosticRepository($this->db(), $this->clock()));
    }

    public function diagnostics(): DiagnosticService
    {
        return $this->resolve('diagnostics', fn () => new DiagnosticService($this->diagnosticRepository(), $this->organizations(), $this->financialProfiles()));
    }

    public function pricingRepository(): PricingRepository
    {
        return $this->resolve('pricing_repository', fn () => new PricingRepository($this->db(), $this->fieldCrypto(), $this->clock()));
    }

    public function pricing(): PricingService
    {
        return $this->resolve('pricing', fn () => new PricingService($this->pricingRepository(), $this->financialProfiles()));
    }

    public function recurringRepository(): RecurringRepository
    {
        return $this->resolve('recurring_repository', fn () => new RecurringRepository($this->db(), $this->fieldCrypto(), $this->clock()));
    }

    public function recurring(): RecurringService
    {
        return $this->resolve('recurring', fn () => new RecurringService($this->recurringRepository(), $this->financialProfiles()));
    }

    public function strategyRepository(): StrategyRepository
    {
        return $this->resolve('strategy_repository', fn () => new StrategyRepository($this->db(), $this->fieldCrypto(), $this->clock()));
    }

    public function strategy(): StrategyService
    {
        return $this->resolve('strategy', fn () => new StrategyService(
            $this->strategyRepository(), $this->financialProfiles(), $this->serviceRepository(), $this->pricing(), $this->recurring(),
            $this->diagnosticRepository(), $this->organizations(), $this->clock(),
        ));
    }

    public function snapshotRepository(): SnapshotRepository
    {
        return $this->resolve('snapshot_repository', fn () => new SnapshotRepository($this->db(), $this->fieldCrypto()));
    }

    public function history(): HistoryService
    {
        return $this->resolve('history', fn () => new HistoryService(
            $this->snapshotRepository(), $this->financialProfiles(), $this->recurring(), $this->recurringRepository(), $this->diagnosticRepository(), $this->strategy(), $this->clock(),
        ));
    }

    public function exporter(): ExportService
    {
        return $this->resolve('exporter', fn () => new ExportService(
            $this->db(), $this->organizations(), $this->financialProfiles(), new FinancialProfileRepository($this->db(), $this->fieldCrypto(), $this->clock()),
            $this->serviceRepository(), $this->diagnosticRepository(), $this->diagnostics(), $this->pricingRepository(), $this->pricing(), $this->recurring(),
            $this->strategy(), $this->snapshotRepository(), $this->clock(),
        ));
    }

    public function account(): AccountService
    {
        return $this->resolve('account', fn () => new AccountService(
            $this->db(), $this->passwords(), $this->rateLimiter(), $this->securityEvents(), $this->mailer(), $this->config, $this->clock(), $this->exporter(),
        ));
    }

    public function demos(): DemoService
    {
        return $this->resolve('demos', fn () => new DemoService(
            $this->db(), $this->financialProfiles(), $this->serviceRepository(), $this->diagnostics(), $this->pricing(), $this->recurring(), $this->strategy(), $this->history(),
            $this->rateLimiter(), $this->securityEvents(), $this->sessions(), $this->clock(),
        ));
    }

    public function kernel(): Kernel
    {
        return $this->resolve('kernel', fn () => new Kernel($this, Routes::build($this)));
    }

    public function cronRunner(): CronRunner
    {
        return $this->resolve('cron_runner', fn () => new CronRunner($this->db(), $this->logger(), $this->securityEvents(), $this->clock(), [
            new PurgeDemoJob($this->demos()),
            new PurgeRateLimitsJob($this->rateLimiter()),
            new PurgeSecurityEventsJob($this->securityEvents()),
            new SnapshotDailyJob($this->snapshotRepository(), $this->history(), $this->clock()),
            new DailyMaintenanceJob($this->account(), $this->strategy(), $this->db()),
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
