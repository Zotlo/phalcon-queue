<?php declare(strict_types=1);

use Laravel\SerializableClosure\SerializableClosure as Closure;
use Phalcon\Di\Di as DependencyInjector;
use Phalcon\Queue\Connector;
use Phalcon\Queue\Dispatcher;
use Phalcon\Queue\Jobs\AsyncJob;
use Phalcon\Queue\Jobs\Job;
use Phalcon\Queue\Jobs\Status;

if (!function_exists('async')) {
    /**
     * @param callable $callable
     * @return Job
     */
    function async(callable $callable): Job
    {
        $job = new AsyncJob(serialize(new Closure($callable)));
        dispatch($job);
        return $job;
    }
}

if (!function_exists('await')) {
    /**
     * @param Job $job
     * @param bool $manageable
     * @return Status
     */
    function await(Job $job, bool $manageable = false): Status
    {
        try {
            /** @var DependencyInjector $di */
            $di = DependencyInjector::getDefault();
            $connector = new Connector($di);
        } catch (Throwable $throwable) {
            return Status::UNKNOWN;
        }

        do {
            switch ($connector->adapter->getJobStatus($job)) {
                case Status::COMPLETED:
                    return Status::COMPLETED;
                case Status::FAILED:
                    return Status::FAILED;
                case Status::PROCESSING:
                    if ($manageable)
                        return Status::PROCESSING;
                    break;
                case Status::PENDING:
                    if ($manageable)
                        return Status::PENDING;
                    break;
                default:
                    return Status::UNKNOWN;
            }

            usleep(rand(100000, 125000));
        } while (true);
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