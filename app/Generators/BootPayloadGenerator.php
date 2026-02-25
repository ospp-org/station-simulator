<?php

declare(strict_types=1);

namespace App\Generators;

use App\Station\BootReason;
use App\Station\SimulatedStation;
use OneStopPay\OsppProtocol\Enums\BayStatus;

final class BootPayloadGenerator
{
    /**
     * @return array<string, mixed>
     */
    public function generate(SimulatedStation $station): array
    {
        $identity = $station->identity;
        $config = $station->config;

        $bays = [];
        foreach ($station->getBays() as $bay) {
            $bays[] = [
                'bayId' => $bay->bayId,
                'bayNumber' => $bay->bayNumber,
                'status' => BayStatus::AVAILABLE->toOspp(),
                'services' => array_map(fn (array $svc): array => [
                    'serviceId' => $svc['service_id'],
                    'serviceName' => $svc['service_name'],
                    'pricingType' => $svc['pricing_type'],
                    'priceCreditsFixed' => $svc['price_credits_fixed'],
                    'priceCreditsPerMinute' => $svc['price_credits_per_minute'],
                    'available' => $svc['available'],
                ], $bay->services),
            ];
        }

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
                'connectionType' => $config->network['connection_type'] ?? 'ethernet',
                'signalStrength' => $config->network['signal_strength'] ?? null,
            ],
            'timezone' => $config->timezone,
            'bootReason' => $station->state->bootReason,
            'bays' => $bays,
        ];
    }
}
