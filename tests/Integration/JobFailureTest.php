<?php declare(strict_types=1);

namespace Phalcon\Queue\Tests\Integration;

use Phalcon\Queue\Tests\Support\IntegrationTestCase;
use Phalcon\Queue\Tests\Support\Jobs\FailingJob;

/**
 * A throwing job is recorded in jobs_failed with its exception detail.
 */
final class JobFailureTest extends IntegrationTestCase
{
    public function testFailingJobIsRecordedInFailedTable(): void
    {
        $dispatcher = dispatch(new FailingJob())->queue($this->queue);
        unset($dispatcher);

        $this->startMaster();

        $failed = $this->waitFor(fn () => $this->countRows('jobs_failed') >= 1, 30.0);

        $this->assertTrue($failed, 'failed job was not recorded. Master stderr: ' . $this->master->getErrorOutput());

        $row       = $this->db()->fetchOne('SELECT * FROM jobs_failed LIMIT 1');
        $exception = json_decode($row['exception'], true);

        $this->assertSame(\RuntimeException::class, $exception['class']);
        $this->assertStringContainsString('always fails', $exception['message']);
        $this->assertSame($this->queue, $row['queue']);
    }
}
