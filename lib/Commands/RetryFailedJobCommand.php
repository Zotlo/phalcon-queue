<?php

namespace Phalcon\Queue\Commands;

use Phalcon\Db\Adapter\AdapterInterface as DatabaseInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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

        return self::SUCCESS;
    }
}