<?php

namespace Phalcon\Queue;

use Symfony\Component\Process\Process as CLIProcess;

/**
 * @mixin CLIProcess
 */
class Process
{
    // Process statuses.
    public const STATUS_RUNNING = "RUNNING";
    public const STATUS_IDLE = "IDLE";

    /** @var CLIProcess $process */
    private CLIProcess $process;

    /** @var bool $isIdle */
    private bool $isIdle = false;

    public function __construct(string $queue, string $workerTaskName)
    {
        $this->initializeShellCommand($queue, $workerTaskName);
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function __get(string $name)
    {
        return $this->process->{$name};
    }

    /**
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments)
    {
        return $this->process->{$name}(...$arguments);
    }

    /**
     * @param string $queue
     * @param string $workerTaskName
     * @return void
     */
    private function initializeShellCommand(string $queue, string $workerTaskName): void
    {
        $scriptPath = realpath($_SERVER['SCRIPT_FILENAME']);
        $command = PHP_BINARY . ' ' . $scriptPath . ' ' . str_replace('Task', '', $workerTaskName) . ' run ' . $queue . ' ' . getmypid();
        $this->process = CLIProcess::fromShellCommandline($command);
    }

    /**
     * @return void
     */
    public function start(): void
    {
        $this->process->disableOutput();
        $this->process->start();
    }

    /**
     * @return bool
     */
    public function isIdle(): bool
    {
        return $this->isIdle;
    }

    /**
     * @param bool $idle
     * @return void
     */
    public function setIdle(bool $idle): void
    {
        $this->isIdle = $idle;
    }
}