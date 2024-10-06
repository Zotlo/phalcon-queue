<?php declare(strict_types=1, ticks=1);

namespace Phalcon\Queue\Jobs;

use Phalcon\Di\Injectable;
use Phalcon\Queue\Exceptions\TimeoutException;
use ReflectionClass;

abstract class Job extends Injectable implements JobInterface
{
    /** @var null|string $id */
    public ?string $id = null;

    /** @var string $queue */
    protected string $queue = "default";

    /** @var int $delay */
    protected int $delay = 0;

    /** @var int $timeout */
    protected int $timeout = 0;

    /**
     * Execute Job Function
     *
     * @return void
     */
    public function handle(): void
    {
        //
    }

    /**
     * @return void
     * @throws TimeoutException
     */
    public function startJobTimer(): void
    {
        if ($this->timeout > 0) {
            pcntl_signal(SIGALRM, function () {
                throw new TimeoutException('Job Timeout: ' . $this->timeout);
            });

            pcntl_async_signals(true);

            pcntl_alarm($this->timeout);
        }
    }

    /**
     * @return string
     */
    public function getQueue(): string
    {
        return $this->queue;
    }

    /**
     * @return int
     */
    public function getDelay(): int
    {
        return $this->delay;
    }

    /**
     * @return int
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * @param string $queue
     * @return void
     */
    public function setQueue(string $queue): void
    {
        $this->queue = $queue;
    }

    /**
     * @param int $delay
     * @return void
     */
    public function setDelay(int $delay): void
    {
        $this->delay = $delay;
    }

    /**
     * @param int $timeout
     * @return void
     */
    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
    }

    /**
     * @return array
     */
    public function __serialize(): array
    {
        if (is_null($this->id)) {
            $this->id = uniqid('job-', true);
        }

        $reflection = new ReflectionClass($this);
        $properties = $reflection->getProperties();

        $data = [];
        foreach ($properties as $property) {
            $data[$property->getName()] = $property->getValue($this);
        }

        return $data;
    }
}