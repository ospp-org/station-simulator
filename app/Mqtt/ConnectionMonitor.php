<?php

declare(strict_types=1);

namespace App\Mqtt;

use App\Logging\ColoredConsoleOutput;
use React\EventLoop\LoopInterface;

final class ConnectionMonitor
{
    /** @var array<string, bool> */
    private array $connectionStates = [];

    /** @var array<string, int> */
    private array $reconnectAttempts = [];

    public function __construct(
        private readonly LoopInterface $loop,
        private readonly MqttConnectionManager $mqtt,
        private readonly ColoredConsoleOutput $output,
        private readonly int $initialDelayMs = 1000,
        private readonly int $maxDelayMs = 30000,
        private readonly float $multiplier = 2.0,
        private readonly int $jitterPercent = 30,
    ) {}

    public function markConnected(string $stationId): void
    {
        $this->connectionStates[$stationId] = true;
        $this->reconnectAttempts[$stationId] = 0;
    }

    public function markDisconnected(string $stationId): void
    {
        $this->connectionStates[$stationId] = false;
        $this->output->mqtt("Station {$stationId} disconnected");
    }

    public function isConnected(string $stationId): bool
    {
        return $this->connectionStates[$stationId] ?? false;
    }

    public function scheduleReconnect(string $stationId, callable $onReconnect): void
    {
        $attempt = $this->reconnectAttempts[$stationId] ?? 0;
        $delay = $this->calculateBackoff($attempt);

        $this->output->mqtt("Scheduling reconnect for {$stationId} in {$delay}ms (attempt #{$attempt})");

        $this->loop->addTimer($delay / 1000, function () use ($stationId, $onReconnect): void {
            $this->reconnectAttempts[$stationId] = ($this->reconnectAttempts[$stationId] ?? 0) + 1;
            $onReconnect($stationId);
        });
    }

    private function calculateBackoff(int $attempt): int
    {
        $delay = (int) ($this->initialDelayMs * ($this->multiplier ** $attempt));
        $delay = min($delay, $this->maxDelayMs);

        // Apply jitter ±jitterPercent
        $jitterRange = (int) ($delay * $this->jitterPercent / 100);
        $jitter = random_int(-$jitterRange, $jitterRange);

        return max(100, $delay + $jitter);
    }
}
