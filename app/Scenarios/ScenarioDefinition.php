<?php

declare(strict_types=1);

namespace App\Scenarios;

final readonly class ScenarioDefinition
{
    /**
     * @param list<array<string, mixed>> $steps
     * @param list<string> $tags
     */
    public function __construct(
        public string $name,
        public string $description,
        public int $stationCount,
        public array $steps,
        public array $tags = [],
        public int $timeoutSeconds = 60,
    ) {}

    public function getStepCount(): int
    {
        return count($this->steps);
    }

    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }
}
