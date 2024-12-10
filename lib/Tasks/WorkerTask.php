<?php

namespace Phalcon\Queue\Tasks;

use Phalcon\Queue\Connector;
use Phalcon\Queue\Exceptions\ConfigException;
use Phalcon\Queue\Exceptions\ConnectorException;
use Phalcon\Queue\Exceptions\DatabaseException;
use Phalcon\Queue\Exceptions\QueueException;
use Phalcon\Queue\Exceptions\RuntimeException;
use Phalcon\Queue\Jobs\Job;
use Phalcon\Queue\Process;
use Phalcon\Queue\Signal;
use Phalcon\Queue\Socket\Message;
use Phalcon\Queue\Socket\Socket;

class WorkerTask extends Task
{
    use Signal;

    // Worker Settings
    private false|object $job;
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
     * @return void
     * @throws ConnectorException
     * @throws ConfigException
     * @throws RuntimeException
     */
    public function runAction(string $queue): void
    {
        $this->queue = $queue;
        $this->configureSignal();
        $this->connector = new Connector($this->di);
        $this->socket = new Socket(false, false, $queue);

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
        return getmypid();
    }

    /**
     * Execute Job
     *
     * @return void
     * @throws QueueException
     */
    private function process(): void
    {
        $this->job = $this->connector->adapter->getPendingJob($this->queue);
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

        if (empty($this->job)) {
            $this->sleep();

            return;
        }

        $this->running();

        $lockKey = 'JOB_LOCK_' . $this->job->id;
        if ($this->connector->adapter->lock($lockKey)) {
            if ($this->connector->adapter->markAsProcessing($this->job)) {
                /** @var Job $jobClass */
                $jobClass = unserialize($this->job->payload);

                if ($jobClass->getTimeout() === 0) {
                    if (!empty($supervisor->timeout)) {
                        $jobClass->setTimeout($supervisor->timeout);
                    }
                }

                try {
                    $jobClass->startJobTimer();
                    $jobClass->handle();

                    $this->connector->adapter->markAsCompleted($this->job);
                } catch (\Throwable $exception) {
                    $this->connector->adapter->markAsFailed($this->job, $exception);
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
        $this->isIdle = false;

        try {
            $this->socket->send(new Message(Message::WORKER, Message::SERVER, Process::STATUS_RUNNING));
        } catch (\Throwable $exception) {
            try {
                $this->connector->adapter->markAsFailed($this->job, $exception);
            } catch (DatabaseException $e) {
                //
            }
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

            try {
                $this->socket->send(new Message(Message::WORKER, Message::SERVER, Process::STATUS_IDLE));
            } catch (\Throwable $exception) {
                //
            }
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