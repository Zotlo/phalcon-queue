<?php

namespace Phalcon\Queue;

use Phalcon\Config\Config;
use Phalcon\Di\Di as DependencyInjector;
use Phalcon\Queue\Exceptions\RuntimeException;

class Utils
{
    /**
     * @param DependencyInjector $di
     * @return Config
     * @throws RuntimeException
     */
    public static function discoveryApplicationConfig(DependencyInjector $di): Config
    {
        $configKeys = [
            'conf', 'config', 'settings', 'setting'
        ];

        foreach ($configKeys as $configKey) {
            try {
                $config = $di->get($configKey);
            } catch (\Throwable $exception) {
                //
            }
        }

        if (!isset($config)) {
            throw new RuntimeException('Phalcon application configuration is missing');
        }

        if (!isset($config->queues)) {
            throw new RuntimeException('Phalcon queue configuration is missing');
        }

        if (!isset($config->queues->supervisors)) {
            throw new RuntimeException('Phalcon queue supervisor configuration is missing');
        }

        return $config;
    }
}