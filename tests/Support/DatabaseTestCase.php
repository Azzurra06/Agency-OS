<?php

declare(strict_types=1);

namespace Tests\Support;

use App\App;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected App $app;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->app = TestApp::make();
        TestApp::freshDatabase($this->app->db());
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    protected function countRows(string $table, string $where = '1 = 1', array $params = []): int
    {
        return (int) ($this->app->db()->fetchOne("SELECT COUNT(*) AS n FROM {$table} WHERE {$where}", $params)['n'] ?? 0);
    }
}
