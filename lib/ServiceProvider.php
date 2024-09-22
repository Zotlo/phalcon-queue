<?php declare(strict_types=1);

namespace Phalcon\Queue;

use Phalcon\Di\DiInterface as DependencyInjector;
use Phalcon\Di\ServiceProviderInterface;
use Phalcon\Queue\Tasks\{ExampleTask, QueueTask, WorkerTask};

/**
 * Phalcon 5 Queue Service Provider
 *
 * @version 1.0.0b
 * @author Abdulkadir Polat <abdulkadirpolat@teknasyon.com>
 */
final class ServiceProvider implements ServiceProviderInterface
{
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
        // Master
        $di->set('QueueTask', function () {
            return new QueueTask();
        });

        // Worker Task
        $di->set('QueueWorkerTask', function () {
            return new WorkerTask();
        });

        // Example Task
        $di->set('ExampleTask', function () {
            return new ExampleTask();
        });
    }
}