<?php

namespace Phalcon\Queue\Commands;

use Phalcon\Db\Adapter\AdapterInterface as DatabaseInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Redis as RedisClient;

class RetryFailedJobCommand extends Command
{
    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('queue:retry')
            ->setDescription('Retry a failed queue job');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->di->get('config');
        $adapter = $config->queues->adapter;

        switch ($adapter) {
            case 'database':
                /** @var DatabaseInterface $db */
                $db = $this->di->get('db');

                $failedJobs = $db->query('SELECT * FROM jobs_failed')
                    ->fetchAll(\PDO::FETCH_OBJ);

                foreach ($failedJobs as $failedJob) {
                    try {
                        $query = $db->query(
                            'INSERT INTO jobs (queue, payload, attempts, available_at, created_at) VALUES (:queue, :payload, :attempts, :available_at, :created_at)',
                            [
                                'queue'        => $failedJob->queue,
                                'payload'      => $failedJob->payload,
                                'attempts'     => $failedJob->attempts,
                                'available_at' => gmdate('Y-m-d H:i:s'),
                                'created_at'   => gmdate('Y-m-d H:i:s'),
                            ]
                        );

                        if ($query) {
                            $db->query('DELETE FROM jobs_failed WHERE id = :id', [
                                'id' => $failedJob->id
                            ]);
                        }
                    } catch (\Throwable $exception) {
                        //
                    }
                }
                break;
            case 'redis':
                /** @var RedisClient $redis */
                $redis = $this->di->get('redis');
                $redis->select($config->queues->dbIndex);

                do {
                    $failedJob = $redis->lPop('PHALCON_QUEUE_FAILED_JOBS');

                    if (empty($failedJob)) {
                        break;
                    }

                    $failedJob = json_decode($failedJob);
                    $failedJob->reserved_at = null;
                    $failedJob->available_at = gmdate('Y-m-d H:i:s');
                    unset($failedJob->job_id, $failedJob->failed_at, $failedJob->exception);

                    try {
                        $redis->lPush('PHALCON_QUEUE_' . $failedJob->queue . '_PENDING_JOBS', json_encode($failedJob));
                    } catch (\Throwable $exception) {
                        //
                    }
                } while (true);
        }

        return self::SUCCESS;
    }
}