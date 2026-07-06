<?php declare(strict_types=1);

namespace Phalcon\Queue\Tests\Integration;

use Phalcon\Queue\Tests\Support\IntegrationTestCase;
use Phalcon\Queue\Tests\Support\Jobs\SentinelJob;

/**
 * Scenario #1: boot a real master, dispatch a job, let a worker pick it up
 * and run it, then confirm the job completed (its marker was written and the
 * row was removed from the `jobs` table).
 */
final class QueueWorkerTest extends IntegrationTestCase
{
    public function testMasterProcessesADispatchedJob(): void
    {
        $marker = sys_get_temp_dir() . '/pq_marker_' . uniqid();
        @unlink($marker);

        // Enqueue onto this test's isolated queue/database.
        $dispatcher = dispatch(new SentinelJob($marker))->queue($this->queue);
        unset($dispatcher);

        $this->assertSame(1, $this->countRows('jobs'), 'job should be pending before the master starts');

        $this->startMaster();

        $done = $this->waitFor(fn () => file_exists($marker), 30.0);

        $this->assertTrue($done, 'the worker never executed the job (marker missing). '
            . 'Master stderr: ' . $this->master->getErrorOutput());
        $this->assertStringStartsWith('done@', (string) file_get_contents($marker));

        // A completed job is deleted from the live table.
        $this->assertTrue(
            $this->waitFor(fn () => $this->countRows('jobs') === 0, 10.0),
            'completed job was not removed from the jobs table'
        );

        @unlink($marker);
    }
}
