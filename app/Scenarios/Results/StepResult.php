<?php

declare(strict_types=1);

namespace App\Scenarios\Results;

final class StepResult
{
    public function __construct(
        public readonly string $name,
        public readonly string $status, // pass|fail|skip
        public readonly float $durationMs,
        public readonly string $details = '',
    ) {}

    public function passed(): bool
    {
        return $this->status === 'pass';
    }

    public function failed(): bool
    {
        return $this->status === 'fail';
    }
}
