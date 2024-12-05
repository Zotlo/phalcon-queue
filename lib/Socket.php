<?php declare(strict_types=1);

namespace Phalcon\Queue;

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
     * @param string $queue
     * @param string|null $socketPath
     * @throws RuntimeException
     */
    public function __construct(bool $isServer = false, string $queue = 'default', string $socketPath = null)
    {
        $this->isServer = $isServer;
        $this->socketPath = $socketPath ?? sys_get_temp_dir() . '/phalcon_queue_socket_' . $queue . '.sock';
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

        while (stream_select($read, $write, $except, 0, 0) > 0) { // timeout 0 yaparak tüm bekleyen mesajları al
            if (in_array($this->socket, $read)) {
                $client = @stream_socket_accept($this->socket);
                if ($client) {
                    stream_set_blocking($client, false);
                    $this->clients[] = $client;
                }
                unset($read[array_search($this->socket, $read)]);
            }

            foreach ($read as $client) {
                $message = @fread($client, $this->bufferSize);

                if ($message === false || $message === '') {
                    $this->removeClient($client);
                    continue;
                }

                $lines = array_filter(explode(PHP_EOL, $message));
                if (!empty($lines)) {
                    $lastLine = end($lines);
                    $callback(trim($lastLine), $client);
                    $messageCount++;
                }
            }

            $read = array_merge([$this->socket], $this->clients);
        }

        return $messageCount;
    }

    /**
     * Send socket message.
     *
     * @param string $message
     * @return bool
     */
    public function send(string $message): bool
    {
        if ($this->isServer) {
            $success = true;
            foreach ($this->clients as $client) {
                if (@fwrite($client, $message . PHP_EOL) === false) {
                    $success = false;
                }
            }
            return $success;
        }

        return @fwrite($this->socket, $message . PHP_EOL) !== false;
    }

    /**
     * Receive socket server message.
     *
     * @return string|null
     * @throws RuntimeException
     */
    public function receive(): ?string
    {
        if ($this->isServer) {
            throw new RuntimeException("This method only use client process");
        }

        $message = @fread($this->socket, $this->bufferSize);
        return $message === false ? null : trim($message);
    }

    /**
     * Send message all clients.
     *
     * @param string $message
     * @return bool
     * @throws RuntimeException
     */
    public function broadcast(string $message): bool
    {
        if (!$this->isServer) {
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
        }

//        if ($this->socket) {
//            @fclose($this->socket);
//        }

        if ($this->isServer && file_exists($this->socketPath)) {
            @unlink($this->socketPath);
        }
    }
}