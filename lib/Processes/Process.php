<?php

namespace Phalcon\Queue\Processes;

use Symfony\Component\Process\Process as CLIProcess;

/**
 * @mixin CLIProcess
 */
class Process
{
    public const STATUS_WORKING = "WORKING";
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
        $command = PHP_BINARY . ' ' . getcwd() . '/' . $_SERVER['SCRIPT_NAME'] . ' ' . str_replace('Task', '', $workerTaskName) . ' run ' . $queue;
        $this->process = CLIProcess::fromShellCommandline($command);
    }

    /**
     * @return void
     */
    public function start(): void
    {
        $this->process->start(function ($type, $buffer) {
            switch (trim($buffer)) {
                case self::STATUS_WORKING:
                    $this->isIdle = false;
                    break;
                case self::STATUS_IDLE:
                    $this->isIdle = true;
                    break;
                default:
            }
        });
    }

    /**
     * @return bool
     */
    public function isIdle(): bool
    {
        return $this->isIdle;
    }
}