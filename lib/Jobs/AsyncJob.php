<?php

namespace Phalcon\Queue\Jobs;

use Opis\Closure\SecurityException;
use Opis\Closure\SerializableClosure as Closure;

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
     * @throws SecurityException
     */
    public function handle(): void
    {
        $closureClass = new Closure(fn() => null);
        $closureClass->unserialize($this->closureString);
        $closureClass->getClosure()();
    }
}