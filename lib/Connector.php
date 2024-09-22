<?php

namespace Phalcon\Queue;

use Phalcon\Queue\Connectors\ConnectorInterface;
use Phalcon\Queue\Connectors\Database;
use Phalcon\Queue\Connectors\Redis;
use Phalcon\Queue\Exceptions\ConnectorException;

final class Connector
{
    /**
     * Storage Connector
     *
     * @var ConnectorInterface $adapter
     */
    public ConnectorInterface $adapter;

    /**
     * @throws ConnectorException
     */
    public function __construct(string $connector = 'database')
    {
        switch ($connector) {
            case 'database':
                $this->adapter = new Database();
                break;
            case 'redis':
                $this->adapter = new Redis();
                break;
            default:
                throw new ConnectorException("Connector '$connector' is not supported");
        }
    }
}