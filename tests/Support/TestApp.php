<?php

declare(strict_types=1);

namespace Tests\Support;

use App\App;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Database\Migrator;
use App\Support\Config;
use App\Support\Env;
use App\Support\FrozenClock;

/**
 * Fabrique d'application de test : répertoire de travail temporaire (storage, clés),
 * base MySQL/MariaDB de test, horloge figée, session simulée.
 */
final class TestApp
{
    private static bool $migrated = false;

    /**
     * @param array<string, string> $overrides
     */
    public static function make(array $overrides = [], string $now = '2026-11-12 09:00:00'): App
    {
        $base = sys_get_temp_dir() . '/agencyos-test-' . bin2hex(random_bytes(4));
        foreach (['storage/logs', 'storage/sessions', 'storage/mail-capture', 'storage/backups', 'config/keys'] as $dir) {
            mkdir($base . '/' . $dir, 0700, true);
        }
        self::writeKey($base . '/config/keys', 'k1');

        $values = $overrides + [
            'APP_ENV' => 'testing',
            'APP_PROFILE' => 'portfolio',
            'APP_URL' => 'https://agency-os.test',
            'APP_KEY' => str_repeat('a1', 32),
            'DB_HOST' => (string) getenv('TEST_DB_HOST'),
            'DB_PORT' => (string) getenv('TEST_DB_PORT'),
            'DB_NAME' => (string) getenv('TEST_DB_NAME'),
            'DB_USER' => (string) getenv('TEST_DB_USER'),
            'DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'),
            'KEYS_PATH' => $base . '/config/keys',
            'MAIL_TRANSPORT' => 'capture',
        ];
        $app = new App(Config::fromEnv(Env::fromArray($values), $base));
        $app->set('clock', new FrozenClock($now));
        $app->set('sessions', new TestSessionManager($app->config, $app->clock()));

        return $app;
    }

    public static function writeKey(string $dir, string $version, bool $activate = true): void
    {
        file_put_contents($dir . '/data.' . $version, base64_encode(random_bytes(32)));
        $manifestPath = $dir . '/keyring.json';
        $manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : ['active' => $version, 'accepted' => []];
        $manifest['accepted'][] = $version;
        if ($activate) {
            $manifest['active'] = $version;
        }
        file_put_contents($manifestPath, json_encode($manifest));
    }

    /** Recrée le schéma une fois par exécution, puis vide les tables entre les tests. */
    public static function freshDatabase(Connection $db): void
    {
        if (!self::$migrated) {
            $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($db->fetchAll('SHOW TABLES') as $row) {
                $db->pdo()->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string) array_values($row)[0]) . '`');
            }
            $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
            (new Migrator($db, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
            self::$migrated = true;
        }
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($db->fetchAll('SHOW TABLES') as $row) {
            $table = (string) array_values($row)[0];
            if ($table !== 'schema_migrations') {
                $db->pdo()->exec('TRUNCATE TABLE `' . str_replace('`', '', $table) . '`');
            }
        }
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
