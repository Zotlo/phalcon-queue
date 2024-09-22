<?php declare(strict_types=1);

namespace Phalcon\Queue\Tasks;

use Phalcon\Config\Config;
use Phalcon\Queue\Exceptions\{QueueException, RuntimeException};
use Phalcon\Queue\Connector;
use Phalcon\Queue\Queue;

class QueueTask extends Task
{
    /**
     * Queue Adapter
     *
     * @var string $adapter
     */
    private string $adapter = 'database';

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
        $this->discoveryApplicationConfig();

        // Start Master Process
        (new Queue())
            ->setQueue($queue)
            ->setConfig($this->config)
            ->setConnector(new Connector($this->adapter))
            ->work();
    }

    /**
     * Discovery Phalcon Application Config
     *
     * @return void
     * @throws RuntimeException
     */
    private function discoveryApplicationConfig(): void
    {
        $configKeys = [
            'conf', 'config', 'settings', 'setting'
        ];

        foreach ($configKeys as $configKey) {
            try {
                $this->config = $this->di->get($configKey);
            } catch (\Throwable $exception) {
                //
            }
        }

        if (!isset($this->config)) {
            throw new RuntimeException('Phalcon application configuration is missing');
        }

        if (!isset($this->config->queues)) {
            throw new RuntimeException('Phalcon queue configuration is missing');
        }

        if (isset($this->config->queues->adapter)) {
            $this->adapter = $this->config->queues->adapter;
        }

        if (!isset($this->config->queues->supervisors)) {
            throw new RuntimeException('Phalcon queue supervisor configuration is missing');
        }
    }
}