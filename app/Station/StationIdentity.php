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

    public static function fromConfig(StationConfig $config, int $index, ?string $stationId = null): self
    {
        $paddedIndex = str_pad((string) $index, 3, '0', STR_PAD_LEFT);

        if ($stationId === null) {
            // Generate OSPP-compliant station ID: stn_ + 8+ hex chars
            $hexSuffix = str_pad(dechex($index), 8, '0', STR_PAD_LEFT);
            $stationId = "stn_{$hexSuffix}";
        }

        return new self(
            stationId: $stationId,
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
