<?php declare(strict_types=1);

namespace Phalcon\Queue\Tests\Unit;

use Phalcon\Queue\Channel;
use Phalcon\Queue\Tests\Support\PurgesChannels;
use PHPUnit\Framework\TestCase;

final class ChannelTest extends TestCase
{
    use PurgesChannels;

    protected function setUp(): void
    {
        if (!extension_loaded('sysvmsg')) {
            $this->markTestSkipped('ext-sysvmsg is required for Channel.');
        }
    }

    protected function tearDown(): void
    {
        // Channel leaks a kernel message queue; remove the ones we created.
        $this->purgeChannels();
    }

    public function testMakeReturnsInstance(): void
    {
        $this->assertInstanceOf(Channel::class, $this->makeChannel());
    }

    public function testWriteThenRead(): void
    {
        $channel = $this->makeChannel();

        $this->assertTrue($channel->write('hello world'));
        $this->assertSame('hello world', $channel->read(2));
    }

    public function testReadReturnsNullWhenEmpty(): void
    {
        $channel = $this->makeChannel();

        $this->assertNull($channel->read(1));
    }

    public function testReadAllDrainsEveryMessage(): void
    {
        $channel = $this->makeChannel();
        $channel->write('a');
        $channel->write('b');
        $channel->write('c');

        $messages = $channel->readAll(1);

        sort($messages);
        $this->assertSame(['a', 'b', 'c'], $messages);
    }

    public function testChannelsAreIndependent(): void
    {
        $one = $this->makeChannel();
        $two = $this->makeChannel();

        $one->write('only-in-one');

        // The second channel has a different key and must not see it.
        $this->assertNull($two->read(1));
        // Drain so we don't leave a message in the kernel queue.
        $this->assertSame('only-in-one', $one->read(1));
    }

    public function testMakesReturnsRequestedCount(): void
    {
        $channels = Channel::makes(3);
        foreach ($channels as $channel) {
            $this->track($channel);
        }

        $this->assertCount(3, $channels);
        $this->assertContainsOnlyInstancesOf(Channel::class, $channels);
    }
}
