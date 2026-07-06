<?php declare(strict_types=1);

namespace Phalcon\Queue\Tests\Integration;

use Phalcon\Queue\Commands\ForceStopQueueCommand;
use Phalcon\Queue\Commands\RestartQueueCommand;
use Phalcon\Queue\Tests\Support\IntegrationTestCase;
use Phalcon\Queue\Tests\Support\Jobs\SentinelJob;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * queue:restart and queue:stop connect to the running master over its Unix
 * socket and send M_RESTART_QUEUE / M_FORCE_STOP_QUEUE. The master then tears
 * its workers down. This exercises the full CLI -> socket -> master control path.
 */
final class ControlCommandIntegrationTest extends IntegrationTestCase
{
    /**
     * Bring up a master with one busy worker, returning once the worker exists.
     */
    private function bootWithBusyWorker(): void
    {
        $marker = sys_get_temp_dir() . '/pq_ctl_' . uniqid();
        // Long-running job so the worker is alive when the command arrives.
        $dispatcher = dispatch(new SentinelJob($marker, 8))->queue($this->queue);
        unset($dispatcher);

        $this->startMaster();

        $this->assertTrue(
            $this->waitFor(fn () => $this->workerCount() >= 1, 30.0),
            'worker never started. Master stderr: ' . $this->master->getErrorOutput()
        );
    }

    private function runControlCommand(string $commandClass): CommandTester
    {
        $tester = new CommandTester((new $commandClass())->setDi($this->di));
        $tester->execute([]);
        return $tester;
    }

    public function testForceStopKillsWorkers(): void
    {
        $this->bootWithBusyWorker();

        $tester = $this->runControlCommand(ForceStopQueueCommand::class);

        $this->assertStringContainsString('Stopped queue "' . $this->queue . '"', $tester->getDisplay());
        $this->assertTrue(
            $this->waitFor(fn () => $this->workerCount() === 0, 10.0),
            'workers were not force-stopped'
        );
        $this->assertTrue($this->master->isRunning(), 'master itself should keep running');
    }

    public function testRestartCyclesWorkers(): void
    {
        $this->bootWithBusyWorker();

        $tester = $this->runControlCommand(RestartQueueCommand::class);

        $this->assertStringContainsString('Restart queue "' . $this->queue . '"', $tester->getDisplay());
        $this->assertTrue(
            $this->waitFor(fn () => $this->workerCount() === 0, 10.0),
            'workers were not stopped on restart'
        );
        $this->assertTrue($this->master->isRunning(), 'master itself should keep running');
    }
}
