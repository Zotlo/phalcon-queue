<?php declare(strict_types=1);

namespace Phalcon\Queue\Tests\Integration;

use Phalcon\Queue\Tests\Support\IntegrationTestCase;
use Phalcon\Queue\Tests\Support\Jobs\SentinelJob;

/**
 * Exercises the master's balance strategies and worker scaling.
 */
final class BalancingTest extends IntegrationTestCase
{
    private function enqueue(int $count, int $sleepSeconds = 0): void
    {
        for ($i = 0; $i < $count; $i++) {
            $dispatcher = dispatch(new SentinelJob(sys_get_temp_dir() . '/pq_bal_' . uniqid() . "_$i", $sleepSeconds))
                ->queue($this->queue);
            unset($dispatcher);
        }
    }

    public function testSimpleBalanceProcessesAllJobs(): void
    {
        $this->balance   = 'simple';
        $this->processes = 3;
        $this->maxShift  = 3;

        $this->enqueue(4);
        $this->assertSame(4, $this->countRows('jobs'));

        $this->startMaster();

        $this->assertTrue(
            $this->waitFor(fn () => $this->countRows('jobs') === 0, 40.0),
            "'simple' balance did not drain the queue. Master stderr: " . $this->master->getErrorOutput()
        );
    }

    public function testAutoBalanceScalesUpForConcurrentJobs(): void
    {
        $this->balance   = 'auto';
        $this->processes = 3;
        $this->maxShift  = 3;

        // Slow jobs so several must run concurrently to drain in time.
        $this->enqueue(4, 2);

        $this->startMaster();

        $this->assertTrue(
            $this->waitFor(fn () => $this->countRows('jobs') === 0, 40.0),
            "'auto' balance did not drain the queue. Master stderr: " . $this->master->getErrorOutput()
        );

        // The master should have spawned more than one worker to keep up.
        $this->assertGreaterThanOrEqual(
            2,
            $this->spawnedWorkerCount(),
            'expected the master to scale up to multiple workers'
        );
    }
}
