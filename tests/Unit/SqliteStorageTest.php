<?php declare(strict_types=1);

namespace Phalcon\Queue\Tests\Unit;

use Phalcon\Queue\Jobs\Status;
use Phalcon\Queue\Tests\Support\DatabaseTestCase;
use Phalcon\Queue\Tests\Support\Jobs\SentinelJob;

final class SqliteStorageTest extends DatabaseTestCase
{
    public function testMigrationCreatesTables(): void
    {
        $this->assertTrue($this->db()->tableExists('jobs'));
        $this->assertTrue($this->db()->tableExists('jobs_locked'));
        $this->assertTrue($this->db()->tableExists('jobs_failed'));
    }

    public function testInsertAndFetchPendingJob(): void
    {
        $job = new SentinelJob('/tmp/x');
        $this->connector->adapter->insertJob($job);

        $pending = $this->connector->adapter->getPendingJob('default');

        $this->assertIsObject($pending);
        $this->assertSame($job->id, $pending->job_id);
        $this->assertCount(1, $this->connector->adapter->getPendingJobs('default'));
    }

    public function testDelayedJobIsNotPending(): void
    {
        $job = new SentinelJob('/tmp/x');
        $job->setDelay(3600);
        $this->connector->adapter->insertJob($job);

        $this->assertFalse($this->connector->adapter->getPendingJob('default'));
        $this->assertSame(1, $this->countRows('jobs')); // still stored, just not due
    }

    public function testPendingJobsAreScopedByQueue(): void
    {
        $a = new SentinelJob('/tmp/a');
        $a->setQueue('alpha');
        $this->connector->adapter->insertJob($a);

        $b = new SentinelJob('/tmp/b');
        $b->setQueue('beta');
        $this->connector->adapter->insertJob($b);

        $this->assertCount(1, $this->connector->adapter->getPendingJobs('alpha'));
        $this->assertCount(1, $this->connector->adapter->getPendingJobs('beta'));
        $this->assertCount(0, $this->connector->adapter->getPendingJobs('gamma'));
    }

    public function testMarkAsProcessingReservesAndIncrementsAttempts(): void
    {
        $this->connector->adapter->insertJob(new SentinelJob('/tmp/x'));
        $row = $this->connector->adapter->getPendingJob('default');

        $this->assertTrue($this->connector->adapter->markAsProcessing($row));

        $stored = $this->db()->fetchOne('SELECT * FROM jobs LIMIT 1');
        $this->assertNotNull($stored['reserved_at']);
        $this->assertSame(1, (int) $stored['attempts']);

        // A reserved job is no longer handed out as pending.
        $this->assertFalse($this->connector->adapter->getPendingJob('default'));
    }

    public function testMarkAsCompletedRemovesJob(): void
    {
        $this->connector->adapter->insertJob(new SentinelJob('/tmp/x'));
        $row = $this->connector->adapter->getPendingJob('default');

        $this->connector->adapter->markAsCompleted($row);

        $this->assertSame(0, $this->countRows('jobs'));
    }

    public function testMarkAsFailedCopiesToFailedTable(): void
    {
        $this->connector->adapter->insertJob(new SentinelJob('/tmp/x'));
        $row = $this->connector->adapter->getPendingJob('default');

        $this->assertTrue(
            $this->connector->adapter->markAsFailed($row, new \RuntimeException('boom'))
        );

        $failed = $this->db()->fetchOne('SELECT * FROM jobs_failed LIMIT 1');
        $this->assertSame($row->job_id, $failed['job_id']);

        $exception = json_decode($failed['exception'], true);
        $this->assertSame(\RuntimeException::class, $exception['class']);
        $this->assertSame('boom', $exception['message']);
    }

    public function testLockIsExclusiveAndReleasable(): void
    {
        $key = 'JOB_LOCK_test';

        $this->assertTrue($this->connector->adapter->lock($key), 'first lock succeeds');
        $this->assertFalse($this->connector->adapter->lock($key), 'second lock is refused');

        $this->assertTrue($this->connector->adapter->unlock($key));
        $this->assertTrue($this->connector->adapter->lock($key), 'lock reusable after unlock');
    }

    public function testGetJobStatusTransitions(): void
    {
        $job = new SentinelJob('/tmp/x');
        $this->connector->adapter->insertJob($job);

        // Freshly inserted, unreserved.
        $this->assertSame(Status::PENDING, $this->connector->adapter->getJobStatus($job));

        // Reserved by a worker.
        $row = $this->connector->adapter->getPendingJob('default');
        $this->connector->adapter->markAsProcessing($row);
        $this->assertSame(Status::PROCESSING, $this->connector->adapter->getJobStatus($job));

        // Recorded as failed, then removed from the live table.
        $this->connector->adapter->markAsFailed($row, new \RuntimeException('boom'));
        $this->connector->adapter->markAsCompleted($row);
        $this->assertSame(Status::FAILED, $this->connector->adapter->getJobStatus($job));
    }

    public function testGetJobStatusTreatsUnknownJobAsCompleted(): void
    {
        // Documents current behaviour: a job absent from both tables is
        // reported COMPLETED (getJobStatus only returns UNKNOWN on a DB error).
        $ghost = new SentinelJob('/tmp/x');
        $ghost->id = 'job-does-not-exist';

        $this->assertSame(Status::COMPLETED, $this->connector->adapter->getJobStatus($ghost));
    }
}
