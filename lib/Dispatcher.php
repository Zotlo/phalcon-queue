<?php

namespace Phalcon\Queue;

use Phalcon\Di\Di;
use Phalcon\Queue\Exceptions\ConnectorException;
use Phalcon\Queue\Exceptions\DatabaseException;
use Phalcon\Queue\Jobs\Job;

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
     * @throws ConnectorException
     */
    public function __construct()
    {
        $config = Di::getDefault()->get('config');
        $this->connector = new Connector($config->queues->adapter);
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