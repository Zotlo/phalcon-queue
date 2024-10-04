<?php declare(strict_types=1);

namespace Phalcon\Queue\Jobs;

use Phalcon\Di\Injectable;
use ReflectionClass;

abstract class Job extends Injectable implements JobInterface
{
    /** @var string $id */
    public string $id;

    /** @var string $queue */
    protected string $queue = "default";

    /** @var int $delay */
    protected int $delay = 0;

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
     * @return array
     */
    public function __serialize(): array
    {
        $this->id = uniqid('job-', true);

        $reflection = new ReflectionClass($this);
        $properties = $reflection->getProperties();

        $data = [];
        foreach ($properties as $property) {
            $data[$property->getName()] = $property->getValue($this);
        }

        return $data;
    }
}