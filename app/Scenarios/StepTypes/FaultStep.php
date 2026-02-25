<?php

declare(strict_types=1);

namespace App\Scenarios\StepTypes;

use App\Scenarios\ScenarioContext;
use OneStopPay\OsppProtocol\Enums\BayStatus;

final class FaultStep implements StepInterface
{
    private string $lastMessage = '';

    /** @param array<string, mixed> $config */
    public function execute(array $config, ScenarioContext $context): bool
    {
        $stationIndex = (int) ($config['station'] ?? 1);
        $errorCode = (int) ($config['error_code'] ?? 5000);
        $errorText = $config['error_text'] ?? 'Simulated fault';

        $station = $context->getStation($stationIndex);
        if ($station === null) {
            $this->lastMessage = "Station #{$stationIndex} not found";

            return false;
        }

        // Support both bay_id (full ID) and bay_number (integer)
        $bay = null;
        if (isset($config['bay_id'])) {
            $bay = $station->getBay($config['bay_id']);
        } elseif (isset($config['bay_number'])) {
            $bayNumber = (int) $config['bay_number'];
            foreach ($station->getBays() as $b) {
                if ($b->bayNumber === $bayNumber) {
                    $bay = $b;
                    break;
                }
            }
        }

        if ($bay === null) {
            $bayRef = $config['bay_id'] ?? $config['bay_number'] ?? '?';
            $this->lastMessage = "Bay {$bayRef} not found";

            return false;
        }

        $bay->transitionTo(BayStatus::FAULTED);
        $bay->setFault($errorCode, $errorText);

        $this->lastMessage = "Faulted bay {$bay->bayId}: {$errorText} ({$errorCode})";

        return true;
    }

    public function getLastMessage(): string
    {
        return $this->lastMessage;
    }
}
