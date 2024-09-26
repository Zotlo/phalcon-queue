<?php declare(strict_types=1);

namespace Phalcon\Queue\Tasks;

use Phalcon\Config\Config;
use Phalcon\Queue\Utils;
use Phalcon\Queue\Exceptions\QueueException;
use Phalcon\Queue\Connector;
use Phalcon\Queue\Queue;

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
            ->setConfig($this->config)
            ->setConnector(new Connector($this->di))
            ->work();
    }
}