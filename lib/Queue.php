<?php

namespace Phalcon\Queue;

use Phalcon\Config\Config;
use Phalcon\Di\Di;
use Phalcon\Queue\{Exceptions\QueueException, Processes\Process};
use Phalcon\Logger\ {
    Adapter\Stream as LoggerStreamAdapter,
    Formatter\Line as LoggerLine,
    Logger,
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
     */
    private function startMasterProcess(): void
    {
        do {
            $this->scale();

            sleep($this->balanceCoolDown);
        } while ($this->isRunning);
    }

    /**
     * Scale workers
     *
     * @return void
     */
    private function scale(): void
    {
        $this->processingCount = count($this->processes);
        $pendingJobs = $this->connector->adapter->getPendingJobs($this->queue);
        $pendingJobCount = count($pendingJobs);

        if ($this->debug) {
            try {
                $this->logger->info('PENDING COUNT: ' . $pendingJobCount);
                $this->logger->info('PROCESSING COUNT: ' . $this->processingCount);
            } catch (\Throwable $exception) {
                //
            }
        }

        if ($this->stopSignalStatus) {
            $this->scaleDown();

            if (empty($this->processes)) {
                exit(0);
            }

            return;
        }

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

                $this->processes[] = $process;
                $this->processingCount = count($this->processes);

                $process->start();
                if ($this->debug) {
                    try {
                        $this->logger->info('PROCESS STARTING PID: ' . $process->getPid());
                    } catch (\Throwable $e) {
                        //
                    }
                }

                continue;
            }

            break;
        }
    }

    /**
     * Stop idle process
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

        foreach ($this->processes as $processKey => $process) {
            if (!$process->isRunning() || $process->isIdle()) {
                if ($this->debug) {
                    try {
                        $this->logger->info('IDLE PROCESS STOPPING PID: ' . $process->getPid());
                    } catch (\Throwable $exception) {
                        //
                    }
                }

                $process->stop(0, SIGKILL);
                unset($this->processes[$processKey]);
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
     * @return void
     */
    private function configureLogger(): void
    {
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
                $this->logger->info('INITIALIZED PHALCON QUEUE LOGGER');
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
    }
}