<?php declare(strict_types=1);

use Phalcon\Di\FactoryDefault\Cli as CliDI;
use Symfony\Component\Console\Application as Console;

require_once __DIR__ . '/../vendor/autoload.php';

// Dependency Injector
$di = new CliDI();

// Set up the phalcon config
$di->setShared('config', function () {
    return new \Phalcon\Config\Config([
        'database' => [
            'adapter'  => 'Mysql',
            'host'     => '127.0.0.1',
            'username' => 'root',
            'password' => 'polat',
            'dbname'   => 'phalcon',
            'charset'  => 'utf8',
        ],
        'queues'   => [
            'adapter'     => 'redis', // database, redis
            'dbIndex'     => 1,       // only redis
            'supervisors' => [
                [
                    'queue'           => 'default',
                    'processes'       => 10,
                    'tries'           => 0,
                    'timeout'         => 180,
                    'balanceMaxShift' => 2,
                    'balanceCooldown' => 3,
                    'debug'           => true
                ]
            ]
        ]
    ]);
});

// Set up the database service
$di->setShared('db', function () use ($di) {
    $config = $di->get('config');

    return new \Phalcon\Db\Adapter\Pdo\Mysql([
        'adapter'  => $config->database->adapter,
        'host'     => $config->database->host,
        'username' => $config->database->username,
        'password' => $config->database->password,
        'dbname'   => $config->database->dbname,
        'charset'  => $config->database->charset,
    ]);
});

$console = new Console('Phalcon Queue Management', '1.0.0');

$console->addCommands(
    [
        (new \Phalcon\Queue\Commands\ExampleCommand())->setDi($di),
        (new \Phalcon\Queue\Commands\ListFailedJobCommand())->setDi($di),
        (new \Phalcon\Queue\Commands\RetryFailedJobCommand())->setDi($di),
        (new \Phalcon\Queue\Commands\RestartQueueCommand())->setDi($di),
    ]
);

$console->run();