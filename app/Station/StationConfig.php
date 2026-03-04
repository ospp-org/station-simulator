<?php

declare(strict_types=1);

namespace App\Station;

use Symfony\Component\Yaml\Yaml;

final class StationConfig
{
    /** @var array<string, mixed> */
    public readonly array $identity;

    /** @var array<string, mixed> */
    public readonly array $capabilities;

    /** @var array<string, mixed> */
    public readonly array $network;

    public readonly string $timezone;

    /** @var array<string, mixed> */
    public readonly array $configuration;

    /** @var list<array<string, mixed>> */
    public readonly array $services;

    /** @var array<string, array<string, mixed>> */
    public array $behavior;

    /** @var array<string, mixed> */
    public readonly array $meterValues;

    /** @var array<string, mixed> */
    public readonly array $offline;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        $this->identity = $data['identity'];
        $this->capabilities = $data['capabilities'];
        $this->network = $data['network'];
        $this->timezone = $data['timezone'];
        $this->configuration = $data['configuration'];
        $this->services = $data['services'];
        $this->behavior = $data['behavior'];
        $this->meterValues = $data['meter_values'];
        $this->offline = $data['offline'];
    }

    public static function fromYamlFile(string $path): self
    {
        /** @var array<string, mixed> $data */
        $data = Yaml::parseFile($path);

        return new self($data);
    }

    public function getBayCount(): int
    {
        return (int) $this->capabilities['bay_count'];
    }

    /** @return array<string, mixed>|null */
    public function getBehaviorFor(string $action): ?array
    {
        return $this->behavior[$action] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function getMeterProfile(string $serviceId): ?array
    {
        return $this->meterValues['profiles'][$serviceId] ?? null;
    }

    public function getMeterIntervalSeconds(): int
    {
        return (int) $this->meterValues['interval_seconds'];
    }

    public function getMeterJitterPercent(): int
    {
        return (int) $this->meterValues['jitter_percent'];
    }

    /** @return array<string, mixed>|null */
    public function getServiceById(string $serviceId): ?array
    {
        foreach ($this->services as $service) {
            if ($service['service_id'] === $serviceId) {
                return $service;
            }
        }

        return null;
    }
}
