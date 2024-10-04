<?php

namespace Phalcon\Queue\Connectors;

use Phalcon\Queue\Jobs\Job;
use Phalcon\Queue\Jobs\Status;

interface ConnectorInterface
{
    public function checkTableAndMigrateIfNecessary(): void;

    public function markAsProcessing(object $job): bool;

    public function markAsFailed(object $job, object $exception): bool;

    public function markAsCompleted(object $job): bool;

    public function lock(string $key): bool;

    public function unlock(string $key): bool;

    public function insertJob(Job $job): string;

    public function getPendingJob(): object|false;

    public function getPendingJobs(): array;

    public function getJobStatus(string $jobId): Status;
}