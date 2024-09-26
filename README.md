PQueue - Queue Worker for Phalcon
======================================

PQueue provide a very simple way to run workers to consume queues (consumers) in PHP.
The library have been developed to be easily extended to work with different queue servers and
open to manage any kind of job.

Current implementations:

- **SQL** queue adapter.
- **Redis** queue adapter. _(development continue)_

_and more adapter planning development_

Worker
------

The lib has a worker class that run and infinite loop (can be stopped with some
conditions) and manage all the stages to process jobs:

- Get next job.
- Execute job.
- Job success then do.
- Job failed then do.
- Execution error then do.
- No jobs then do.
- Stop worker process pid.

The loop can be **stopped** under control using the following methods:

- **Stop Job**: The job handler allow to define a STOP job.
- **Max Iterations**: It can be specified when the object is declared.

Each worker has one queue source and manage one type of jobs. Many workers
can be working concurrently using the same queue source.

### Graceful Exit

The worker is also capable for handling some posix signals, *viz.* `SIGINT` and `SIGTERM` so
that it exits gracefully (waits for the current queue job to complete) when someone tries to
manually stop it (usually with a `C-c` keystroke in a shell).

Queue
-----

The lib provide an interface which allow to implement a queue connection for different queue
servers. Currently the lib provide following implementations:

- **SQL** queue adapter.
- **Redis** queue adapter. _(development continue)_

The queue interface manage all related with the queue system and abstract the job about that.

It require the queue system client:

- SQL : Phalcon\Db\Adapter\Pdo
- Redis : Predis\Client

And was well the source *queue name*. The consumer will need additional queues to manage the process:

- **Processing Queue**: It will store the item popped from source queue while it is being processed.
- **Failed Queue**: All Jobs that fail (according the Job definition) will be add in this queue.

Jobs
----

The job interface is used to manage the job received in the queue. It must manage the domain
business logic and **define the STOP job**.

The job is abstracted form the queue system, so the same job definition is able to work with
different queues interfaces. The job always receive the message body from the queue.

If you have different job types ( send mail, crop images, etc. ) and you use one queue, you can define **isMyJob**.
If job is not expected type, you can send back job to queue.

Install
-------

Require the package in your composer json file:

```
composer require zotlo/phalcon-queue
```

Configure
-----

Register the ServiceProvider in cli.php file.

```php
$di->register(new Phalcon\Queue\ServiceProvider());
```

Define Supervisors in Phalcon config. It is possible to define more than one Queue.

```php
$di->setShared('config', function () {
    return new \Phalcon\Config\Config([
        'queues'   => [
            'adapter'     => 'database', # redis, aws. very soon
            'supervisors' => [
                [
                    'queue'           => 'default', # Queue Name
                    'processes'       => 5,         # Maximum Process
                    'tries'           => 0,         # Job Maximum Tries
                    'timeout'         => 90,        # Job Timeout
                    'balanceMaxShift' => 5,         # Execute or Destory Process Count
                    'balanceCooldown' => 3,         # Check Process TTL (seconds)
                    'debug'           => false      # Debugging Worker
                ],
                [
                    'queue'           => 'another-queue',
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

Set Supervisor Config

```
[program:phalcon-queue]
process_name = phalcon-queue
command = /usr/bin/php PHALCON_CLI_PATH/cli.php Queue run default
autostart = true
autorestart = true
user = root
numprocs = 1
stopsignal = SIGTERM
stopwaitsecs = 30
startretries = 3
```

You must start each queue you define in supervisor.

```
[program:phalcon-queue]
process_name = another-queue
command = /usr/bin/php PHALCON_CLI_PATH/cli.php Queue run another-queue
autostart = true
autorestart = true
user = root
numprocs = 1
stopsignal = SIGTERM
stopwaitsecs = 30
startretries = 3
```

Usage
-----

The first step is to define and implement the **Job** to be managed.

```php
<?php

namespace App\Jobs;

use Phalcon\Queue\Jobs\Job;

class MyJob implements Job {

    public function handle(): void 
    {
        // your code
    }
    
}
```

You can call the **dispatch** function anywhere you want.

```php
dispatch(new MyJob())
```

You can set queue.

```php
dispatch(new MyJob())
    ->queue('default')
```

You can set delay.

```php
dispatch(new MyJob())
    ->queue('default')
    ->delay(10) # Delay TTL (seconds)
```

You can dispatch job batch.

```php
$jobArray = [
    new MyJob(),
    new MyJob(),
    ...
]

dispatchBatch($jobArray);

# Also, you can set queue with batch.
dispatchBatch($jobArray)
    ->queue('default');
```

## Async Job

You can also define tasks that you want to run asynchronously without creating any classes.

```php
async(function (){
    ...
});

# You can 'use' statement.
$uniqId = uniqid();

async(function () use ($uniqId){
    $taskId = $uniqId;
    ...
});
```

### IMPORTANT: You can't do that

```php
# You can 'use' statement.
$variable = "initial";

async(function () use (&$variable){
    $variable = "changed";
});

echo $variable; # print "initial"
```