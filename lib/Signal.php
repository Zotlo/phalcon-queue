<?php declare(strict_types=1);

namespace Phalcon\Queue;

trait Signal
{
    /**
     * Configure POSIX Signal Handler
     *
     * @param string $methodName
     * @return void
     */
    private function configureSignal(string $methodName = 'handleSignal'): void
    {
        pcntl_signal(SIGTERM, [$this, $methodName]);
        pcntl_signal(SIGHUP, [$this, $methodName]);
        pcntl_signal(SIGINT, [$this, $methodName]);
        pcntl_async_signals(true);
    }

    /**
     * Handle POSIX Signal
     *
     * @param int $signal
     * @return void
     */
    public function handleSignal(int $signal)
    {
        //
    }
}