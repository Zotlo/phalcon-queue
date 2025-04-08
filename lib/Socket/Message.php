<?php

namespace Phalcon\Queue\Socket;

class Message
{
    // Socket Message Format
    private const MESSAGE_FORMAT = '%s>%s|%s#%s';

    // Message owner and receivers.
    public const SERVER = 'SERVER';
    public const WORKER = 'WORKER';
    public const CLI = 'CLI';

    // Message fields.
    private string $from;
    private string $to;
    private string $message;
    private int $pid;

    // Messages
    public const M_RESTART_QUEUE = 'RESTART_QUEUE';
    public const M_FORCE_STOP_QUEUE = 'FORCE_STOP_QUEUE';

    public function __construct(string $from, string $to = null, string $message = null)
    {
        $this->from = $from;
        $this->pid = getmypid();

        // Parse receive socket message.
        if (is_null($to) || is_null($message)) {
            $this->parse($this->from);
        } else {
            $this->to = $to;
            $this->message = $message;
        }
    }

    /**
     * Parse receive socket message.
     * Example: WORKER>SERVER|00000#RUNNING
     *
     * @param string $message
     * @return void
     */
    private function parse(string $message): void
    {
        [$fromTo, $pidMessage] = explode('|', $message);
        [$from, $to] = explode('>', $fromTo);
        [$pid, $message] = explode('#', $pidMessage);

        $this->from = $from;
        $this->to = $to;
        $this->message = $message;
        $this->pid = (int)$pid;
    }

    /**
     * @return int
     */
    public function getPid(): int
    {
        return $this->pid;
    }

    /**
     * @return string
     */
    public function getFrom(): string
    {
        return $this->from;
    }

    /**
     * @return string
     */
    public function getTo(): string
    {
        return $this->to;
    }

    /**
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Example: WORKER>SERVER|00000#RUNNING
     * @return string
     */
    public function __toString(): string
    {
        return sprintf(self::MESSAGE_FORMAT, $this->from, $this->to, $this->pid, $this->message);
    }
}