<?php

namespace Phalcon\Queue\Jobs;

use Laravel\SerializableClosure\SerializableClosure as Closure;

class AsyncJob extends Job
{
    /** @var string $closureString */
    private string $closureString;

    /**
     * @param string $closure
     */
    public function __construct(string $closure)
    {
        $this->closureString = $closure;
    }

    /**
     * @return void
     */
    public function handle(): void
    {
        /** @var Closure $closure */
        $closure = unserialize($this->closureString);
        $closure->getClosure()($this->di);
    }
}