<?php declare(strict_types=1);

namespace Phalcon\Queue\Tests\Support;

use Phalcon\Queue\Channel;

/**
 * Channel has no public way to destroy its underlying System V message queue,
 * so every Channel leaks one kernel queue for the lifetime of the machine.
 * The host has a hard limit (macOS: kern.sysv.msgmni, typically 40), so an
 * un-cleaned test suite eventually exhausts it and msg_get_queue() returns
 * false. This trait tracks the channels a test creates and removes their
 * kernel queues on teardown, keeping the suite repeatable.
 */
trait PurgesChannels
{
    /** @var array<Channel> */
    private array $trackedChannels = [];

    /**
     * Create a Channel and register it for cleanup.
     */
    protected function makeChannel(): Channel
    {
        return $this->track(Channel::make());
    }

    /**
     * Register an already-created Channel for cleanup.
     */
    protected function track(Channel $channel): Channel
    {
        $this->trackedChannels[] = $channel;
        return $channel;
    }

    protected function purgeChannels(): void
    {
        foreach ($this->trackedChannels as $channel) {
            // ReflectionMethod can invoke private methods directly since PHP 8.1.
            $key   = (new \ReflectionMethod($channel, 'key'))->invoke($channel);
            $queue = @msg_get_queue($key);
            if ($queue !== false) {
                @msg_remove_queue($queue);
            }
        }
        $this->trackedChannels = [];
    }
}
