<?php declare(strict_types=1);

namespace Phalcon\Queue\Tests\Integration;

use Phalcon\Queue\Channel;
use Phalcon\Queue\Tests\Support\IntegrationTestCase;
use Phalcon\Queue\Tests\Support\PurgesChannels;

/**
 * Scenario #4: the insert.php pattern — a Channel is captured by an async
 * closure, the worker writes to it from another process, and the test
 * process reads the message back over the System V message queue.
 */
final class ChannelIpcTest extends IntegrationTestCase
{
    use PurgesChannels;

    protected function makeQueueName(): string
    {
        return 'default'; // async() enqueues onto 'default'
    }

    protected function setUp(): void
    {
        if (!\extension_loaded('sysvmsg')) {
            $this->markTestSkipped('ext-sysvmsg is required for Channel.');
        }
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->purgeChannels();
        parent::tearDown();
    }

    public function testWorkerWritesToChannelReadByParent(): void
    {
        $channel = $this->track(Channel::make());
        $token   = uniqid('tok_');

        async(function () use ($channel, $token) {
            $channel->write(json_encode(['status' => true, 'token' => $token]));
        });

        $this->assertSame(1, $this->countRows('jobs'));

        $this->startMaster();

        $payload = $channel->read(30);

        $this->assertNotNull($payload, 'no message arrived on the channel. Master stderr: '
            . $this->master->getErrorOutput());

        $decoded = json_decode($payload, true);
        $this->assertTrue($decoded['status']);
        $this->assertSame($token, $decoded['token']);
    }
}
