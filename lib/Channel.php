<?php

namespace Phalcon\Queue;

use Phalcon\Queue\Socket\Message;
use Phalcon\Queue\Socket\Socket;

final class Channel
{
    /** @var array $channels */
    private static array $channels = [];

    /** @var string $id */
    private string $id;

    public function __construct()
    {
        $this->id = strtoupper(uniqid());
    }

    /**
     * Creates a specified number of Channel objects and stores them.
     *
     * @param int $size
     * @return array<self>
     */
    public static function make(int $size): array
    {
        for ($i = 0; $i < $size; $i++) {
            self::$channels[] = new Channel();
        }

        return self::$channels;
    }

    /**
     * @param int $timeout
     * @return null|string
     */
    public function read(int $timeout = 15): ?string
    {
        $ts = microtime(true);
        $q = msg_get_queue($this->key());

        while (microtime(true) - $ts < $timeout) {
            if (@msg_receive($q, 1, $type, 131072, $message, false, MSG_IPC_NOWAIT)) {
                break;
            }
            usleep(100000);
        }

        if (isset($message) && is_string($message)) {
            return $message;
        }

        return null;
    }

    public function write(string $message): bool
    {
        $q = msg_get_queue($this->key());
        return msg_send($q, 1, $message);
    }

    /**
     * @return string
     */
    private function key(): string
    {
        return hexdec(substr(md5($this->id), 0, 8));
    }
}