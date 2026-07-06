<?php declare(strict_types=1);

namespace Phalcon\Queue\Tests\Unit;

use Phalcon\Config\Config;
use Phalcon\Di\FactoryDefault\Cli as CliDI;
use Phalcon\Queue\Connector;
use Phalcon\Queue\Connectors\SQLite;
use Phalcon\Queue\Exceptions\ConnectorException;
use Phalcon\Queue\Tests\Support\TestDi;
use PHPUnit\Framework\TestCase;

final class ConnectorTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('phalcon')) {
            $this->markTestSkipped('ext-phalcon is required.');
        }
    }

    public function testSelectsSqliteAdapterFromConfig(): void
    {
        $dbPath = sys_get_temp_dir() . '/pq_conn_' . uniqid() . '.sqlite';
        $di     = TestDi::create($dbPath);

        $connector = new Connector($di);

        $this->assertSame('sqlite', $connector->connectorName);
        $this->assertInstanceOf(SQLite::class, $connector->adapter);

        @unlink($dbPath);
    }

    public function testThrowsOnUnsupportedAdapter(): void
    {
        $di = new CliDI();
        $di->setShared('config', fn () => new Config([
            'queues' => [
                'adapter'     => 'cassandra',
                'supervisors' => [['queue' => 'default']],
            ],
        ]));

        $this->expectException(ConnectorException::class);

        new Connector($di);
    }
}
