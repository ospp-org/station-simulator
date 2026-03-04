<?php

declare(strict_types=1);

namespace App\Services;

use App\Generators\BootPayloadGenerator;
use App\Logging\ColoredConsoleOutput;
use App\Mqtt\MessageSender;
use App\StateMachines\StationLifecycle;
use App\Station\BootReason;
use App\Station\SimulatedStation;
use App\Station\StationState;
use App\Timers\TimerManager;
use Ospp\Protocol\Actions\OsppAction;
use Ospp\Protocol\Enums\BayStatus;

final class BootService
{
    public function __construct(
        private readonly StationLifecycle $lifecycle,
        private readonly BootPayloadGenerator $bootGenerator,
        private readonly MessageSender $sender,
        private readonly TimerManager $timers,
        private readonly HeartbeatService $heartbeatService,
        private readonly StatusNotificationService $statusService,
        private readonly ColoredConsoleOutput $output,
    ) {}

    public function boot(SimulatedStation $station, string $bootReason = BootReason::POWER_ON): bool
    {
        $stationId = $station->getStationId();

        if (! $this->lifecycle->transition($station->state, StationState::LIFECYCLE_BOOTING)) {
            $this->output->error("Cannot boot station {$stationId} from state {$station->state->lifecycle}");

            return false;
        }

        $station->state->bootReason = $bootReason;
        $this->output->boot("Booting station {$stationId}...");

        $payload = $this->bootGenerator->generate($station);
        $this->sender->sendRequest($station, OsppAction::BOOT_NOTIFICATION, $payload);

        $this->timers->addTimer("boot-timeout:{$stationId}", 5.0, function () use ($station): void {
            $this->handleTimeout($station, 0);
        });

        $station->emit('station.stateChanged', [
            'lifecycle' => $station->state->lifecycle,
        ]);

        return true;
    }

    public function handleResponse(SimulatedStation $station, array $payload): void
    {
        $stationId = $station->getStationId();

        // Ignore stale boot responses when station is already ONLINE
        if ($station->state->lifecycle === StationState::LIFECYCLE_ONLINE) {
            return;
        }

        $this->timers->cancelTimer("boot-timeout:{$stationId}");
        $this->timers->cancelTimer("boot-retry:{$stationId}");

        // Handle error responses (e.g., PROTOCOL_VERSION_MISMATCH, INVALID_MESSAGE_FORMAT)
        if (isset($payload['error'])) {
            $error = $payload['error'];
            $errorCode = $error['errorCode'] ?? 'unknown';
            $errorText = $error['errorText'] ?? 'Unknown error';
            $errorDesc = $error['errorDescription'] ?? '';
            $this->output->error("Station {$stationId} boot ERROR {$errorCode}: {$errorText} — {$errorDesc}");

            $this->timers->addTimer("boot-retry:{$stationId}", 30.0, function () use ($station): void {
                $payload = $this->bootGenerator->generate($station);
                $this->sender->sendRequest($station, OsppAction::BOOT_NOTIFICATION, $payload);

                $this->timers->addTimer("boot-timeout:{$station->getStationId()}", 5.0, function () use ($station): void {
                    $this->handleTimeout($station, 0);
                });
            });

            $station->emit('station.stateChanged', [
                'lifecycle' => $station->state->lifecycle,
                'error' => $errorText,
            ]);

            return;
        }

        $status = $payload['status'] ?? '';

        if ($status === 'Accepted') {
            $station->state->sessionKey = $payload['sessionKey'] ?? null;
            $station->state->heartbeatInterval = (int) ($payload['heartbeatIntervalSec'] ?? 30);

            // If station is OFFLINE (e.g. after disconnect), transition through BOOTING first
            if ($station->state->lifecycle === StationState::LIFECYCLE_OFFLINE) {
                $this->lifecycle->transition($station->state, StationState::LIFECYCLE_BOOTING);
            }
            $this->lifecycle->transition($station->state, StationState::LIFECYCLE_ONLINE);
            $this->output->boot("Station {$stationId} ONLINE (heartbeat: {$station->state->heartbeatInterval}s)");

            $this->statusService->sendAllBays($station, BayStatus::AVAILABLE);
            $this->heartbeatService->start($station);

            $station->emit('station.stateChanged', [
                'lifecycle' => StationState::LIFECYCLE_ONLINE,
            ]);
        } elseif ($status === 'Rejected') {
            $retryInterval = (int) ($payload['retryInterval'] ?? 60);
            $this->output->boot("Station {$stationId} boot REJECTED. Retry in {$retryInterval}s");

            $this->timers->addTimer("boot-retry:{$stationId}", (float) $retryInterval, function () use ($station): void {
                $payload = $this->bootGenerator->generate($station);
                $this->sender->sendRequest($station, OsppAction::BOOT_NOTIFICATION, $payload);

                $this->timers->addTimer("boot-timeout:{$station->getStationId()}", 5.0, function () use ($station): void {
                    $this->handleTimeout($station, 0);
                });
            });
        }
    }

    public function handleTimeout(SimulatedStation $station, int $attempt): void
    {
        $stationId = $station->getStationId();
        $delay = min(1000 * (2 ** $attempt), 30000);
        $jitter = (int) ($delay * 0.3);
        $delay += random_int(-$jitter, $jitter);
        $delay = max(1000, $delay);

        $this->output->boot("Boot timeout for {$stationId}. Retry in {$delay}ms (attempt #{$attempt})");

        $this->timers->addTimer("boot-retry:{$stationId}", $delay / 1000, function () use ($station, $attempt): void {
            $payload = $this->bootGenerator->generate($station);
            $this->sender->sendRequest($station, OsppAction::BOOT_NOTIFICATION, $payload);

            $this->timers->addTimer("boot-timeout:{$station->getStationId()}", 5.0, function () use ($station, $attempt): void {
                $this->handleTimeout($station, $attempt + 1);
            });
        });
    }
}
