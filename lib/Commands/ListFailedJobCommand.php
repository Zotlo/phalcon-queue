<?php

namespace Phalcon\Queue\Commands;

use Phalcon\Db\Adapter\AdapterInterface as DatabaseInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Redis as RedisClient;

class ListFailedJobCommand extends Command
{
    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('queue:failed')
            ->setDescription('List all of the failed queue jobs');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \RedisException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = new Table($output);
        $config = $this->di->get('config');
        $adapter = $config->queues->adapter;

        $rows = [];

        switch ($adapter) {
            case 'database':
                /** @var DatabaseInterface $db */
                $db = $this->di->get('db');

                $failedJobs = $db->query('SELECT * FROM jobs_failed LIMIT 100')
                    ->fetchAll(\PDO::FETCH_OBJ);

                foreach ($failedJobs as $failedJob) {
                    $jobClass = unserialize($failedJob->payload);
                    $exception = json_decode($failedJob->exception);

                    $rows[] = [
                        $failedJob->id, 'Database', $failedJob->job_id, get_class($jobClass), $failedJob->queue, $failedJob->attempts, $exception->message, $failedJob->failed_at
                    ];
                }
                break;
            case 'redis':
                /** @var RedisClient $redis */
                $redis = $this->di->get('redis');
                $redis->select($config->queues->dbIndex);
                $failedJobs = $redis->lRange('PHALCON_QUEUE_FAILED_JOBS', 0, 100);

                foreach ($failedJobs as $failedJob) {
                    $failedJob = json_decode($failedJob);

                    $jobClass = unserialize($failedJob->payload);
                    $exception = json_decode($failedJob->exception);

                    $rows[] = [
                        $failedJob->id, 'Redis', $failedJob->job_id, get_class($jobClass), $failedJob->queue, $failedJob->attempts, $exception->message, $failedJob->failed_at
                    ];
                }
                break;
            default:
                // TODO :: Maybe throw.
        }

        $table
            ->setHeaders(['#', 'Adapter', 'Job ID', 'Job Class', 'Queue', 'Attempts', 'Exception Message', 'Failed Date'])
            ->setRows($rows);
        $table->render();

        return self::SUCCESS;
    }

}