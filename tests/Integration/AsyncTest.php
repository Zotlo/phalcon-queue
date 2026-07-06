<?php declare(strict_types=1);

namespace Phalcon\Queue\Tests\Integration;

use Phalcon\Queue\Tests\Support\IntegrationTestCase;

/**
 * Scenario #2: the async() helper serializes a closure, a worker picks it up
 * and actually executes it in another process.
 */
final class AsyncTest extends IntegrationTestCase
{
    /**
     * async() always enqueues onto the 'default' queue, so the master must
     * supervise that queue too. The database is still isolated per test.
     */
    protected function makeQueueName(): string
    {
        return 'default';
    }

    public function testAsyncClosureRunsInAWorker(): void
    {
        $marker   = sys_get_temp_dir() . '/pq_async_' . uniqid();
        $expected = 'ran-' . uniqid();
        @unlink($marker);

        async(function () use ($marker, $expected) {
            file_put_contents($marker, $expected, LOCK_EX);
        });

        $this->assertSame(1, $this->countRows('jobs'), 'async() should have enqueued a job');

        $this->startMaster();

        $ok = $this->waitFor(fn () => file_exists($marker), 30.0);

        $this->assertTrue($ok, 'async closure never ran. Master stderr: ' . $this->master->getErrorOutput());
        $this->assertSame($expected, file_get_contents($marker));

        @unlink($marker);
    }
}
