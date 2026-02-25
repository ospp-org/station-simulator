<?php

declare(strict_types=1);

namespace App\Station;

final class BootReason
{
    public const POWER_ON = 'power_on';
    public const WATCHDOG = 'watchdog';
    public const FIRMWARE_UPDATE = 'firmware_update';
    public const MANUAL_RESET = 'manual_reset';
    public const SCHEDULED_RESET = 'scheduled_reset';
    public const ERROR_RECOVERY = 'error_recovery';

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
