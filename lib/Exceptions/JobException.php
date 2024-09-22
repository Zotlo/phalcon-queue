<?php

namespace Phalcon\Queue\Exceptions;

use Phalcon\Queue\Jobs\Job;

abstract class JobException extends RuntimeException
{
    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /** @var Job $job */
    private Job $job;

    /**
     * @param Job $job
     * @return void
     */
    public function setJob(Job $job): void
    {
        $this->job = $job;
    }

    /**
     * @return Job
     */
    public function getJob(): Job
    {
        return $this->job;
    }
}