<?php

namespace Phalcon\Queue\Commands;

use Phalcon\Queue\Exceptions\ConfigException;
use Phalcon\Queue\Exceptions\RuntimeException;
use Phalcon\Queue\Socket\Message;
use Phalcon\Queue\Socket\Socket;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RestartQueueCommand extends Command
{
    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('queue:restart')
            ->setDescription('Restart all workers.');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws ConfigException
     * @throws RuntimeException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->di->get('config');

        if (!isset($config->queues)) {
            throw new ConfigException('Queues config not found.');
        }

        if (empty($config->queues->toArray())) {
            throw new ConfigException('Queues config not found.');
        }

        if (empty($config->queues->supervisors)) {
            throw new ConfigException('Queues config not found.');
        }

        $msg = new Message(Message::CLI, Message::SERVER, Message::M_RESTART_QUEUE);
        foreach ($config->queues->supervisors as $supervisor) {
            $socket = new Socket(false, $supervisor->queue);

            $socket->send($msg);
            $socket->disconnect();

            $output->writeln('Restart queue "' . $supervisor->queue . '" success.');
        }

        return 1;
    }
}