<?php

namespace Phalcon\Queue;

use Phalcon\Config\Config;
use Phalcon\Di\Di;
use Phalcon\Logger\{Adapter\Stream as LoggerStreamAdapter, Formatter\Line as LoggerLine, Logger,};
use Phalcon\Queue\{Exceptions\ConfigException,
    Exceptions\QueueException,
    Exceptions\RuntimeException,
    Socket\Message,
    Socket\Socket
};

final class Queue
{
    use Signal;

    /**
     * Debug Phalcon Queue with Logger
     *
     * @var bool $debug
     */
    private bool $debug = false;

    /**
     * Queue name
     *
     * @var string $queue
     */
    public string $queue = 'default';

    /**
     * Queue Balance Strategy
     *
     * @var string $balance
     */
    public string $balance = 'auto';

    /**
     * Worker Process Phalcon Task Name
     *
     * @var string
     */
    private string $workerTaskName;

    /**
     * Active processes
     *
     * @var array<Process> $processes
     */
    private array $processes = [];

    /**
     * Active process count
     *
     * @var int $processingCount
     */
    private int $processingCount = 0;

    /**
     * Maximum process count
     *
     * @var int $processMax
     */
    private int $processMax = 1;

    /**
     * Job extra attempts
     *
     * @var int $tries
     */
    private int $tries = 0;

    /**
     * Job timeout
     *
     * @var int $timeout
     */
    private int $timeout = 0;

    /**
     * Process fire count
     *
     * @var int $balanceMaxShift
     */
    private int $balanceMaxShift = 1;

    /**
     * Process cooling timeout
     *
     * @var int $balanceCoolDown
     */
    private int $balanceCoolDown = 5;

    /**
     * Master process status
     *
     * @var bool $isRunning
     */
    private bool $isRunning = true;

    /**
     * POSIX Signal Status
     *
     * @var bool $stopSignalStatus
     */
    public bool $stopSignalStatus = false;

    /**
     * @var string $lastLoggerInitializationDate
     */
    private string $lastLoggerInitializationDate;

    /**
     * Phalcon Application Config
     *
     * @var Config $config
     */
    private Config $config;

    /**
     * Storage Connector
     *
     * @var Connector $connector
     */
    private Connector $connector;

    /**
     * Socket Client
     *
     * @var Socket $socket
     */
    private Socket $socket;

    /**
     * Phalcon Application Logger
     *
     * @var Logger $logger
     */
    private Logger $logger;

    /**
     * @param string $queue
     * @return self
     */
    public function setQueue(string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }

    /**
     * @param string $workerTaskName
     * @return $this
     */
    public function setWorkerTaskName(string $workerTaskName): self
    {
        $this->workerTaskName = $workerTaskName;

        return $this;
    }

    /**
     * @param Connector $connector
     * @return $this
     */
    public function setConnector(Connector $connector): self
    {
        $this->connector = $connector;

        return $this;
    }

    /**
     * @param Socket $socket
     * @return $this
     */
    public function setSocket(Socket $socket): self
    {
        $this->socket = $socket;

        return $this;
    }

    /**
     * @param Config $config
     * @return $this
     */
    public function setConfig(Config $config): self
    {
        $this->config = $config;

        return $this;
    }

    /**
     * @return void
     * @throws QueueException
     */
    public function work(): void
    {
        $this->configureQueueSettings();

        $this->configureLogger();

        $this->configureSignal();

        $this->connector->adapter->checkTableAndMigrateIfNecessary();

        $this->startMasterProcess();
    }

    /**
     * Handle POSIX Signal
     * @param int $signal
     * @return void
     */
    public function handleSignal(int $signal): void
    {
        switch ($signal) {
            case SIGTERM:
            case SIGHUP:
            case SIGINT:
                if (empty($this->processes)) {
                    exit(0);
                }
                $this->stopSignalStatus = true;
                break;
            default:
                //
        }
    }

    /**
     * @return void
     * @throws QueueException
     */
    private function startMasterProcess(): void
    {
        $this->lastLoggerInitializationDate = gmdate('Y-m-d');

        do {
            $this->checkWorkerMessages();

            $this->scale();

            sleep($this->balanceCoolDown);

            $this->configureLogger(true);
        } while ($this->isRunning);
    }

    /**
     * Checks messages from worker processes and updates their status if necessary.
     *
     * @return void
     */
    private function checkWorkerMessages(): void
    {
        try {
            $this->socket->check(function (string $message, mixed $client) {
                $message = new Message($message);

                if ($message->getTo() === Message::SERVER) {

                    // Worker Messages
                    if ($message->getFrom() === Message::WORKER) {
                        if (isset($this->processes[$message->getPid()])) {
                            $this->processes[$message->getPid()]->setIdle($message->getMessage() === Process::STATUS_IDLE);
                        }
                    }

                    // CLI Messages
                    if ($message->getFrom() === Message::CLI) {
                        switch ($message->getMessage()) {
                            case Message::M_RESTART_QUEUE:
                                if ($this->debug) {
                                    try {
                                        $this->logger->debug(sprintf('RESTARTING QUEUE: %s', $this->queue));
                                    } catch (\Throwable $exception) {
                                        //
                                    }
                                }

                                if (!empty($this->processes)) {
                                    foreach ($this->processes as $process) {
                                        $process->stop(0, SIGTERM);
                                    }
                                }
                                break;
                            default:
                        }
                    }
                }

            });
        } catch (RuntimeException $e) {
            //
        }
    }

    /**
     * Scale workers
     *
     * @return void
     * @throws QueueException
     */
    private function scale(): void
    {
        $this->processingCount = count($this->processes);
        $pendingJobs = $this->connector->adapter->getPendingJobs($this->queue);
        $pendingJobCount = count($pendingJobs);

        if ($this->debug) {
            try {
                $this->logger->debug('PENDING COUNT: ' . $pendingJobCount);
                $this->logger->debug('PROCESSING COUNT: ' . $this->processingCount);
            } catch (\Throwable $exception) {
                //
            }
        }

        // If stop signal is sent, close processes.
        if ($this->stopSignalStatus) {
            $this->scaleDown();

            if (empty($this->processes)) {
                exit(0);
            }

            return;
        }

        // Balance Strategy
        switch ($this->balance) {
            case 'auto':
                $this->balanceAuto($pendingJobs, $pendingJobCount);
                break;
            case 'simple':
                $this->balanceSimple($pendingJobs, $pendingJobCount);
                break;
            default:
                // If there is no balance strategy, close all processes and stop the system.
                if (empty($this->processes)) {
                    throw new ConfigException('Queue balance strategy is not defined.');
                }
                $this->scaleDown();
        }
    }

    /**
     * Adjusts the processing resources automatically based on the number of pending jobs.
     * The aim of this strategy is to consume pending tasks in a balanced manner.
     *
     * @param array $pendingJobs The collection of pending jobs that need processing.
     * @param int $pendingJobCount The count of pending jobs to be processed.
     * @return void
     */
    private function balanceAuto(array $pendingJobs, int $pendingJobCount): void
    {
        if (!empty($pendingJobs)) {
            if ($this->processingCount < $this->processMax && $this->processingCount <= $pendingJobCount) {
                $this->scaleUp($pendingJobCount);
            } else {
                if ($this->processingCount > $pendingJobCount) {
                    $this->scaleDown();
                }
            }
        } else {
            if ($this->processingCount > $pendingJobCount) {
                $this->scaleDown();
            }
        }
    }

    /**
     * Balances the system load by scaling up or down based on the number of pending jobs.
     * The goal of this strategy is to quickly exhaust pending work.
     *
     * @param array $pendingJobs An array of jobs that are waiting to be processed.
     * @param int $pendingJobCount The count of pending jobs to be used for balancing.
     * @return void
     */
    private function balanceSimple(array $pendingJobs, int $pendingJobCount): void
    {
        if (!empty($pendingJobs)) {
            if ($this->processingCount < $this->processMax) {
                $this->scaleUp($pendingJobCount);
            } else {
                if ($this->processingCount > $pendingJobCount) {
                    $this->scaleDown();
                }
            }
        } else {
            if ($this->processingCount > $pendingJobCount) {
                $this->scaleDown();
            }
        }
    }

    /**
     * Run new process
     *
     * @param int $pendingJobCount
     * @return void
     */
    private function scaleUp(int $pendingJobCount): void
    {
        $balanceShift = $this->balanceMaxShift;
        if ($this->balanceMaxShift > $pendingJobCount) {
            $balanceShift = $pendingJobCount;

            if ($balanceShift === 0) {
                $balanceShift = 1;
            }
        }

        if ($this->debug) {
            try {
                $this->logger->debug('SCALING UP SHIFT: ' . $balanceShift);
            } catch (\Throwable $exception) {
                //
            }
        }

        for ($i = 1; $i <= $balanceShift; $i++) {
            if ($this->processingCount <= $this->processMax) {
                $process = new Process($this->queue, $this->workerTaskName);

                $this->processingCount = count($this->processes);
                $process->start();
                if ($this->debug) {
                    try {
                        $this->logger->debug('PROCESS STARTING PID: ' . $process->getPid());
                    } catch (\Throwable $e) {
                        //
                    }
                }
                $this->processes[$process->getPid()] = $process;

                continue;
            }

            break;
        }
    }

    /**
     * Stop idle & zombie process.
     *
     * @return void
     */
    private function scaleDown(): void
    {
        if ($this->debug) {
            try {
                $this->logger->debug('SCALING DOWN');
            } catch (\Throwable $exception) {
                //
            }
        }

        $stopBalanceMaxShift = $this->balanceMaxShift;
        foreach ($this->processes as $processKey => $process) {
            if ($stopBalanceMaxShift <= 0) {
                break;
            }

            if (!$process->isRunning() || $process->isIdle()) {
                if ($this->debug) {
                    try {
                        if (is_null($process->getPid())) {
                            $this->logger->debug('IDLE ZOMBIE PROCESS FORCE STOPPING PID: ' . $processKey . ' EXTRA: ' . json_encode([
                                    'code'     => $process->getExitCode(),
                                    'codeText' => $process->getExitCodeText()
                                ]));
                        } else {
                            $this->logger->debug('IDLE ACTIVE PROCESS GRACEFUL STOPPING PID: ' . $processKey);
                        }
                    } catch (\Throwable $exception) {
                        //
                    }
                }

                $process->stop(0, SIGKILL);
                unset($this->processes[$processKey]);
                if (!$this->stopSignalStatus) {
                    $stopBalanceMaxShift--;
                }
            } else {
                // Send Stop Signals Child Process
                if ($this->stopSignalStatus) {
                    try {
                        $process->signal(SIGTERM);
                    } catch (\Throwable $exception) {
                        //
                    }
                }
            }
        }
    }

    /**
     * Initialize Phalcon Queue Settings
     *
     * @return void
     */
    private function configureQueueSettings(): void
    {
        $configs = $this->config->queues->supervisors;

        foreach ($configs as $config) {
            if ($config->queue === $this->queue) {
                if (isset($config->balance)) {
                    $this->balance = $config->balance;
                }

                if (isset($config->processes)) {
                    $this->processMax = (int)$config->processes;
                }

                if (isset($config->tries)) {
                    $this->tries = (int)$config->tries;
                }

                if (isset($config->timeout)) {
                    $this->timeout = (int)$config->timeout;
                }

                if (isset($config->balanceMaxShift)) {
                    $this->balanceMaxShift = (int)$config->balanceMaxShift;
                }

                if (isset($config->balanceCoolDown)) {
                    $this->balanceCoolDown = (int)$config->balanceCoolDown;
                }

                if (isset($config->debug)) {
                    $this->debug = (bool)$config->debug;
                }

                break;
            }
        }
    }

    /**
     * Configure Phalcon Queue Logger
     *
     * @param bool $restart
     * @return void
     */
    private function configureLogger(bool $restart = false): void
    {
        if ($restart && $this->lastLoggerInitializationDate === gmdate('Y-m-d')) {
            return;
        }

        $loggerKeys = [
            'log', 'logger', 'monolog'
        ];

        foreach ($loggerKeys as $loggerKey) {
            try {
                $this->logger = Di::getDefault()->get($loggerKey);
            } catch (\Throwable $exception) {
                //
            }
        }

        $formatter = new LoggerLine();
        $formatter->setFormat('[%date%] [%level%] [' . $this->queue . '] %message%');
        $formatter->setDateFormat('Y-m-d H:i:s');

        if (!isset($this->logger)) {
            $stream = new LoggerStreamAdapter('monitor.log');
            $stream->setFormatter($formatter);

            $this->logger = new Logger('Phalcon Queue Logger', [
                'main' => $stream
            ]);

            try {
                if ($restart) {
                    $this->logger->debug('RESTARTED PHALCON QUEUE LOGGER');
                } else {
                    $this->logger->debug('INITIALIZED PHALCON QUEUE LOGGER');
                }
            } catch (\Throwable $exception) {
                //
            }
        } else {
            $configuredAdapters = [];
            foreach ($this->logger->getAdapters() as $key => $adapter) {
                $adapter->setFormatter($formatter);
                $configuredAdapters[$key] = $adapter;
            }

            $this->logger->setAdapters($configuredAdapters);
        }

        if ($restart) {
            $this->lastLoggerInitializationDate = gmdate('Y-m-d');
        }
    }
}