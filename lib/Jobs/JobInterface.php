<?php declare(strict_types=1);

namespace Phalcon\Queue\Jobs;

interface JobInterface
{
    public function handle(): void;
}