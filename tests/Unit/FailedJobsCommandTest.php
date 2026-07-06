<?php declare(strict_types=1);

namespace Phalcon\Queue\Tests\Unit;

use Phalcon\Queue\Commands\ListFailedJobCommand;
use Phalcon\Queue\Commands\RetryFailedJobCommand;
use Phalcon\Queue\Tests\Support\DatabaseTestCase;
use Phalcon\Queue\Tests\Support\Jobs\SentinelJob;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * queue:failed (ListFailedJobCommand) and queue:retry (RetryFailedJobCommand).
 *
 * Both commands branch on the config string $config->queues->adapter and only
 * implement 'database' and 'redis' — NOT the 'mysql'/'sqlite' values the real
 * Connector produces. So against the real adapter they silently no-op. These
 * tests pin that reality (and the retry bug below).
 */
final class FailedJobsCommandTest extends DatabaseTestCase
{
    private function insertFailedJob(string $jobId = 'job-abc'): void
    {
        $this->db()->query(
            'INSERT INTO jobs_failed (job_id, queue, payload, attempts, exception) VALUES (:j,:q,:p,:a,:e)',
            [
                'j' => $jobId,
                'q' => 'default',
                'p' => serialize(new SentinelJob('/tmp/x')),
                'a' => 2,
                'e' => json_encode(['message' => 'kaboom']),
            ]
        );
    }

    private function setAdapter(string $adapter): void
    {
        // The commands read the adapter as a plain config string.
        $this->di->get('config')->queues->adapter = $adapter;
    }

    private function runCommand(string $commandClass): CommandTester
    {
        $tester = new CommandTester((new $commandClass())->setDi($this->di));
        $tester->execute([]);
        return $tester;
    }

    public function testListRendersFailedJobsWithDatabaseAdapter(): void
    {
        $this->insertFailedJob('job-list-1');
        $this->setAdapter('database');

        $display = $this->runCommand(ListFailedJobCommand::class)->getDisplay();

        $this->assertStringContainsString('job-list-1', $display);
        $this->assertStringContainsString(SentinelJob::class, $display);
        $this->assertStringContainsString('kaboom', $display);
    }

    public function testListIsEmptyForSqliteAdapter(): void
    {
        // Documents the adapter-string mismatch: 'sqlite' hits the default
        // (do-nothing) branch, so nothing is listed even though a row exists.
        $this->insertFailedJob('job-list-2');
        $this->setAdapter('sqlite');

        $display = $this->runCommand(ListFailedJobCommand::class)->getDisplay();

        $this->assertStringNotContainsString('job-list-2', $display);
    }

    public function testRetryDoesNotRequeueOnMigratedSchema(): void
    {
        // BUG pinned: RetryFailedJobCommand's INSERT omits the required job_id
        // column, so on the migrated schema the insert fails silently (caught)
        // and the DELETE never runs — the job is never re-queued.
        $this->insertFailedJob('job-retry-1');
        $this->setAdapter('database');

        $this->runCommand(RetryFailedJobCommand::class);

        $this->assertSame(1, $this->countRows('jobs_failed'), 'still stuck in failed table');
        $this->assertSame(0, $this->countRows('jobs'), 'nothing was re-queued');
    }

    public function testRetryIsNoOpForSqliteAdapter(): void
    {
        $this->insertFailedJob('job-retry-2');
        $this->setAdapter('sqlite');

        $this->runCommand(RetryFailedJobCommand::class);

        $this->assertSame(1, $this->countRows('jobs_failed'));
        $this->assertSame(0, $this->countRows('jobs'));
    }
}
