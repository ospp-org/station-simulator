<?php

declare(strict_types=1);

namespace App\Timers;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

final class TimerManager
{
    /** @var array<string, TimerInterface> */
    private array $timers = [];

    public function __construct(
        private readonly LoopInterface $loop,
    ) {}

    public function addPeriodicTimer(string $name, float $intervalSeconds, callable $callback): void
    {
        $this->cancelTimer($name);

        $this->timers[$name] = $this->loop->addPeriodicTimer($intervalSeconds, $callback);
    }

    public function addTimer(string $name, float $delaySeconds, callable $callback): void
    {
        $this->cancelTimer($name);

        $this->timers[$name] = $this->loop->addTimer($delaySeconds, function () use ($name, $callback): void {
            unset($this->timers[$name]);
            $callback();
        });
    }

    public function cancelTimer(string $name): void
    {
        if (isset($this->timers[$name])) {
            $this->loop->cancelTimer($this->timers[$name]);
            unset($this->timers[$name]);
        }
    }

    public function cancelAll(?string $prefix = null): void
    {
        foreach ($this->timers as $name => $timer) {
            if ($prefix === null || str_starts_with($name, $prefix)) {
                $this->loop->cancelTimer($timer);
                unset($this->timers[$name]);
            }
        }
    }

    public function hasTimer(string $name): bool
    {
        return isset($this->timers[$name]);
    }

    /** @return list<string> */
    public function getActiveTimers(): array
    {
        return array_keys($this->timers);
    }

    public function getCount(): int
    {
        return count($this->timers);
    }
}
