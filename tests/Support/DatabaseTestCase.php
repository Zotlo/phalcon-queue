<?php declare(strict_types=1);

namespace Phalcon\Queue\Tests\Support;

use Phalcon\Db\Adapter\AdapterInterface;
use Phalcon\Di\DiInterface;
use Phalcon\Queue\Connector;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need a real (isolated, throw-away) SQLite storage.
 *
 * Each test gets its own temp database file and freshly migrated tables, so
 * tests never see each other's rows and never touch the project's db.sqlite.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected string $dbPath;
    protected DiInterface $di;
    protected Connector $connector;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('phalcon')) {
            $this->markTestSkipped('ext-phalcon is required.');
        }

        $this->dbPath = tempnam(sys_get_temp_dir(), 'pq_db_') ?: (sys_get_temp_dir() . '/pq_' . uniqid());
        // tempnam creates an empty file; remove it so SQLite starts clean.
        @unlink($this->dbPath);

        $this->di        = TestDi::create($this->dbPath, $this->queueName());
        $this->connector = new Connector($this->di);
        $this->connector->adapter->checkTableAndMigrateIfNecessary();
    }

    protected function tearDown(): void
    {
        @unlink($this->dbPath);
        parent::tearDown();
    }

    protected function queueName(): string
    {
        return 'default';
    }

    protected function db(): AdapterInterface
    {
        return $this->di->get('db');
    }

    protected function countRows(string $table): int
    {
        $row = $this->db()->fetchOne("SELECT COUNT(*) AS c FROM {$table}");
        return (int) ($row['c'] ?? 0);
    }
}
