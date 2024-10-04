<?php

namespace Phalcon\Queue;

use Phalcon\Di\Di as DependencyInjector;
use Phalcon\Queue\Exceptions\ConnectorException;
use Phalcon\Queue\Exceptions\DatabaseException;
use Phalcon\Queue\Exceptions\JobDispatchException;
use Phalcon\Queue\Exceptions\RuntimeException;
use Phalcon\Queue\Jobs\Job;
use Phalcon\Queue\Jobs\Status;

final class Dispatcher
{
    /**
     * Dispatching jobs
     *
     * @var array<Job> $jobs
     */
    private array $jobs;

    /**
     * Dispatching queue
     *
     * @var string $queue
     */
    private string $queue = 'default';

    /**
     * Queue Connector Adapter
     *
     * @var Connector $connector
     */
    private Connector $connector;

    /**
     * Processing delay
     *
     * @var int $delay
     */
    private int $delay = 0;

    /**
     * Last Inserted Job ID
     *
     * @var string $lastInsertedJobId
     */
    private string $lastInsertedJobId;

    /**
     * @throws ConnectorException
     * @throws RuntimeException
     */
    public function __construct()
    {
        /** @var DependencyInjector $di */
        $di = DependencyInjector::getDefault();
        $this->connector = new Connector($di);
    }

    /**
     * Dispatch queue job
     *
     * @param Job $job
     * @return self
     */
    public static function dispatch(Job $job): self
    {
        $dispatch = new self();
        $dispatch->jobs[] = $job;

        return $dispatch;
    }

    /**
     * @param array<Job> $jobs
     * @return self
     */
    public static function batch(array $jobs): self
    {
        $dispatch = new self();
        $dispatch->jobs = $jobs;

        return $dispatch;
    }

    /**
     * @return Status
     * @throws JobDispatchException
     */
    public function await(): Status
    {
        do {
            if (property_exists($this, 'lastInsertedJobId') && !empty($this->lastInsertedJobId)) {
                throw new JobDispatchException();
            }

            // TODO :: CHECK JOB STATUS

            usleep(rand(100, 800));
        } while (true);
    }

    /**
     * Add delay dispatching job
     *
     * @param int $delay
     * @return $this
     */
    public function delay(int $delay): self
    {
        $this->delay = $delay;

        return $this;
    }

    /**
     * Set queue dispatching job
     *
     * @param string $queue
     * @return $this
     */
    public function queue(string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }

    /**
     * @throws DatabaseException
     */
    public function __destruct()
    {
        if (!empty($this->jobs)) {
            foreach ($this->jobs as $job) {
                $job->setQueue($this->queue);
                $job->setDelay($this->delay);

                $this->connector->adapter->insertJob($job);
            }
        }
    }
}