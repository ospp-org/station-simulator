<?php

declare(strict_types=1);

namespace App\Station;

final class BootReason
{
    public const POWER_ON = 'PowerOn';
    public const WATCHDOG = 'Watchdog';
    public const FIRMWARE_UPDATE = 'FirmwareUpdate';
    public const MANUAL_RESET = 'ManualReset';
    public const SCHEDULED_RESET = 'ScheduledReset';
    public const ERROR_RECOVERY = 'ErrorRecovery';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::POWER_ON,
            self::WATCHDOG,
            self::FIRMWARE_UPDATE,
            self::MANUAL_RESET,
            self::SCHEDULED_RESET,
            self::ERROR_RECOVERY,
        ];
    }
}
