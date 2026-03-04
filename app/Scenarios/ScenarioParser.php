<?php

declare(strict_types=1);

namespace App\Scenarios;

use Symfony\Component\Yaml\Yaml;

final class ScenarioParser
{
    public function parseFile(string $path): ScenarioDefinition
    {
        if (! file_exists($path)) {
            throw new \InvalidArgumentException("Scenario file not found: {$path}");
        }

        /** @var array<string, mixed> $data */
        $data = Yaml::parseFile($path);

        return $this->parse($data);
    }

    public function parseString(string $yaml): ScenarioDefinition
    {
        /** @var array<string, mixed> $data */
        $data = Yaml::parse($yaml);

        return $this->parse($data);
    }

    /** @param array<string, mixed> $data */
    private function parse(array $data): ScenarioDefinition
    {
        $this->validate($data);

        return new ScenarioDefinition(
            name: $data['name'],
            description: $data['description'] ?? '',
            stationCount: (int) ($data['station_count'] ?? 1),
            steps: $data['steps'] ?? [],
            tags: $data['tags'] ?? [],
            timeoutSeconds: (int) ($data['timeout_seconds'] ?? 60),
            config: $data['config'] ?? [],
        );
    }

    /** @param array<string, mixed> $data */
    private function validate(array $data): void
    {
        if (! isset($data['name'])) {
            throw new \InvalidArgumentException('Scenario must have a "name" field');
        }

        if (! isset($data['steps']) || ! is_array($data['steps'])) {
            throw new \InvalidArgumentException('Scenario must have a "steps" array');
        }

        foreach ($data['steps'] as $i => $step) {
            if (! isset($step['type'])) {
                throw new \InvalidArgumentException("Step #{$i} must have a \"type\" field");
            }
        }
    }
}
