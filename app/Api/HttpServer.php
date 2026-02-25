<?php

declare(strict_types=1);

namespace App\Api;

use App\Logging\ColoredConsoleOutput;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer as ReactHttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use Psr\Http\Message\ServerRequestInterface;

final class HttpServer
{
    /** @var array<string, array<string, callable>> method → path → handler */
    private array $routes = [];

    public function __construct(
        private readonly LoopInterface $loop,
        private readonly ColoredConsoleOutput $output,
        private readonly int $port = 8086,
    ) {}

    public function registerRoute(string $method, string $path, callable $handler): void
    {
        $this->routes[strtoupper($method)][$path] = $handler;
    }

    public function registerController(object $controller): void
    {
        if (method_exists($controller, 'registerRoutes')) {
            $controller->registerRoutes($this);
        }
    }

    public function start(): void
    {
        $server = new ReactHttpServer($this->loop, function (ServerRequestInterface $request): Response {
            return $this->handleRequest($request);
        });

        $socket = new SocketServer("0.0.0.0:{$this->port}", [], $this->loop);
        $server->listen($socket);

        $this->output->info("REST API server listening on port {$this->port}");
    }

    private function handleRequest(ServerRequestInterface $request): Response
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();

        // Try exact match first
        $handler = $this->routes[$method][$path] ?? null;

        // Try pattern match (simple {param} patterns)
        if ($handler === null) {
            $handler = $this->matchRoute($method, $path, $params);
        }

        if ($handler === null) {
            return $this->jsonResponse(404, ['error' => 'Not Found', 'path' => $path]);
        }

        try {
            $result = $handler($request, $params ?? []);

            if ($result instanceof Response) {
                return $result;
            }

            return $this->jsonResponse(200, $result);
        } catch (\InvalidArgumentException $e) {
            return $this->jsonResponse(400, ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->output->error("API error: {$e->getMessage()}");

            return $this->jsonResponse(500, ['error' => 'Internal Server Error']);
        }
    }

    /** @param array<string, string>|null $params */
    private function matchRoute(string $method, string $path, ?array &$params = null): ?callable
    {
        foreach ($this->routes[$method] ?? [] as $pattern => $handler) {
            if (! str_contains($pattern, '{')) {
                continue;
            }

            $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern);
            $regex = '#^' . $regex . '$#';

            if (preg_match($regex, $path, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                return $handler;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    public function jsonResponse(int $status, array $data): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );
    }
}
