<?php

declare(strict_types=1);

namespace App\Station;

use DateTimeImmutable;

final class StationState
{
    public const LIFECYCLE_OFFLINE = 'OFFLINE';
    public const LIFECYCLE_BOOTING = 'BOOTING';
    public const LIFECYCLE_ONLINE = 'ONLINE';
    public const LIFECYCLE_RESETTING = 'RESETTING';

    public string $lifecycle = self::LIFECYCLE_OFFLINE;
    public ?string $sessionKey = null;
    public int $heartbeatInterval = 30;
    public ?DateTimeImmutable $uptimeStart = null;
    public ?DateTimeImmutable $lastHeartbeat = null;
    public string $bootReason = BootReason::POWER_ON;

    /** @var array<string, string> */
    public array $configValues = [];

    public function __construct(StationConfig $config)
    {
        foreach ($config->configuration as $key => $value) {
            $this->configValues[$key] = (string) $value;
        }

        $this->heartbeatInterval = (int) ($config->configuration['HeartbeatIntervalSeconds'] ?? 30);
    }

    public function isOnline(): bool
    {
        return $this->lifecycle === self::LIFECYCLE_ONLINE;
    }

    public function isOffline(): bool
    {
        return $this->lifecycle === self::LIFECYCLE_OFFLINE;
    }

    public function setLifecycle(string $lifecycle): void
    {
        $this->lifecycle = $lifecycle;
    }
}
