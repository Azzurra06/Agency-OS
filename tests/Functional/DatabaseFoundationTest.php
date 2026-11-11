<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Database\OrgId;
use App\Infrastructure\Database\OrgScopedRepository;
use LogicException;
use RuntimeException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Fixtures;

final class DatabaseFoundationTest extends DatabaseTestCase
{
    public function testMigrationsAreAppliedOnceWithChecksums(): void
    {
        $migrator = new Migrator($this->app->db(), dirname(__DIR__, 2) . '/database/migrations');
        self::assertSame([], $migrator->migrate(), 'Aucune migration ne doit être rejouée.');
        self::assertSame(5, $this->countRows('schema_migrations'));
        foreach ($this->app->db()->fetchAll('SELECT checksum FROM schema_migrations') as $row) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $row['checksum']);
        }
    }

    public function testModifiedMigrationIsDetected(): void
    {
        $dir = sys_get_temp_dir() . '/agencyos-mig-' . bin2hex(random_bytes(4));
        mkdir($dir);
        foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') ?: [] as $file) {
            copy($file, $dir . '/' . basename($file));
        }
        file_put_contents($dir . '/0001_identity.sql', "\n-- modification\n", FILE_APPEND);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('modifiée après application');
        (new Migrator($this->app->db(), $dir))->migrate();
    }

    public function testStatementSplitterIgnoresComments(): void
    {
        $statements = Migrator::splitStatements("-- commentaire ; ignoré\nCREATE TABLE a (id INT);\n\nCREATE TABLE b (id INT);\n");
        self::assertSame(['CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)'], $statements);
    }

    public function testOrganisationDeletionCascades(): void
    {
        $member = Fixtures::member($this->app->db(), 'claire@example.test');
        $this->app->db()->run('DELETE FROM organizations WHERE id = :id', ['id' => $member['org_id']]);
        self::assertSame(0, $this->countRows('organization_members', 'organization_id = :id', ['id' => $member['org_id']]));
    }

    public function testDemoOrganisationRequiresExpiry(): void
    {
        $this->expectException(\PDOException::class);
        $this->app->db()->run(
            "INSERT INTO organizations (public_id, name, is_demo, demo_expires_at, created_at, updated_at)
             VALUES ('01ABCDEFGHJKMNPQRSTVWXYZ00', 'Démo', 1, NULL, NOW(), NOW())",
        );
    }

    public function testScopedRepositoryRefusesUnscopedQueries(): void
    {
        $repository = new class ($this->app->db()) extends OrgScopedRepository {
            public function members(OrgId $org): array
            {
                return $this->scopedAll($org, 'SELECT user_id FROM organization_members WHERE organization_id = :org');
            }

            public function leak(OrgId $org): array
            {
                return $this->scopedAll($org, 'SELECT user_id FROM organization_members');
            }
        };
        $a = Fixtures::member($this->app->db(), 'a@example.test');
        $b = Fixtures::member($this->app->db(), 'b@example.test');

        self::assertSame([['user_id' => $a['user_id']]], $repository->members(new OrgId($a['org_id'])));
        self::assertSame([['user_id' => $b['user_id']]], $repository->members(new OrgId($b['org_id'])));

        $this->expectException(LogicException::class);
        $repository->leak(new OrgId($a['org_id']));
    }
}
