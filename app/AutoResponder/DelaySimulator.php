<?php

declare(strict_types=1);

namespace App\AutoResponder;

use App\Timers\TimerManager;

final class DelaySimulator
{
    public function __construct(
        private readonly TimerManager $timers,
    ) {}

    /**
     * Execute callback after a random delay within the given range.
     *
     * @param string $key Named timer key (deduped/cancellable via TimerManager)
     * @param array{int, int} $delayRangeMs [min, max] in milliseconds
     */
    public function afterDelay(string $key, array $delayRangeMs, callable $callback): void
    {
        $delayMs = random_int($delayRangeMs[0], $delayRangeMs[1]);

        $this->timers->addTimer($key, $delayMs / 1000, $callback);
    }

    /** Execute callback after specified delay from config. */
    public function afterConfigDelay(string $key, AutoResponderConfig $config, callable $callback): void
    {
        $this->afterDelay($key, $config->responseDelayMs, $callback);
    }
}
