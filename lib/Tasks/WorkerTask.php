<?php

namespace Phalcon\Queue\Tasks;

use Phalcon\Queue\Connector;
use Phalcon\Queue\Exceptions\ConnectorException;
use Phalcon\Queue\Exceptions\QueueException;
use Phalcon\Queue\Exceptions\RuntimeException;
use Phalcon\Queue\Jobs\Job;
use Phalcon\Queue\Signal;

class WorkerTask extends Task
{
    use Signal;

    // Worker Settings
    private string $queue;
    private int $each = 0;

    // Worker Status
    private bool $isIdle = false;

    // Signal Status
    private const STATUS_RUNNING = "RUNNING";
    private const STATUS_IDLE = "IDLE";

    /**
     * Queue Connector
     *
     * @var Connector $connector
     */
    private Connector $connector;

    /**
     * Handle Queue Worker Process
     *
     * @param string $queue
     * @return void
     * @throws ConnectorException
     * @throws RuntimeException
     */
    public function runAction(string $queue): void
    {
        $this->queue = $queue;
        $this->configureSignal();
        $this->connector = new Connector($this->di);

        do {
            try {
                $this->process();
            } catch (\Throwable $exception) {
                //
            }

            $this->each += 1;
            $this->sleep(false);
        } while (true);
    }

    /**
     * Handle POSIX Signal
     * @param int $signal
     * @return void
     */
    public function handleSignal(int $signal): void
    {
        switch ($signal) {
            case SIGTERM:
            case SIGHUP:
            case SIGINT:
                do {
                    $this->sleep();
                } while (true);
            case SIGKILL:
                exit(0);
            default:
                //
        }
    }

    /**
     * Execute Job
     *
     * @return void
     * @throws QueueException
     */
    private function process(): void
    {
        $job = $this->connector->adapter->getPendingJob($this->queue);

        if (empty($job)) {
            $this->sleep();

            return;
        }

        $this->running();

        $lockKey = 'JOB_LOCK_' . $job->id;
        if ($this->connector->adapter->lock($lockKey)) {
            if ($this->connector->adapter->markAsProcessing($job)) {
                /** @var Job $jobClass */
                $jobClass = unserialize($job->payload);

                try {
                    $jobClass->handle();
                } catch (\Throwable $exception) {
                    try {
                        $this->connector->adapter->markAsFailed($job, $exception);
                    } catch (\Throwable $exception) {
                        //
                    }

                    $this->idle();
                    return;
                }

                $this->connector->adapter->markAsCompleted($job);
            }

            $this->connector->adapter->unlock($lockKey);
        }

        $this->idle();
    }

    /**
     * Send RUNNING Signal Master Process
     *
     * @return void
     */
    private function running(): void
    {
        $this->isIdle = false;

        echo self::STATUS_RUNNING . PHP_EOL;
    }

    /**
     * Send IDLE Signal Master Process
     *
     * @return void
     */
    private function idle(): void
    {
        $this->isIdle = true;

        echo self::STATUS_IDLE . PHP_EOL;
    }

    /**
     * Sleep Process
     *
     * @param bool $sendSignal
     * @return void
     */
    private function sleep(bool $sendSignal = true): void
    {
        if ($sendSignal) {
            $this->idle();
        }

        sleep(rand(3, 8));
    }
}