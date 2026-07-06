<?php declare(strict_types=1);

namespace Phalcon\Queue\Tests\Unit;

use Phalcon\Queue\Jobs\Job;
use Phalcon\Queue\Tests\Support\Jobs\SentinelJob;
use PHPUnit\Framework\TestCase;

final class JobTest extends TestCase
{
    private function job(): Job
    {
        return new class extends Job {
            public function handle(): void {}
        };
    }

    public function testDefaults(): void
    {
        $job = $this->job();

        $this->assertSame('default', $job->getQueue());
        $this->assertSame(0, $job->getDelay());
        $this->assertSame(0, $job->getTimeout());
        $this->assertNull($job->id);
    }

    public function testSettersAndGetters(): void
    {
        $job = $this->job();
        $job->setQueue('emails');
        $job->setDelay(15);
        $job->setTimeout(60);

        $this->assertSame('emails', $job->getQueue());
        $this->assertSame(15, $job->getDelay());
        $this->assertSame(60, $job->getTimeout());
    }

    public function testSerializeAssignsIdAndCapturesProperties(): void
    {
        $job = $this->job();
        $job->setQueue('reports');

        $data = $job->__serialize();

        $this->assertNotNull($job->id, 'serialize should lazily assign an id');
        $this->assertStringStartsWith('job-', $data['id']);
        $this->assertSame('reports', $data['queue']);
        $this->assertSame(0, $data['delay']);
    }

    public function testSerializeRoundTripPreservesState(): void
    {
        $job = new SentinelJob('/tmp/whatever');
        $job->setQueue('files');
        $job->setDelay(5);

        /** @var SentinelJob $restored */
        $restored = unserialize(serialize($job));

        $this->assertInstanceOf(SentinelJob::class, $restored);
        $this->assertSame($job->id, $restored->id);
        $this->assertSame('files', $restored->getQueue());
        $this->assertSame(5, $restored->getDelay());
        $this->assertSame('/tmp/whatever', $restored->path);
    }

    public function testStartJobTimerIsNoOpWhenTimeoutZero(): void
    {
        $job = $this->job();

        $job->startJobTimer();

        // No alarm should be pending; pcntl_alarm(0) returns the remaining
        // seconds of any previously scheduled alarm (0 = none).
        $this->assertSame(0, pcntl_alarm(0));
    }

    public function testStartJobTimerSchedulesAlarm(): void
    {
        $job = $this->job();
        $job->setTimeout(120);

        $job->startJobTimer();

        // Cancelling returns the remaining seconds of the alarm we just armed.
        $remaining = pcntl_alarm(0);

        $this->assertGreaterThan(0, $remaining, 'an alarm should have been scheduled');
        $this->assertLessThanOrEqual(120, $remaining);
    }
}
