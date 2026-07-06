<?php declare(strict_types=1);

namespace Phalcon\Queue\Tests\Support\Jobs;

use Phalcon\Queue\Jobs\Job;

/**
 * A trivial job that proves it ran by writing a marker file.
 * The path is a plain string property, so it survives serialization
 * into the worker process untouched.
 */
class SentinelJob extends Job
{
    public string $path = '';

    public function __construct(string $path = '')
    {
        $this->path = $path;
    }

    public function handle(): void
    {
        file_put_contents($this->path, 'done@' . time(), LOCK_EX);
    }
}
