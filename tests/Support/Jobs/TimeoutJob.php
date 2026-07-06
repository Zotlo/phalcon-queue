<?php declare(strict_types=1);

namespace Phalcon\Queue\Tests\Support\Jobs;

use Phalcon\Queue\Jobs\Job;

/**
 * Sleeps well past its own (short) timeout so the SIGALRM watchdog
 * installed by Job::startJobTimer() fires and aborts the job.
 */
class TimeoutJob extends Job
{
    public function __construct(int $timeout = 1)
    {
        $this->timeout = $timeout;
    }

    public function handle(): void
    {
        sleep(30);
    }
}
