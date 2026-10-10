<?php

declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;

class MigrationTest extends TestCase
{
    public function testSqliteIndexMigrationRestoresRequestLookups(): void
    {
        require_once __DIR__ . '/support.php';
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        apm_migrate_sqlite($pdo);

        $indexes = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'apm_requests'"
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->assertContains('idx_apm_requests_request_dt', $indexes);
        $this->assertContains('idx_apm_requests_dt_total_time', $indexes);
        $this->assertContains('idx_apm_requests_token', $indexes);
        $this->assertNotContains('idx_apm_requests_timestamp', $indexes);
    }
}
