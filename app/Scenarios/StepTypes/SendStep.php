<?php

declare(strict_types=1);

namespace App\Scenarios\StepTypes;

use App\Scenarios\ScenarioContext;
use Ospp\Protocol\Actions\OsppAction;

final class SendStep implements StepInterface
{
    private string $lastMessage = '';

    /** @param array<string, mixed> $config */
    public function execute(array $config, ScenarioContext $context): bool
    {
        $action = $config['action'] ?? '';
        $payload = $config['payload'] ?? [];
        $stationIndex = (int) ($config['station'] ?? 1);
        $messageType = $config['message_type'] ?? 'request';

        if (! OsppAction::isValid($action)) {
            $this->lastMessage = "Invalid action: {$action}";

            return false;
        }

        $station = $context->getStation($stationIndex);
        if ($station === null) {
            $this->lastMessage = "Station #{$stationIndex} not found";

            return false;
        }

        // Inject stationId if not present
        if (! isset($payload['stationId'])) {
            $payload['stationId'] = $station->getStationId();
        }

        if ($messageType === 'event') {
            $envelope = $context->sender->sendEvent($station, $action, $payload);
        } else {
            $envelope = $context->sender->sendRequest($station, $action, $payload);
        }

        $context->lastSentMessage = $envelope;
        $this->lastMessage = "Sent {$action} ({$envelope->messageType->value})";

        return true;
    }

    public function getLastMessage(): string
    {
        return $this->lastMessage;
    }
}
