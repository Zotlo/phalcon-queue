<?php

namespace Phalcon\Queue\Connectors;

use Phalcon\Di\Di;
use Phalcon\Queue\Exceptions\RedisSelectDatabaseException;
use Phalcon\Queue\Jobs\Job;
use Redis as RedisClient;
use Throwable;

class Redis implements ConnectorInterface
{
    /**
     * Redis Cache Prefix
     *
     * @var string $prefix
     */
    private string $prefix = "PHALCON_QUEUE_";

    /**
     * Redis Database
     *
     * @var int $dbIndex
     */
    private int $dbIndex = 1;

    /**
     * @var RedisClient $redis
     */
    private RedisClient $redis;

    /**
     * @throws RedisSelectDatabaseException
     */
    public function __construct()
    {
        $this->redis = Di::getDefault()->get('redis');
        $config = Di::getDefault()->get('config');

        try {
            if (!empty($config->queues->dbIndex)) {
                $this->dbIndex = (int)$config->queues->dbIndex;
            }

            $this->redis->select($this->dbIndex);
        } catch (Throwable $exception) {
            throw new RedisSelectDatabaseException($exception->getMessage());
        }
    }

    /**
     * @param int $dbIndex
     * @return $this
     */
    public function setDatabaseIndex(int $dbIndex): self
    {
        $this->dbIndex = $dbIndex;

        return $this;
    }

    public function checkTableAndMigrateIfNecessary(): void
    {
        //
    }

    /**
     * @param object $job
     * @return bool
     */
    public function markAsProcessing(object $job): bool
    {
        return true;
    }

    /**
     * @param object $job
     * @param object $exception
     * @return bool
     */
    public function markAsFailed(object $job, object $exception): bool
    {
        try {
            $this->redis->lPush($this->prefix . 'FAILED_JOBS', json_encode([
                // Fail Information
                'job_id'    => $job->id,
                'failed_at' => gmdate('Y-m-d H:i:s'),
                'exception' => json_encode([
                        'class'   => get_class($exception),
                        'message' => $exception->getMessage(),
                        'code'    => $exception->getCode(),
                        'file'    => $exception->getFile(),
                        'line'    => $exception->getLine(),
                        'trace'   => $exception->getTrace(),
                    ]
                ),

                // Job Details
                ...(array)$job,
            ]));
        } catch (Throwable $exception) {
            return false;
        }

        return true;
    }

    /**
     * @param object $job
     * @return bool
     */
    public function markAsCompleted(object $job): bool
    {
        return true;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function lock(string $key): bool
    {
        try {
            return $this->redis->setnx($this->prefix . $key, time());
        } catch (Throwable $exception) {
            return false;
        }
    }

    /**
     * @param string $key
     * @return bool
     */
    public function unlock(string $key): bool
    {
        try {
            return $this->redis->del($this->prefix . $key) !== false;
        } catch (Throwable $exception) {
            return false;
        }
    }

    /**
     * @param Job $job
     * @return object|false
     */
    public function insertJob(Job $job): object|false
    {
        try {
            $jobId = uniqid();

            $values = [
                'id'           => $jobId,
                'queue'        => $job->getQueue(),
                'payload'      => serialize($job),
                'attempts'     => 0,
                'reserved_at'  => null,
                'available_at' => gmdate('Y-m-d H:i:s', strtotime('+' . $job->getDelay() . ' seconds')),
                'created_at'   => gmdate('Y-m-d H:i:s'),
            ];

            return $this->redis->lPush($this->prefix . $job->getQueue() . '_PENDING_JOBS', json_encode($values));
        } catch (Throwable $exception) {
            return false;
        }
    }

    /**
     * @param string $queue
     * @return object|false
     */
    public function getPendingJob(string $queue = 'default'): object|false
    {
        try {
            $job = $this->redis->lPop($this->prefix . $queue . '_PENDING_JOBS');

            if (empty($job)) {
                return false;
            }

            $jobObject = json_decode($job);
            $jobObject->attempts += 1;
            $jobObject->reserved_at = gmdate('Y-m-d H:i:s');

            return $jobObject;
        } catch (Throwable $exception) {
            return false;
        }
    }

    /**
     * @param string $queue
     * @return array
     */
    public function getPendingJobs(string $queue = 'default'): array
    {
        $pendingJobs = [];

        try {
            $length = $this->redis->lLen($this->prefix . $queue . '_PENDING_JOBS');

            for ($i = 0; $i < $length; $i++) {
                $pendingJobs[] = $i;
            }

            return $pendingJobs;
        } catch (Throwable $exception) {
            //
        }

        return $pendingJobs;
    }
}