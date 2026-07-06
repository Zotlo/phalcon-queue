<?php declare(strict_types=1);

namespace Phalcon\Queue\Tests\Unit;

use Phalcon\Queue\Dispatcher;
use Phalcon\Queue\Jobs\AsyncJob;
use Phalcon\Queue\Tests\Support\DatabaseTestCase;
use Phalcon\Queue\Tests\Support\Jobs\SentinelJob;

final class DispatcherTest extends DatabaseTestCase
{
    public function testDispatchInsertsJobOnDestruct(): void
    {
        $this->assertSame(0, $this->countRows('jobs'));

        $dispatcher = Dispatcher::dispatch(new SentinelJob('/tmp/x'));
        unset($dispatcher); // __destruct performs the insert

        $this->assertSame(1, $this->countRows('jobs'));
    }

    public function testQueueAndDelayAreApplied(): void
    {
        $dispatcher = Dispatcher::dispatch(new SentinelJob('/tmp/x'))
            ->queue('reports')
            ->delay(3600);
        unset($dispatcher);

        $row = $this->db()->fetchOne('SELECT * FROM jobs LIMIT 1');

        $this->assertSame('reports', $row['queue']);
        // A one-hour delay must push available_at into the future.
        $this->assertGreaterThan(gmdate('Y-m-d H:i:s'), $row['available_at']);

        // A delayed job is not yet returned as pending.
        $this->assertFalse($this->connector->adapter->getPendingJob('reports'));
    }

    public function testBatchInsertsEveryJob(): void
    {
        $dispatcher = Dispatcher::batch([
            new SentinelJob('/tmp/a'),
            new SentinelJob('/tmp/b'),
            new SentinelJob('/tmp/c'),
        ]);
        unset($dispatcher);

        $this->assertSame(3, $this->countRows('jobs'));
    }

    public function testDispatchBatchGlobalHelperInsertsEveryJob(): void
    {
        $dispatcher = dispatchBatch([
            new SentinelJob('/tmp/a'),
            new SentinelJob('/tmp/b'),
        ]);
        unset($dispatcher);

        $this->assertSame(2, $this->countRows('jobs'));
    }

    public function testAsyncHelperEnqueuesAnAsyncJob(): void
    {
        $ran = async(function () {
            return 'noop';
        });

        $this->assertSame(1, $this->countRows('jobs'));

        $row     = $this->db()->fetchOne('SELECT * FROM jobs LIMIT 1');
        $payload = unserialize($row['payload']);

        $this->assertInstanceOf(AsyncJob::class, $payload);
        $this->assertInstanceOf(AsyncJob::class, $ran);
    }
}
