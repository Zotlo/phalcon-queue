<?php declare(strict_types=1);

namespace Phalcon\Queue\Tests\Integration;

use Phalcon\Queue\Tests\Support\IntegrationTestCase;
use Phalcon\Queue\Tests\Support\Jobs\SentinelJob;

/**
 * Scenario #3: the master handles SIGTERM/SIGINT/SIGHUP by shutting its
 * workers down and exiting cleanly (exit code 0), rather than being killed.
 */
final class SignalTest extends IntegrationTestCase
{
    /**
     * @dataProvider gracefulSignals
     */
    public function testMasterExitsGracefullyOnSignal(int $signal): void
    {
        $marker = sys_get_temp_dir() . '/pq_sig_' . uniqid();
        @unlink($marker);

        // Enqueue a job so at least one worker gets spawned/registered.
        $dispatcher = dispatch(new SentinelJob($marker))->queue($this->queue);
        unset($dispatcher);

        $this->startMaster();

        // Wait until the worker has actually run (a worker is now registered
        // with the master, so we exercise the graceful drain path).
        $this->assertTrue(
            $this->waitFor(fn () => file_exists($marker), 30.0),
            'worker never started; cannot test graceful drain. stderr: ' . $this->master->getErrorOutput()
        );

        $this->assertTrue($this->master->isRunning(), 'master should still be running');

        $this->master->signal($signal);

        $stopped = $this->waitFor(fn () => !$this->master->isRunning(), 20.0);

        $this->assertTrue($stopped, 'master did not exit after signal ' . $signal);
        $this->assertSame(0, $this->master->getExitCode(), 'master should exit cleanly (0)');

        // No orphaned worker for this queue should survive the shutdown.
        $noOrphans = $this->waitFor(fn () => $this->workerCount() === 0, 5.0);
        $this->assertTrue($noOrphans, 'a worker process was left orphaned');

        @unlink($marker);
    }

    public static function gracefulSignals(): array
    {
        return [
            'SIGTERM' => [SIGTERM],
            'SIGINT'  => [SIGINT],
        ];
    }

    private function workerCount(): int
    {
        @\exec(
            'pgrep -f ' . \escapeshellarg('console.php QueueWorker run ' . $this->queue) . ' 2>/dev/null',
            $out
        );
        return \count(\array_filter($out));
    }
}
