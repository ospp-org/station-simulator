<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\HttpServer;
use App\Logging\MessageLogger;
use Psr\Http\Message\ServerRequestInterface;

final class MessageController
{
    public function __construct(
        private readonly MessageLogger $logger,
    ) {}

    public function registerRoutes(HttpServer $server): void
    {
        $server->registerRoute('GET', '/api/messages', [$this, 'list']);
        $server->registerRoute('GET', '/api/messages/{messageId}', [$this, 'show']);
    }

    /** @return array<string, mixed> */
    public function list(ServerRequestInterface $request, array $params = []): array
    {
        $queryParams = $request->getQueryParams();
        $limit = min((int) ($queryParams['limit'] ?? 50), 200);
        $stationId = $queryParams['station_id'] ?? null;
        $action = $queryParams['action'] ?? null;
        $direction = $queryParams['direction'] ?? null;

        $entries = $this->logger->getAll($limit);

        // Filter
        if ($stationId !== null) {
            $entries = array_filter($entries, fn ($e) => $e['stationId'] === $stationId);
        }

        if ($action !== null) {
            $entries = array_filter($entries, fn ($e) => $e['action'] === $action);
        }

        if ($direction !== null) {
            $entries = array_filter($entries, fn ($e) => $e['direction'] === $direction);
        }

        $entries = array_values($entries);

        return ['messages' => $entries, 'count' => count($entries)];
    }

    /** @return array<string, mixed> */
    public function show(ServerRequestInterface $request, array $params = []): array
    {
        $messageId = $params['messageId'] ?? '';
        $entry = $this->logger->findByMessageId($messageId);

        if ($entry === null) {
            throw new \InvalidArgumentException("Message not found: {$messageId}");
        }

        return $entry;
    }
}
