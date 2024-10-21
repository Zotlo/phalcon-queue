<?php

namespace Phalcon\Queue;

use Phalcon\Config\Config;
use Phalcon\Di\Di as DependencyInjector;
use Phalcon\Queue\Connectors\ConnectorInterface;
use Phalcon\Queue\Connectors\MySQL;
use Phalcon\Queue\Connectors\Redis;
use Phalcon\Queue\Connectors\SQLite;
use Phalcon\Queue\Exceptions\ConfigException;
use Phalcon\Queue\Exceptions\ConnectorException;

final class Connector
{
    /**
     * Connector Key
     *
     * @var string $connectorName
     */
    public string $connectorName;

    /**
     * Storage Connector
     *
     * @var ConnectorInterface $adapter
     */
    public ConnectorInterface $adapter;

    /**
     * Phalcon Application Config
     *
     * @var Config $config
     */
    private Config $config;

    /**
     * @throws ConnectorException
     * @throws ConfigException
     */
    public function __construct(DependencyInjector $di)
    {
        $this->config = Utils::discoveryApplicationConfig($di);
        $this->connectorName = $this->config->queues->adapter;

        $this->adapter = match ($this->connectorName) {
            'mysql' => new MySQL(),
            'sqlite' => new SQLite(),
            'redis' => (new Redis())->setDatabaseIndex($this->config->queues->dbIndex ?? 1),
            default => throw new ConnectorException("Connector '" . $this->connectorName . "' is not supported"),
        };
    }
}