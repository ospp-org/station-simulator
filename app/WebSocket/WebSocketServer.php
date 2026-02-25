<?php

declare(strict_types=1);

namespace App\WebSocket;

use App\Logging\ColoredConsoleOutput;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\LoopInterface;
use React\Socket\SocketServer;

final class WebSocketServer implements MessageComponentInterface
{
    /** @var \SplObjectStorage<ConnectionInterface, mixed> */
    private \SplObjectStorage $clients;

    private ?IoServer $server = null;

    public function __construct(
        private readonly LoopInterface $loop,
        private readonly ColoredConsoleOutput $output,
        private readonly int $port = 8085,
    ) {
        $this->clients = new \SplObjectStorage();
    }

    public function start(): void
    {
        $wsServer = new WsServer($this);
        $httpServer = new HttpServer($wsServer);

        $socket = new SocketServer("0.0.0.0:{$this->port}", [], $this->loop);
        $this->server = new IoServer($httpServer, $socket, $this->loop);

        $this->output->info("WebSocket server listening on port {$this->port}");
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->clients->attach($conn);
        $this->output->debug("WS client connected ({$this->clients->count()} total)");
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        // WebSocket is push-only for now; ignore incoming messages
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $this->clients->detach($conn);
        $this->output->debug("WS client disconnected ({$this->clients->count()} remaining)");
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $this->output->error("WS error: {$e->getMessage()}");
        $conn->close();
    }

    public function broadcast(string $json): void
    {
        foreach ($this->clients as $client) {
            $client->send($json);
        }
    }

    public function getClientCount(): int
    {
        return $this->clients->count();
    }
}
