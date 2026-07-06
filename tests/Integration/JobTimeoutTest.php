<?php declare(strict_types=1);

namespace Phalcon\Queue\Tests\Integration;

use Phalcon\Queue\Exceptions\TimeoutException;
use Phalcon\Queue\Tests\Support\IntegrationTestCase;
use Phalcon\Queue\Tests\Support\Jobs\TimeoutJob;

/**
 * A job that runs past its timeout is aborted by the SIGALRM watchdog
 * (Job::startJobTimer) and lands in jobs_failed as a TimeoutException.
 */
final class JobTimeoutTest extends IntegrationTestCase
{
    public function testSlowJobTimesOutAndFails(): void
    {
        // 1-second timeout, but the job sleeps for 30s.
        $dispatcher = dispatch(new TimeoutJob(1))->queue($this->queue);
        unset($dispatcher);

        $this->startMaster();

        $failed = $this->waitFor(fn () => $this->countRows('jobs_failed') >= 1, 30.0);

        $this->assertTrue($failed, 'timed-out job was not recorded. Master stderr: ' . $this->master->getErrorOutput());

        $row       = $this->db()->fetchOne('SELECT * FROM jobs_failed LIMIT 1');
        $exception = json_decode($row['exception'], true);

        $this->assertSame(TimeoutException::class, $exception['class']);
    }
}
