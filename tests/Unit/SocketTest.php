<?php declare(strict_types=1);

namespace Phalcon\Queue\Tests\Unit;

use Phalcon\Queue\Exceptions\RuntimeException;
use Phalcon\Queue\Socket\Message;
use Phalcon\Queue\Socket\Socket;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the Unix-domain-socket transport with a server and a client
 * living in the same process (the socket is non-blocking, so this works).
 */
final class SocketTest extends TestCase
{
    private string $id;
    private ?Socket $server = null;
    private ?Socket $client = null;

    protected function setUp(): void
    {
        if (!extension_loaded('sockets') || \stripos(PHP_OS, 'WIN') === 0) {
            $this->markTestSkipped('POSIX sockets are required.');
        }
        $this->id = 'sock_' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->client = null;
        $this->server = null;
    }

    public function testClientMessageReachesServer(): void
    {
        $this->server = new Socket(true, $this->id);
        $this->client = new Socket(false, $this->id);

        $this->client->send(new Message(Message::WORKER, Message::SERVER, 'RUNNING'));

        $received = null;
        $this->pollServer(function (string $message) use (&$received) {
            $received = new Message($message);
        });

        $this->assertNotNull($received, 'server never received the client message');
        $this->assertSame(Message::WORKER, $received->getFrom());
        $this->assertSame('RUNNING', $received->getMessage());
    }

    public function testServerBroadcastReachesClient(): void
    {
        $this->server = new Socket(true, $this->id);
        $this->client = new Socket(false, $this->id);

        // Client must be registered on the server first (via an accept).
        $this->client->send(new Message(Message::WORKER, Message::SERVER, 'IDLE'));
        $this->pollServer(fn () => null);

        // NOTE: Socket::receive() reads a single 32-byte chunk, so the whole
        // wire string (FROM>TO|PID#MSG) must stay under that limit here.
        $this->server->send(new Message(Message::SERVER, Message::WORKER, 'IDLE'));

        $reply = null;
        $ok = $this->waitUntil(function () use (&$reply) {
            $reply = $this->client->receive();
            return $reply !== null;
        });

        $this->assertTrue($ok, 'client never received the broadcast');
        // receive() returns the raw frame (trailing EOL not stripped, unlike check()).
        $this->assertSame('IDLE', trim($reply->getMessage()));
    }

    public function testCheckRejectedOnClient(): void
    {
        $this->server = new Socket(true, $this->id);
        $this->client = new Socket(false, $this->id);

        $this->expectException(RuntimeException::class);
        $this->client->check(fn () => null);
    }

    public function testReceiveRejectedOnServer(): void
    {
        $this->server = new Socket(true, $this->id);

        $this->expectException(RuntimeException::class);
        $this->server->receive();
    }

    /**
     * Repeatedly pump the server until it dispatches at least one message
     * (or a short deadline passes).
     */
    private function pollServer(callable $callback): void
    {
        $seen = 0;
        $this->waitUntil(function () use ($callback, &$seen) {
            $seen += $this->server->check($callback);
            return $seen > 0;
        });
    }

    private function waitUntil(callable $condition, float $timeout = 3.0): bool
    {
        $deadline = microtime(true) + $timeout;
        do {
            if ($condition()) {
                return true;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        return (bool) $condition();
    }
}
