<?php

namespace Phalcon\Queue\Tasks;

use Phalcon\Queue\Connector;
use Phalcon\Queue\Exceptions\ConfigException;
use Phalcon\Queue\Exceptions\ConnectorException;
use Phalcon\Queue\Exceptions\QueueException;
use Phalcon\Queue\Exceptions\RuntimeException;
use Phalcon\Queue\Jobs\Job;
use Phalcon\Queue\Process;
use Phalcon\Queue\Signal;
use Phalcon\Queue\Socket;

class WorkerTask extends Task
{
    use Signal;

    // Master Process Pid
    private int $masterProcessPid;

    // Worker Settings
    private string $queue;
    private int $each = 0;

    // Worker Status
    private bool $isIdle = false;
    private bool $shouldStop = false;

    /**
     * Queue Connector
     *
     * @var Connector $connector
     */
    private Connector $connector;

    /**
     * Socket Client
     *
     * @var Socket $socket
     */
    private Socket $socket;

    /**
     * Handle Queue Worker Process
     *
     * @param string $queue
     * @param int $masterProcessPid
     * @return void
     * @throws ConnectorException
     * @throws ConfigException
     * @throws RuntimeException
     */
    public function runAction(string $queue, int $masterProcessPid): void
    {
        $this->masterProcessPid = $masterProcessPid;
        $this->queue = $queue;
        $this->configureSignal();
        $this->connector = new Connector($this->di);
        $this->socket = new Socket(false, $queue);

        do {
            if ($this->shouldStop) {
                exit(0);
            }

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
                $this->shouldStop = true;
                break;
            case SIGKILL:
                exit(0);
            default:
                //
        }
    }

    /**
     * @return int
     */
    private function pid(): int
    {
        $pid = posix_getppid();
        if ($pid === 1 || $pid === $this->masterProcessPid) {
            $pid = getmypid();
        }
        return $pid;
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
        $config = $this->di->getShared('config');

        foreach ($config->queues->supervisors as $supervisor) {
            if ($supervisor->queue === $this->queue) {
                break;
            }
        }

        if (empty($supervisor)) {
            $this->sleep();

            return;
        }

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

                if ($jobClass->getTimeout() === 0) {
                    if (!empty($supervisor->timeout)) {
                        $jobClass->setTimeout($supervisor->timeout);
                    }
                }

                try {
                    $jobClass->startJobTimer();
                    $jobClass->handle();

                    $this->connector->adapter->markAsCompleted($job);
                } catch (\Throwable $exception) {
                    $this->connector->adapter->markAsFailed($job, $exception);
                }
            }

            $this->connector->adapter->unlock($lockKey);
        }
    }

    /**
     * Send RUNNING Signal Master Process
     *
     * @return void
     */
    private function running(): void
    {
        if ($this->isIdle) {
            $this->isIdle = false;

            $this->socket->send($this->pid() . '@' . Process::STATUS_RUNNING);
        }
    }

    /**
     * Send IDLE Signal Master Process
     *
     * @return void
     */
    private function idle(): void
    {
        if (!$this->isIdle) {
            $this->isIdle = true;

            $this->socket->send($this->pid() . '@' . Process::STATUS_IDLE);
        }
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