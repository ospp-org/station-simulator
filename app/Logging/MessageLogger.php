<?php

declare(strict_types=1);

namespace App\Logging;

use Ospp\Protocol\Envelope\MessageEnvelope;

final class MessageLogger
{
    private const MAX_ENTRIES = 10000;

    /** @var list<array{direction: string, stationId: string, envelope: MessageEnvelope, timestamp: float}> */
    private array $buffer = [];

    private int $totalCount = 0;

    public function logInbound(string $stationId, MessageEnvelope $envelope): void
    {
        $this->addEntry('inbound', $stationId, $envelope);
    }

    public function logOutbound(string $stationId, MessageEnvelope $envelope): void
    {
        $this->addEntry('outbound', $stationId, $envelope);
    }

    /**
     * @return list<array{direction: string, stationId: string, envelope: MessageEnvelope, timestamp: float}>
     */
    public function getAll(int $limit = 100): array
    {
        $entries = array_slice($this->buffer, -$limit);

        return array_reverse($entries);
    }

    /**
     * @return list<array{direction: string, stationId: string, envelope: MessageEnvelope, timestamp: float}>
     */
    public function getByStation(string $stationId, int $limit = 100): array
    {
        $filtered = array_filter(
            $this->buffer,
            fn (array $entry): bool => $entry['stationId'] === $stationId,
        );

        return array_reverse(array_slice(array_values($filtered), -$limit));
    }

    /**
     * @return list<array{direction: string, stationId: string, envelope: MessageEnvelope, timestamp: float}>
     */
    public function getByAction(string $action, int $limit = 100): array
    {
        $filtered = array_filter(
            $this->buffer,
            fn (array $entry): bool => $entry['envelope']->action === $action,
        );

        return array_reverse(array_slice(array_values($filtered), -$limit));
    }

    /**
     * @return list<array{direction: string, stationId: string, envelope: MessageEnvelope, timestamp: float}>
     */
    public function getByDirection(string $direction, int $limit = 100): array
    {
        $filtered = array_filter(
            $this->buffer,
            fn (array $entry): bool => $entry['direction'] === $direction,
        );

        return array_reverse(array_slice(array_values($filtered), -$limit));
    }

    public function findByMessageId(string $messageId): ?array
    {
        foreach (array_reverse($this->buffer) as $entry) {
            if ((string) $entry['envelope']->messageId === $messageId) {
                return $entry;
            }
        }

        return null;
    }

    public function getTotalCount(): int
    {
        return $this->totalCount;
    }

    public function getBufferSize(): int
    {
        return count($this->buffer);
    }

    public function clear(): void
    {
        $this->buffer = [];
        $this->totalCount = 0;
    }

    private function addEntry(string $direction, string $stationId, MessageEnvelope $envelope): void
    {
        $this->buffer[] = [
            'direction' => $direction,
            'stationId' => $stationId,
            'envelope' => $envelope,
            'timestamp' => microtime(true),
        ];

        $this->totalCount++;

        // Trim to circular buffer capacity
        if (count($this->buffer) > self::MAX_ENTRIES) {
            $this->buffer = array_slice($this->buffer, -self::MAX_ENTRIES);
        }
    }
}
