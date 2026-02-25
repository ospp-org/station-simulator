<?php

declare(strict_types=1);

namespace App\Station;

final readonly class StationIdentity
{
    public function __construct(
        public string $stationId,
        public string $model,
        public string $vendor,
        public string $serialNumber,
        public string $firmwareVersion,
    ) {}

    public static function fromConfig(StationConfig $config, int $index): self
    {
        $prefix = $config->identity['station_id_prefix'];
        $paddedIndex = str_pad((string) $index, 3, '0', STR_PAD_LEFT);

        return new self(
            stationId: "{$prefix}-{$paddedIndex}",
            model: $config->identity['station_model'],
            vendor: $config->identity['station_vendor'],
            serialNumber: $config->identity['serial_number_prefix'] . '-' . $paddedIndex,
            firmwareVersion: $config->identity['firmware_version'],
        );
    }

    public function withFirmwareVersion(string $version): self
    {
        return new self(
            stationId: $this->stationId,
            model: $this->model,
            vendor: $this->vendor,
            serialNumber: $this->serialNumber,
            firmwareVersion: $version,
        );
    }
}
