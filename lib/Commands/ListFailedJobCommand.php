<?php

namespace Phalcon\Queue\Commands;

use Phalcon\Db\Adapter\AdapterInterface as DatabaseInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = new Table($output);

        /** @var DatabaseInterface $db */
        $db = $this->di->get('db');

        $failedJobs = $db->query('SELECT * FROM jobs_failed')
            ->fetchAll(\PDO::FETCH_OBJ);

        $rows = [];
        foreach ($failedJobs as $failedJob) {
            $jobClass = unserialize($failedJob->payload);
            $exception = json_decode($failedJob->exception);

            $rows[] = [
                $failedJob->id, $failedJob->job_id, get_class($jobClass), $failedJob->queue, $failedJob->attempts, $exception->message, $failedJob->failed_at
            ];
        }

        $table
            ->setHeaders(['#', 'Job ID', 'Job Class', 'Queue', 'Attempts', 'Exception Message', 'Failed Date'])
            ->setRows($rows);
        $table->render();

        return self::SUCCESS;
    }

}