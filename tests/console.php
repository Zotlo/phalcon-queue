<?php declare(strict_types=1);

/*
 * Spawnable Phalcon CLI console for integration tests.
 *
 * Mirrors the project's root cli.php, but points the storage at an isolated
 * SQLite file supplied through the PHALCON_QUEUE_TEST_DB environment variable
 * (and the supervisor queue name through PHALCON_QUEUE_TEST_QUEUE).
 *
 * It is launched by the tests as the master process
 *   php tests/console.php Queue run <queue>
 * and re-launched internally by Phalcon\Queue\Process as each worker
 *   php tests/console.php QueueWorker run <queue>
 * so both master and workers see the same database and configuration.
 */

require __DIR__ . '/../vendor/autoload.php';

use Phalcon\Cli\Console as ConsoleApp;
use Phalcon\Queue\Tests\Support\TestDi;

$dbPath = getenv('PHALCON_QUEUE_TEST_DB') ?: (sys_get_temp_dir() . '/phalcon_queue_test.sqlite');
$queue  = getenv('PHALCON_QUEUE_TEST_QUEUE') ?: 'default';

$di = TestDi::create($dbPath, $queue);

$console = new ConsoleApp($di);

$arguments = ['task' => '', 'action' => '', 'params' => []];
foreach ($argv as $key => $value) {
    if ($key === 1) {
        $arguments['task'] = $value;
    } elseif ($key === 2) {
        $arguments['action'] = $value;
    } elseif ($key >= 3) {
        $arguments['params'][] = $value;
    }
}

try {
    $console->handle($arguments);
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
