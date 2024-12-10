<?php declare(strict_types=1);

namespace Phalcon\Queue\Tasks;

use Phalcon\Config\Config;
use Phalcon\Queue\Connector;
use Phalcon\Queue\Exceptions\QueueException;
use Phalcon\Queue\Queue;
use Phalcon\Queue\Socket\Socket;
use Phalcon\Queue\Utils;

class QueueTask extends Task
{
    /**
     * Phalcon Application Config
     *
     * @var Config $config
     */
    protected Config $config;

    /**
     * Handle Queue Master Process
     *
     * @param string $queue
     * @return void
     * @throws QueueException
     */
    public function runAction(string $queue = 'default'): void
    {
        // Initialize Phalcon Config
        $this->config = Utils::discoveryApplicationConfig($this->di);

        // Start Master Process
        (new Queue())
            ->setQueue($queue)
            ->setWorkerTaskName($this->workerTaskName)
            ->setConfig($this->config)
            ->setConnector(new Connector($this->di))
            ->setSocket(new Socket(true, false, $queue))
            ->work();
    }

    /**
     * Set the name of the worker task
     *
     * @param string $taskName
     * @return self
     */
    public function setWorkerTaskName(string $taskName): self
    {
        $this->workerTaskName = $taskName;

        return $this;
    }
}