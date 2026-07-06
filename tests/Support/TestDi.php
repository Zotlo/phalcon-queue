<?php declare(strict_types=1);

namespace Phalcon\Queue\Tests\Support;

use Phalcon\Config\Config;
use Phalcon\Db\Adapter\Pdo\Sqlite;
use Phalcon\Di\DiInterface;
use Phalcon\Di\FactoryDefault\Cli as CliDI;
use Phalcon\Queue\ServiceProvider;

/**
 * Builds an isolated Phalcon CLI DI container for the test suite.
 *
 * The container is intentionally SQLite-only: it needs no external server,
 * so both the in-process unit tests and the spawned master/worker processes
 * can share the same on-disk database file for deterministic assertions.
 */
final class TestDi
{
    /**
     * Create (and register as default) a DI container backed by $dbPath.
     */
    public static function create(string $dbPath, string $queue = 'default'): DiInterface
    {
        $di = new CliDI();

        $di->register(new ServiceProvider());

        $di->setShared('config', function () use ($queue) {
            return new Config([
                'queues' => [
                    'adapter'     => 'sqlite',
                    'supervisors' => [
                        [
                            'queue'           => $queue,
                            'balance'         => 'auto',
                            'processes'       => 2,
                            'tries'           => 0,
                            'timeout'         => 30,
                            'balanceMaxShift' => 1,
                            'balanceCooldown' => 1,
                            'debug'           => self::debugEnabled(),
                        ],
                    ],
                ],
            ]);
        });

        $di->setShared('db', function () use ($dbPath) {
            return new Sqlite(['dbname' => $dbPath]);
        });

        // Global helpers (dispatch/async/await) resolve the default container.
        \Phalcon\Di\Di::setDefault($di);

        return $di;
    }

    /**
     * Debug logging is ON by default during tests so the master's lifecycle
     * (pending/processing counts, scaling, worker PIDs) shows up in the log.
     * Set PHALCON_QUEUE_TEST_DEBUG=0 (or false) to silence it.
     */
    private static function debugEnabled(): bool
    {
        $value = getenv('PHALCON_QUEUE_TEST_DEBUG');
        if ($value === false || $value === '') {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}

