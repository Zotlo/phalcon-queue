<?php declare(strict_types=1);

namespace Phalcon\Queue\Tests\Unit;

use Phalcon\Queue\Jobs\Status;
use Phalcon\Queue\Tests\Support\DatabaseTestCase;
use Phalcon\Queue\Tests\Support\Jobs\SentinelJob;

/**
 * await() polls getJobStatus() until the job reaches a terminal state.
 * These cases drive it from pre-arranged database state so they resolve
 * immediately (no worker, no blocking).
 *
 * NOTE: there is no "await() -> FAILED via a real worker" case on purpose:
 * the worker failure path leaves the row in `jobs` (reserved) while also
 * copying it to jobs_failed, so getJobStatus() reports PROCESSING forever
 * and a non-manageable await() would hang. We drive the FAILED branch from
 * a jobs_failed-only state instead.
 */
final class AwaitTest extends DatabaseTestCase
{
    public function testManageableReturnsPendingImmediately(): void
    {
        $job = new SentinelJob('/tmp/x');
        $this->connector->adapter->insertJob($job);

        $this->assertSame(Status::PENDING, await($job, true));
    }

    public function testManageableReturnsProcessingImmediately(): void
    {
        $job = new SentinelJob('/tmp/x');
        $this->connector->adapter->insertJob($job);

        $row = $this->connector->adapter->getPendingJob($this->queueName());
        $this->connector->adapter->markAsProcessing($row);

        $this->assertSame(Status::PROCESSING, await($job, true));
    }

    public function testResolvesToFailedWhenOnlyInFailedTable(): void
    {
        $job     = new SentinelJob('/tmp/x');
        $job->id = 'job-failed-1';

        $this->db()->query(
            'INSERT INTO jobs_failed (job_id, queue, payload, attempts, exception) VALUES (:j,:q,:p,:a,:e)',
            [
                'j' => $job->id,
                'q' => 'default',
                'p' => serialize($job),
                'a' => 1,
                'e' => json_encode(['message' => 'boom']),
            ]
        );

        $this->assertSame(Status::FAILED, await($job));
    }

    public function testResolvesToCompletedWhenAbsent(): void
    {
        // A job absent from both tables is treated as completed (see
        // SqliteStorageTest::testGetJobStatusTreatsUnknownJobAsCompleted).
        $job     = new SentinelJob('/tmp/x');
        $job->id = 'job-never-existed';

        $this->assertSame(Status::COMPLETED, await($job));
    }
}
