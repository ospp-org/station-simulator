<?php

declare(strict_types=1);

namespace App\AutoResponder;

use React\EventLoop\LoopInterface;

final class DelaySimulator
{
    public function __construct(
        private readonly LoopInterface $loop,
    ) {}

    /**
     * Execute callback after a random delay within the config's range.
     *
     * @param array{int, int} $delayRangeMs [min, max] in milliseconds
     */
    public function afterDelay(array $delayRangeMs, callable $callback): void
    {
        $delayMs = random_int($delayRangeMs[0], $delayRangeMs[1]);

        $this->loop->addTimer($delayMs / 1000, $callback);
    }

    /** Execute callback after specified delay from config. */
    public function afterConfigDelay(AutoResponderConfig $config, callable $callback): void
    {
        $this->afterDelay($config->responseDelayMs, $callback);
    }
}
