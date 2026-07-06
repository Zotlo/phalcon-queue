<?php declare(strict_types=1);

namespace Phalcon\Queue\Tests\Support\Jobs;

use Phalcon\Queue\Jobs\Job;

/**
 * Always throws, to exercise the failure path (jobs -> jobs_failed).
 */
class FailingJob extends Job
{
    public function handle(): void
    {
        throw new \RuntimeException('FailingJob always fails');
    }
}
