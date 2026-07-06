<?php declare(strict_types=1);

namespace Phalcon\Queue\Tests\Support;

use Symfony\Component\Process\Process;

/**
 * Base class for end-to-end tests that spawn a real master process
 * (tests/console.php Queue run <queue>) which in turn spawns worker
 * subprocesses, exactly like production.
 *
 * Each test gets a unique queue name so the Unix domain socket
 * (/tmp/phalcon_queue/socket_<queue>.sock) and the DB rows are isolated.
 */
abstract class IntegrationTestCase extends DatabaseTestCase
{
    protected const CONSOLE = __DIR__ . '/../console.php';

    protected string $queue;
    protected ?Process $master = null;

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_signal')) {
            $this->markTestSkipped('ext-pcntl is required for the process model.');
        }
        if (\stripos(PHP_OS, 'WIN') === 0) {
            $this->markTestSkipped('The master/worker model is POSIX-only.');
        }

        // A distinct queue per test => isolated socket + rows.
        $this->queue = $this->makeQueueName();

        parent::setUp();
    }

    /**
     * Queue name for this test. Override to force a specific name (e.g. the
     * async() helper always enqueues onto the 'default' queue).
     */
    protected function makeQueueName(): string
    {
        return 'it_' . \substr(\md5(static::class . $this->name()), 0, 8);
    }

    protected function queueName(): string
    {
        // setUp() sets $this->queue before calling parent::setUp(),
        // which in turn builds the DI using this queue name.
        return $this->queue;
    }

    protected function tearDown(): void
    {
        $this->stopMaster();

        // Best-effort cleanup of any lingering worker for this queue.
        @\exec('pkill -f ' . \escapeshellarg('console.php QueueWorker run ' . $this->queue) . ' 2>/dev/null');

        $socket = \sys_get_temp_dir() . '/phalcon_queue/socket_' . $this->queue . '.sock';
        @\unlink($socket);

        parent::tearDown();
    }

    /**
     * Start the master process for this test's queue.
     */
    protected function startMaster(): Process
    {
        $env = [
            'PHALCON_QUEUE_TEST_DB'    => $this->dbPath,
            'PHALCON_QUEUE_TEST_QUEUE' => $this->queue,
        ];
        // Forward an explicit debug override to the master (default is on).
        if (($debug = getenv('PHALCON_QUEUE_TEST_DEBUG')) !== false) {
            $env['PHALCON_QUEUE_TEST_DEBUG'] = $debug;
        }

        $console = \realpath(self::CONSOLE);

        $this->master = new Process(
            [PHP_BINARY, $console, 'Queue', 'run', $this->queue],
            \dirname($console, 2), // project root as CWD (monitor.log lands there, gitignored)
            $env
        );
        $this->master->setTimeout(null);
        $this->master->start();

        return $this->master;
    }

    protected function stopMaster(): void
    {
        if ($this->master !== null && $this->master->isRunning()) {
            // Give the graceful SIGTERM handler a moment, then hard-kill.
            $this->master->stop(3, SIGTERM);
        }
        $this->master = null;
    }

    /**
     * Poll $condition until it returns true or $timeout seconds elapse.
     */
    protected function waitFor(callable $condition, float $timeout = 30.0, float $interval = 0.2): bool
    {
        $deadline = \microtime(true) + $timeout;
        do {
            if ($condition()) {
                return true;
            }
            \usleep((int) ($interval * 1_000_000));
        } while (\microtime(true) < $deadline);

        return $condition();
    }
}
