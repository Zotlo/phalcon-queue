<?php declare(strict_types=1);

use Opis\Closure\SerializableClosure as Closure;
use Phalcon\Queue\Dispatcher;
use Phalcon\Queue\Jobs\AsyncJob;
use Phalcon\Queue\Jobs\Job;

if (!function_exists('async')) {
    /**
     * @param callable $callable
     * @return void
     */
    function async(callable $callable): void
    {
        dispatch(new AsyncJob((new Closure($callable))->serialize()));
    }
}

if (!function_exists('dispatch')) {
    /**
     * @param Job $job
     * @return Dispatcher
     */
    function dispatch(Job $job): Dispatcher
    {
        return Dispatcher::dispatch($job);
    }
}

if (!function_exists('dispatchBatch')) {
    /**
     * @param array $jobs
     * @return Dispatcher
     */
    function dispatchBatch(array $jobs): Dispatcher
    {
        return Dispatcher::batch($jobs);
    }
}