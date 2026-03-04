<?php

declare(strict_types=1);

namespace App\Generators;

use App\Station\SimulatedStation;

final class BootPayloadGenerator
{
    /**
     * @return array<string, mixed>
     */
    public function generate(SimulatedStation $station): array
    {
        $identity = $station->identity;
        $config = $station->config;

        return [
            'stationId' => $identity->stationId,
            'firmwareVersion' => $identity->firmwareVersion,
            'stationModel' => $identity->model,
            'stationVendor' => $identity->vendor,
            'serialNumber' => $identity->serialNumber,
            'uptimeSeconds' => $station->state->uptimeStart !== null
                ? (new \DateTimeImmutable())->getTimestamp() - $station->state->uptimeStart->getTimestamp()
                : 0,
            'pendingOfflineTransactions' => 0,
            'bayCount' => $config->getBayCount(),
            'capabilities' => [
                'bleSupported' => $config->capabilities['ble_supported'] ?? false,
                'offlineModeSupported' => $config->capabilities['offline_mode_supported'] ?? false,
                'meterValuesSupported' => $config->capabilities['meter_values_supported'] ?? false,
                'deviceManagementSupported' => $config->capabilities['device_management_supported'] ?? false,
            ],
            'networkInfo' => [
                'connectionType' => ucfirst($config->network['connection_type'] ?? 'ethernet'),
                'signalStrength' => $config->network['signal_strength'] ?? null,
            ],
            'timezone' => $config->timezone,
            'bootReason' => $station->state->bootReason,
        ];
    }
}
