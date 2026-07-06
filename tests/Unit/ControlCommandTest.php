<?php declare(strict_types=1);

namespace Phalcon\Queue\Tests\Unit;

use Phalcon\Config\Config;
use Phalcon\Di\FactoryDefault\Cli as CliDI;
use Phalcon\Queue\Commands\ForceStopQueueCommand;
use Phalcon\Queue\Commands\RestartQueueCommand;
use Phalcon\Queue\Exceptions\ConfigException;
use Symfony\Component\Console\Tester\CommandTester;
use PHPUnit\Framework\TestCase;

/**
 * Config validation for queue:restart and queue:stop. Their happy path
 * (sending a socket message to a live master) is covered by the integration
 * suite; here we assert they refuse to run without supervisor config.
 */
final class ControlCommandTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('phalcon')) {
            $this->markTestSkipped('ext-phalcon is required.');
        }
    }

    private function diWithoutSupervisors(): CliDI
    {
        $di = new CliDI();
        $di->setShared('config', fn () => new Config([
            'queues' => ['adapter' => 'sqlite'], // no supervisors
        ]));

        return $di;
    }

    public function testRestartThrowsWithoutSupervisors(): void
    {
        $command = (new RestartQueueCommand())->setDi($this->diWithoutSupervisors());

        $this->expectException(ConfigException::class);
        (new CommandTester($command))->execute([]);
    }

    public function testForceStopThrowsWithoutSupervisors(): void
    {
        $command = (new ForceStopQueueCommand())->setDi($this->diWithoutSupervisors());

        $this->expectException(ConfigException::class);
        (new CommandTester($command))->execute([]);
    }
}
