# Tests

PHPUnit test suite for `zotlo/phalcon-queue`. Everything runs against an
**isolated, throw-away SQLite** database — no MySQL/Redis server is needed and
the project's own `db.sqlite` is never touched.

## Running

```bash
composer dump-autoload          # once, so the Phalcon\Queue\Tests\ namespace resolves
composer test                   # everything
composer test:unit              # fast, in-process
composer test:integration       # spawns real master/worker processes (POSIX only)
```

Or directly:

```bash
vendor/bin/phpunit
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit tests/Integration/QueueWorkerTest.php
```

## Layout

- `Support/` — shared harness
  - `TestDi.php` — builds an isolated Phalcon CLI DI (SQLite) and registers it as default.
  - `console.php` — spawnable CLI mirroring the root `cli.php`; reads its DB path and
    queue name from the `PHALCON_QUEUE_TEST_DB` / `PHALCON_QUEUE_TEST_QUEUE` env vars.
    Used both as the master and (re-launched by `Process`) as each worker.
  - `DatabaseTestCase.php` — per-test temp SQLite file + freshly migrated tables.
  - `IntegrationTestCase.php` — starts/stops a real master, polls for state, isolates
    each test with a unique queue name (unique socket + rows).
  - `Jobs/` — `SentinelJob` (writes a marker), `FailingJob` (throws), `TimeoutJob` (sleeps).
- `Unit/` — deterministic, no subprocesses: `Message`, `Status`, `Job`, `Channel`,
  `Connector`, `Utils`, `Dispatcher`, `SqliteStorage`, `Socket`.
- `Integration/` — end-to-end: dispatch→process (`QueueWorkerTest`), `async()`
  (`AsyncTest`), graceful signal shutdown (`SignalTest`), cross-process `Channel`
  IPC (`ChannelIpcTest`), failure recording (`JobFailureTest`), timeout watchdog
  (`JobTimeoutTest`).

## Notes / behaviours pinned by these tests

- `Job::__serialize` lazily assigns `job-*` ids; round-trips through `serialize()`.
- `Connector` selects the adapter from `queues.adapter`; unknown → `ConnectorException`.
- SQLite `lock()` is exclusive per key (unique index) and reusable after `unlock()`.
- `getJobStatus()` reports **COMPLETED** for a job absent from both tables, and only
  returns `UNKNOWN` on a DB error (see `SqliteStorageTest`).
- `Socket::receive()` reads a single 32-byte frame and does not strip the trailing EOL
  (see `SocketTest`); the master's `check()` path buffers and trims properly.
```
