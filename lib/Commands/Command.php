<?php declare(strict_types=1);

namespace Phalcon\Queue\Commands;

use Phalcon\Di\Di;
use Phalcon\Di\DiInterface as DependencyInjector;
use Symfony\Component\Console\Command\Command as Commands;
use Symfony\Component\Console\Application as Console;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

abstract class Command extends Commands
{
    /**
     * @var array|string[] $commands
     */
    private static array $commands = [
        ExampleCommand::class        => 'example',
        ListFailedJobCommand::class  => 'queue:failed',
        RetryFailedJobCommand::class => 'queue:retry'
    ];

    /**
     * Phalcon DI
     *
     * @var DependencyInjector $di
     */
    protected DependencyInjector $di;

    /**
     * @param DependencyInjector $di
     * @return self
     */
    public function setDi(DependencyInjector $di): self
    {
        $this->di = $di;

        return $this;
    }

    /**
     * Run command now
     *
     * @param string $class
     * @return bool
     */
    public static function dispatch(string $class): bool
    {
        if (isset(self::$commands[$class])) {
            $application = new Console();
            $application->add((new $class())->setDi(Di::getDefault()));

            $input = new ArrayInput(['command' => self::$commands[$class]]);
            $output = new BufferedOutput();

            try {
                $application->run($input, $output);
            } catch (\Exception $e) {
                return false;
            }

            return true;
        }

        return false;
    }
}