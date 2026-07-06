<?php declare(strict_types=1);

namespace Phalcon\Queue\Tests\Unit;

use Phalcon\Queue\Process;
use Phalcon\Queue\Socket\Message;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    public function testStringifiesInWireFormat(): void
    {
        $message = new Message(Message::WORKER, Message::SERVER, Process::STATUS_RUNNING);

        // FROM>TO|PID#MESSAGE
        $this->assertMatchesRegularExpression(
            '/^WORKER>SERVER\|\d+#RUNNING$/',
            (string) $message
        );
    }

    public function testUsesCurrentPidWhenComposing(): void
    {
        $message = new Message(Message::CLI, Message::SERVER, Message::M_RESTART_QUEUE);

        $this->assertSame(getmypid(), $message->getPid());
        $this->assertSame(Message::CLI, $message->getFrom());
        $this->assertSame(Message::SERVER, $message->getTo());
        $this->assertSame(Message::M_RESTART_QUEUE, $message->getMessage());
    }

    public function testParsesReceivedWireString(): void
    {
        $message = new Message('WORKER>SERVER|4242#RUNNING');

        $this->assertSame(Message::WORKER, $message->getFrom());
        $this->assertSame(Message::SERVER, $message->getTo());
        $this->assertSame(4242, $message->getPid());
        $this->assertSame('RUNNING', $message->getMessage());
    }

    public function testComposeThenParseRoundTrip(): void
    {
        $original = new Message(Message::WORKER, Message::SERVER, Process::STATUS_IDLE);
        $parsed   = new Message((string) $original);

        $this->assertSame($original->getFrom(), $parsed->getFrom());
        $this->assertSame($original->getTo(), $parsed->getTo());
        $this->assertSame($original->getMessage(), $parsed->getMessage());
        $this->assertSame($original->getPid(), $parsed->getPid());
    }
}
