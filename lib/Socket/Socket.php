<?php declare(strict_types=1);

namespace Phalcon\Queue\Socket;

use Phalcon\Queue\Exceptions\RuntimeException;

class Socket
{
    /**
     * Socket resource.
     * @var resource
     */
    private $socket;

    /**
     * Connected socket client resources.
     *
     * @var array $clients
     */
    private array $clients = [];

    /**
     * Socket master server status.
     *
     * @var bool $isServer
     */
    private bool $isServer;

    /**
     * Socket 'sock' path.
     *
     * @var string $socketPath
     */
    private string $socketPath;

    /**
     * Socket read message buffer size.
     *
     * @var int $bufferSize
     */
    private int $bufferSize = 32;

    /**
     * Socket Constructor
     *
     * @param bool $isServer
     * @param bool $isChannel
     * @param string $identifier
     * @throws RuntimeException
     */
    public function __construct(bool $isServer = false, bool $isChannel = false, string $identifier = 'default')
    {
        $this->isServer = $isServer;

        // Path settings.
        $basePath = sys_get_temp_dir() . '/phalcon_queue';
        if (is_dir($basePath) === false) {
            mkdir($basePath);
        }

        // Socket path settings.
        $this->socketPath = $basePath . '/socket_' . $identifier . '.sock';
        if ($isChannel) {
            $this->bufferSize = 128;
            $this->socketPath = $basePath . '/channel_' . $identifier . '.sock';
        }

        $this->init();
    }

    /**
     * Initialize Socket
     * @throws RuntimeException
     */
    private function init(): void
    {
        if ($this->isServer) {
            if (file_exists($this->socketPath)) {
                unlink($this->socketPath);
            }

            $this->socket = stream_socket_server(
                "unix://{$this->socketPath}",
                $errno,
                $errstr
            );

            if (!$this->socket) {
                throw new RuntimeException(sprintf("Socket Server Error: %s (%s)", $errstr, $errno));
            }

        } else {
            $retries = 5;
            $connected = false;

            while ($retries > 0 && !$connected) {
                $this->socket = @stream_socket_client(
                    "unix://{$this->socketPath}",
                    $errno,
                    $errstr
                );

                if ($this->socket) {
                    $connected = true;
                } else {
                    $retries--;
                    usleep(rand(30000, 50000));
                }
            }

            if (!$connected) {
                throw new RuntimeException(sprintf("Socket Connect Error: %s (%s)", $errstr, $errno));
            }
        }

        stream_set_blocking($this->socket, false);
    }

    /**
     * Check client message
     *
     * @param callable $callback ($message, $client)
     * @return int
     * @throws RuntimeException
     */
    public function check(callable $callback): int
    {
        if (!$this->isServer) {
            throw new RuntimeException("This method only use master process");
        }

        $messageCount = 0;
        $read = array_merge([$this->socket], $this->clients);
        $write = $except = null;
        $buffers = [];

        while (stream_select($read, $write, $except, 0, 0) > 0) {
            if (in_array($this->socket, $read)) {
                $client = @stream_socket_accept($this->socket);
                if ($client) {
                    stream_set_blocking($client, false);
                    $this->clients[] = $client;
                    $buffers[(int)$client] = '';
                }
                unset($read[array_search($this->socket, $read)]);
            }

            foreach ($read as $client) {
                $buffer = '';
                while (true) {
                    $chunk = @fread($client, $this->bufferSize);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $buffer .= $chunk;
                    if (strlen($chunk) < $this->bufferSize) {
                        break;
                    }
                }

                if ($buffer === false || ($buffer === '' && feof($client))) {
                    $this->removeClient($client);
                    unset($buffers[(int)$client]);
                    continue;
                }

                if ($buffer !== '') {
                    $clientId = (int)$client;
                    $buffers[$clientId] = isset($buffers[$clientId]) ? $buffers[$clientId] . $buffer : $buffer;

                    $lines = array_filter(explode(PHP_EOL, $buffers[$clientId]));
                    if (!empty($lines)) {
                        $lastLine = end($lines);
                        $callback(trim($lastLine), $client);
                        $messageCount++;
                        $buffers[$clientId] = '';
                    }
                }
            }

            $read = array_merge([$this->socket], $this->clients);
        }

        return $messageCount;
    }

    /**
     * Send socket message.
     *
     * @param Message $message
     * @return bool
     * @throws RuntimeException
     */
    public function send(Message $message): bool
    {
        if ($this->isServer) {
            return $this->broadcast($message, true);
        }

        return @fwrite($this->socket, $message . PHP_EOL) !== false;
    }

    /**
     * Receive socket server message.
     *
     * @return Message|null
     * @throws RuntimeException
     */
    public function receive(): ?Message
    {
        if ($this->isServer) {
            throw new RuntimeException("This method only use client process");
        }

        $message = @fread($this->socket, $this->bufferSize);

        if (empty($message)) {
            return null;
        }

        return new Message($message);
    }

    /**
     * Send message all clients.
     *
     * @param Message $message
     * @param bool $bypassThrow
     * @return bool
     * @throws RuntimeException
     */
    public function broadcast(Message $message, bool $bypassThrow = false): bool
    {
        if (!$bypassThrow && !$this->isServer) {
            throw new RuntimeException("This method only use master process");
        }

        $success = true;
        foreach ($this->clients as $client) {
            if (@fwrite($client, $message . PHP_EOL) === false) {
                $success = false;
            }
        }
        return $success;
    }

    /**
     * Disconnect socket server.
     *
     * @return bool
     */
    public function disconnect(): bool
    {
        return fclose($this->socket);
    }

    /**
     * @return array
     */
    public function subscribers(): array
    {
        return $this->clients;
    }

    /**
     * Remove client.
     *
     * @param resource $client
     */
    private function removeClient($client): void
    {
        $key = array_search($client, $this->clients);
        if ($key !== false) {
            unset($this->clients[$key]);
        }
        @fclose($client);
    }

    public function __destruct()
    {
        if ($this->isServer) {
            foreach ($this->clients as $client) {
                @fclose($client);
            }

            if (file_exists($this->socketPath)) {
                @unlink($this->socketPath);
            }
        } else {
            @fclose($this->socket);
        }
    }
}