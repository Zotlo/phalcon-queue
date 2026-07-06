# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`zotlo/phalcon-queue` — a queue worker library for Phalcon 5 (PHP 8.1+). Library code lives in `lib/` (PSR-4 namespace `Phalcon\Queue\`). The repo root also contains a local development harness (`cli.php`, `config.php`, `insert.php`) used to run the queue manually against a local SQLite/MySQL/Redis backend — these files are for manual testing, not part of the distributed library.

There is no test suite, linter, or CI configured. Verification is manual: dispatch jobs and run a supervisor.

## Development commands

```bash
composer install

# Start the master/supervisor process for a queue (uses config.php for DI/config)
php cli.php Queue run default

# Insert demo jobs (uses async() closures)
php insert.php
```

Management commands (Symfony Console, see `extra/cli.php` for a wiring example):

- `queue:failed` — list failed jobs (`ListFailedJobCommand`)
- `queue:retry` — retry failed jobs (`RetryFailedJobCommand`)
- `queue:restart` — restart queue processes (`RestartQueueCommand`)
- `queue:stop` — force-stop queue processes (`ForceStopQueueCommand`)

Required PHP extensions: `pdo`, `redis`, `pcntl`, `sysvmsg`, `sockets`, `phalcon` (5.x). The process model is POSIX-only.

## Architecture

Master/worker process model with three pluggable storage backends (MySQL, Redis, SQLite).

**Dispatch path:** consumer code calls the global helpers in `lib/functions.php` — `dispatch(Job)`, `dispatchBatch(array)`, `async(Closure)`, `await(Job)`. These go through `Dispatcher` (`lib/Dispatcher.php`), which serializes the job (closures via `laravel/serializable-closure` into `Jobs\AsyncJob`) and inserts it into storage through a connector. Note `async()` closures cannot mutate captured-by-reference variables — they execute in another process.

**Storage:** `Connector` (`lib/Connector.php`) is the factory that picks the adapter from config (`queues.adapter` = `mysql` | `redis` | `sqlite`). Adapters live in `lib/Connectors/`; MySQL and SQLite share `PDOStorage` and use tables `jobs` / `jobs_failed` (SQLite adds `jobs_locked` for locking; MySQL uses `GET_LOCK`/`RELEASE_LOCK`). Redis uses key prefix `PHALCON_QUEUE_` and the `dbIndex` config value.

**Master process** (`php cli.php Queue run <queue>` → `Tasks/QueueTask::runAction` → `lib/Queue.php`): reads the matching supervisor entry from config, spawns worker subprocesses via `lib/Process.php` (Symfony Process wrapping `cli.php QueueWorker run <queue>`), and scales the pool between 1 and `processes` according to the `balance` strategy (`auto` scales on pending/processing counts; `simple` drains quickly), moving at most `balanceMaxShift` workers per `balanceCooldown` seconds. Handles SIGTERM/SIGINT/SIGHUP for graceful shutdown (waits for in-flight jobs).

**Workers** (`lib/Tasks/WorkerTask.php`): loop polling storage for pending jobs (`available_at <= now`, unreserved), reserve the job, unserialize the payload, and run `handle()` under a `pcntl` SIGALRM timeout. Success deletes the job; failure records it in `jobs_failed` with the exception, honoring `tries`. Workers report IDLE/RUNNING state to the master over a Unix domain socket (`lib/Socket/`, message format in `Socket/Message.php`); `lib/Channel.php` uses System V message queues (`ext-sysvmsg`) for inter-process communication.

**Integration into a Phalcon app:** register `Phalcon\Queue\ServiceProvider` in the app's CLI bootstrap (registers `QueueTask`/`QueueWorkerTask` in the DI), and define `queues.supervisors` in the shared `config` service. Each supervisor entry needs: `queue`, `balance`, `processes`, `tries` (0 = unlimited), `timeout`, `balanceMaxShift`, `balanceCooldown`, `debug`. The `db` service (Phalcon PDO adapter) or `redis` service must also be registered, matching the chosen adapter. Production runs one master per queue under supervisord (see README).

**Job contract:** implement `Phalcon\Queue\Jobs\Job` with a `handle(): void` method. Job states are the `Jobs\Status` enum: pending, processing, failed, completed, unknown.
