<?php declare(strict_types=1);

namespace Phalcon\Queue\Tests\Unit;

use Phalcon\Config\Config;
use Phalcon\Di\FactoryDefault\Cli as CliDI;
use Phalcon\Queue\Exceptions\ConfigException;
use Phalcon\Queue\Utils;
use PHPUnit\Framework\TestCase;

final class UtilsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('phalcon')) {
            $this->markTestSkipped('ext-phalcon is required.');
        }
    }

    public function testReturnsConfigWhenComplete(): void
    {
        $di = new CliDI();
        $di->setShared('config', fn () => new Config([
            'queues' => [
                'adapter'     => 'sqlite',
                'supervisors' => [['queue' => 'default']],
            ],
        ]));

        $config = Utils::discoveryApplicationConfig($di);

        $this->assertInstanceOf(Config::class, $config);
        $this->assertSame('sqlite', $config->queues->adapter);
    }

    public function testThrowsWhenConfigServiceMissing(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('configuration is missing');

        Utils::discoveryApplicationConfig(new CliDI());
    }

    public function testThrowsWhenQueuesSectionMissing(): void
    {
        $di = new CliDI();
        $di->setShared('config', fn () => new Config(['something' => 'else']));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('queue configuration is missing');

        Utils::discoveryApplicationConfig($di);
    }

    public function testThrowsWhenSupervisorsMissing(): void
    {
        $di = new CliDI();
        $di->setShared('config', fn () => new Config([
            'queues' => ['adapter' => 'sqlite'],
        ]));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('supervisor configuration is missing');

        Utils::discoveryApplicationConfig($di);
    }
}
