<?php declare(strict_types=1);

namespace Phalcon\Queue;

use Phalcon\Di\DiInterface as DependencyInjector;
use Phalcon\Di\ServiceProviderInterface;
use Phalcon\Queue\Tasks\{QueueTask, WorkerTask};

/**
 * Phalcon 5 Queue Service Provider
 *
 * @version 1.1.3
 * @author Abdulkadir Polat <abdulkadirpolat@teknasyon.com>
 */
final class ServiceProvider implements ServiceProviderInterface
{
    public function __construct(
        protected string $masterTaskName = 'QueueTask',
        protected string $workerTaskName = 'QueueWorkerTask'
    )
    {
        //
    }

    /**
     * Register Phalcon Queue
     *
     * @param DependencyInjector $di
     * @return void
     */
    public function register(DependencyInjector $di): void
    {
        $this->share($di); // Share Tasks
    }

    /**
     * Share Phalcon Queue Tasks
     *
     * @param DependencyInjector $di
     * @return void
     */
    private function share(DependencyInjector $di): void
    {
        $workerTaskName = $this->workerTaskName;

        // Master
        $di->set($this->masterTaskName, function () use ($workerTaskName) {
            return (new QueueTask())->setWorkerTaskName($workerTaskName);
        });

        // Worker Task
        $di->set($this->workerTaskName, function () {
            return new WorkerTask();
        });
    }
}