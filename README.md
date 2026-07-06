Phalcon Queue
======================================

`zotlo/phalcon-queue` is a queue worker library for Phalcon 5. It lets you dispatch
jobs (classes or plain closures) from your application and processes them in the
background with a supervisor that automatically scales worker processes up and down.

Supported storage backends:

- **MySQL**
- **Redis**
- **SQLite**

Requirements
------------

- PHP 8.1+
- Phalcon 5.x
- PHP extensions: `pdo`, `redis`, `pcntl`, `sysvmsg`, `sockets`
- POSIX operating system (Linux, macOS)

Install
-------

```
composer require zotlo/phalcon-queue
```

Configure
---------

Register the ServiceProvider in your CLI bootstrap (`cli.php`).

```php
$di->register(new Phalcon\Queue\ServiceProvider());
```

Define supervisors in your Phalcon config. It is possible to define more than one queue.

```php
$di->setShared('config', function () {
    return new \Phalcon\Config\Config([
        'queues'   => [
            'adapter'     => 'mysql',    # mysql, redis, sqlite
            'dbIndex'     => 1,          # Redis database index (only redis)
            'supervisors' => [
                [
                    'queue'           => 'default', # Queue Name
                    'balance'         => 'auto',    # Balance Strategy (auto, simple)
                    'processes'       => 5,         # Maximum Process
                    'tries'           => 0,         # Job Maximum Tries (0 = unlimited)
                    'timeout'         => 90,        # Job Timeout (seconds)
                    'balanceMaxShift' => 5,         # Max processes to start/stop per scaling cycle
                    'balanceCooldown' => 3,         # Seconds between scaling checks
                    'debug'           => false      # Worker debug logging
                ],
                [
                    'queue'           => 'another-queue',
                    'balance'         => 'simple',
                    'processes'       => 5,
                    'tries'           => 0,
                    'timeout'         => 90,
                    'balanceMaxShift' => 5,
                    'balanceCooldown' => 3,
                    'debug'           => false
                ]
            ]
        ]
    ]);
});
```

Depending on the adapter, the matching service must also be registered in the DI:

- `mysql` / `sqlite`: a `db` service returning a `Phalcon\Db\Adapter\Pdo` adapter
- `redis`: a `redis` service returning a connected `\Redis` (phpredis) instance

Running Workers
---------------

Each queue is driven by a master process that spawns and supervises its workers:

```
php cli.php Queue run default
```

The master scales the worker pool between 1 and `processes` according to the
`balance` strategy:

- **auto**: scales workers based on pending/processing job counts, moving at most
  `balanceMaxShift` processes per `balanceCooldown` seconds.
- **simple**: drains the queue as quickly as possible.

### Graceful Exit

The master handles `SIGINT`, `SIGTERM` and `SIGHUP` so that it exits gracefully:
workers finish their current job before the process stops (e.g. a `C-c` keystroke
in a shell, or a supervisord stop).

### Supervisord

In production, run one master per queue under supervisord:

```
[program:phalcon-queue]
process_name = phalcon-queue
command = /usr/bin/php PHALCON_CLI_PATH/cli.php Queue run default
autostart = true
autorestart = true
user = root
stopsignal = SIGTERM
stopwaitsecs = 30
startretries = 3

[program:phalcon-queue-another]
process_name = another-queue
command = /usr/bin/php PHALCON_CLI_PATH/cli.php Queue run another-queue
autostart = true
autorestart = true
user = root
stopsignal = SIGTERM
stopwaitsecs = 30
startretries = 3
```

You must start each queue you define in the config.

Usage
-----

The first step is to define and implement the **Job** to be managed.

```php
<?php

namespace App\Jobs;

use Phalcon\Queue\Jobs\Job;

class MyJob extends Job
{
    public function handle(): void
    {
        // your code
    }
}
```

You can call the **dispatch** function anywhere you want.

```php
dispatch(new MyJob());
```

You can set the queue.

```php
dispatch(new MyJob())
    ->queue('default');
```

You can set a delay.

```php
dispatch(new MyJob())
    ->queue('default')
    ->delay(10); # Delay TTL (seconds)
```

You can dispatch a job batch.

```php
$jobArray = [
    new MyJob(),
    new MyJob(),
    ...
];

dispatchBatch($jobArray);

# Also, you can set queue with batch.
dispatchBatch($jobArray)
    ->queue('default');
```

Async Jobs
----------

You can also define tasks that you want to run asynchronously without creating any classes.

```php
async(function () {
    ...
});

# You can use the 'use' statement.
$uniqId = uniqid();

async(function () use ($uniqId) {
    $taskId = $uniqId;
    ...
});
```

Also, you can get the status of any job you start at any time. Use `await`, which
returns a `Phalcon\Queue\Jobs\Status` enum.

```php
$job = async(function () {
    ...
});

// Your app codes

// Waits until the job succeeds or fails.
$status = await($job); // Status::COMPLETED or Status::FAILED

// If you want to manage all states yourself, pass 'manageable' as true;
// pending/processing states are then returned immediately instead of blocking.
$status = await($job, true); // Status::PENDING, PROCESSING, FAILED or COMPLETED
```

### IMPORTANT: You can't do that

Closures run in a separate worker process, so captured references are not shared:

```php
$variable = "initial";

async(function () use (&$variable) {
    $variable = "changed";
});

echo $variable; # prints "initial"
```

Channels
--------

`Phalcon\Queue\Channel` lets you pass messages between your application and async
jobs (or between any processes). It is built on System V message queues
(`ext-sysvmsg`), so a channel created in your app can be written to from a worker
process running the job.

Create a channel, share it with an async job via `use`, and read the result back:

```php
use Phalcon\Queue\Channel;

$ch = Channel::make();

$token = uniqid();

async(function () use ($token, $ch) {
    // heavy work...
    sleep(rand(2, 10));

    $ch->write(json_encode(['status' => true, 'msg' => 'message received!', 'id' => $token]));
});

// Blocks until a message arrives (or the timeout expires).
$channelData = $ch->read();

echo $channelData; # {"status":true,"msg":"message received!","id":"..."}
```

### API

```php
$ch = Channel::make();          # create a single channel
$channels = Channel::makes(5);  # create multiple channels at once

$ch->write(string $message): bool;          # write a message (max 128 KB)
$ch->read(int $timeout = 15): ?string;      # read one message; null if none arrives within $timeout seconds
$ch->readAll(int $timeout = 15): array;     # collect all messages arriving within $timeout seconds
```

`read()` returns as soon as a message is available. `readAll()` keeps collecting
until the timeout expires, which is useful when multiple jobs report to the same
channel:

```php
$ch = Channel::make();

for ($i = 0; $i < 3; $i++) {
    async(function () use ($ch, $i) {
        $ch->write("job {$i} done");
    });
}

$messages = $ch->readAll(30); # ["job 0 done", "job 1 done", "job 2 done"]
```

Failed Jobs
-----------

Jobs that exhaust their `tries` (or fail with an exception) are stored in the failed
job storage (`jobs_failed` table for MySQL/SQLite, a prefixed key for Redis) together
with the exception details.

Management Commands
-------------------

The library ships Symfony Console commands for managing queues and failed jobs:

| Command         | Description                       |
|-----------------|-----------------------------------|
| `queue:failed`  | List all of the failed queue jobs |
| `queue:retry`   | Retry a failed queue job          |
| `queue:restart` | Restart all workers               |
| `queue:stop`    | Force stop all workers            |

Register them in a console application (see `extra/cli.php` for a full example):

```php
$console = new Symfony\Component\Console\Application('Phalcon Queue Management', '1.0.0');

$console->addCommands([
    (new \Phalcon\Queue\Commands\ListFailedJobCommand())->setDi($di),
    (new \Phalcon\Queue\Commands\RetryFailedJobCommand())->setDi($di),
    (new \Phalcon\Queue\Commands\RestartQueueCommand())->setDi($di),
    (new \Phalcon\Queue\Commands\ForceStopQueueCommand())->setDi($di),
]);

$console->run();
```
