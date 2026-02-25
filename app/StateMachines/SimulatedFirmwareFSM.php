<?php

declare(strict_types=1);

namespace App\StateMachines;

use App\Logging\ColoredConsoleOutput;
use App\Mqtt\MessageSender;
use App\Station\SimulatedStation;
use App\Timers\TimerManager;
use Ospp\Protocol\Actions\OsppAction;
use Ospp\Protocol\Enums\FirmwareUpdateStatus;
use Ospp\Protocol\StateMachines\FirmwareTransitions;

final class SimulatedFirmwareFSM
{
    private readonly FirmwareTransitions $transitions;

    /** @var array<string, FirmwareUpdateStatus> stationId → current status */
    private array $states = [];

    public function __construct(
        private readonly MessageSender $sender,
        private readonly TimerManager $timers,
        private readonly ColoredConsoleOutput $output,
    ) {
        $this->transitions = new FirmwareTransitions();
    }

    public function getStatus(string $stationId): FirmwareUpdateStatus
    {
        return $this->states[$stationId] ?? FirmwareUpdateStatus::IDLE;
    }

    public function startUpdate(
        SimulatedStation $station,
        string $firmwareUrl,
        string $targetVersion,
        float $downloadDurationMs,
        float $installDurationMs,
        float $failureRate,
        ?string $failureAtStage,
        float $progressIntervalMs,
    ): void {
        $stationId = $station->getStationId();
        $this->states[$stationId] = FirmwareUpdateStatus::IDLE;

        // IDLE → DOWNLOADING
        $this->transitionAndNotify($station, FirmwareUpdateStatus::DOWNLOADING);

        // Simulate download progress
        $this->simulateProgress($station, 'downloading', $downloadDurationMs, $progressIntervalMs, function () use (
            $station, $targetVersion, $installDurationMs, $failureRate, $failureAtStage, $progressIntervalMs,
        ): void {
            // Check failure at downloading stage
            if ($failureAtStage === 'downloading' || ($failureAtStage === null && $this->shouldFail($failureRate))) {
                $this->transitionAndNotify($station, FirmwareUpdateStatus::FAILED);

                return;
            }

            // DOWNLOADING → DOWNLOADED → VERIFYING → VERIFIED
            $this->transitionAndNotify($station, FirmwareUpdateStatus::DOWNLOADED);

            $this->timers->addTimer("firmware-verify:{$station->getStationId()}", 0.5, function () use (
                $station, $targetVersion, $installDurationMs, $failureRate, $failureAtStage, $progressIntervalMs,
            ): void {
                $this->transitionAndNotify($station, FirmwareUpdateStatus::VERIFYING);

                $this->timers->addTimer("firmware-verified:{$station->getStationId()}", 0.5, function () use (
                    $station, $targetVersion, $installDurationMs, $failureRate, $failureAtStage, $progressIntervalMs,
                ): void {
                    $this->transitionAndNotify($station, FirmwareUpdateStatus::VERIFIED);
                    $this->startInstallation($station, $targetVersion, $installDurationMs, $failureRate, $failureAtStage, $progressIntervalMs);
                });
            });
        });
    }

    private function startInstallation(
        SimulatedStation $station,
        string $targetVersion,
        float $installDurationMs,
        float $failureRate,
        ?string $failureAtStage,
        float $progressIntervalMs,
    ): void {
        $this->transitionAndNotify($station, FirmwareUpdateStatus::INSTALLING);

        $this->simulateProgress($station, 'installing', $installDurationMs, $progressIntervalMs, function () use (
            $station, $targetVersion, $failureRate, $failureAtStage,
        ): void {
            if ($failureAtStage === 'installing' || ($failureAtStage === null && $this->shouldFail($failureRate))) {
                $this->transitionAndNotify($station, FirmwareUpdateStatus::FAILED);

                return;
            }

            // INSTALLING → INSTALLED → REBOOTING → ACTIVATED
            $this->transitionAndNotify($station, FirmwareUpdateStatus::INSTALLED);

            $this->timers->addTimer("firmware-reboot:{$station->getStationId()}", 2.0, function () use ($station, $targetVersion): void {
                $this->transitionAndNotify($station, FirmwareUpdateStatus::REBOOTING);

                $this->timers->addTimer("firmware-activated:{$station->getStationId()}", 3.0, function () use ($station, $targetVersion): void {
                    $this->transitionAndNotify($station, FirmwareUpdateStatus::ACTIVATED);

                    // Update station firmware version
                    $station->emit('firmware.completed', [
                        'targetVersion' => $targetVersion,
                    ]);
                });
            });
        });
    }

    /** Statuses that should be reported to the CSMS via FirmwareStatusNotification */
    private const REPORTABLE_STATUSES = [
        'downloading', 'downloaded', 'installing', 'installed', 'failed',
    ];

    private function transitionAndNotify(SimulatedStation $station, FirmwareUpdateStatus $newStatus): void
    {
        $stationId = $station->getStationId();
        $current = $this->getStatus($stationId);

        if (! $this->transitions->canTransition($current, $newStatus)) {
            $this->output->error("Invalid firmware transition: {$current->value} → {$newStatus->value} for {$stationId}");

            return;
        }

        $this->states[$stationId] = $newStatus;
        $this->output->firmware("Station {$stationId}: firmware {$current->value} → {$newStatus->value}");

        // Only send notification for CSMS-reportable statuses
        if (in_array($newStatus->value, self::REPORTABLE_STATUSES, true)) {
            $this->sender->sendEvent($station, OsppAction::FIRMWARE_STATUS_NOTIFICATION, [
                'status' => ucfirst($newStatus->value),
                'firmwareVersion' => $station->identity->firmwareVersion,
            ]);
        }

        $station->emit('firmware.progress', [
            'status' => $newStatus->value,
        ]);
    }

    private function simulateProgress(
        SimulatedStation $station,
        string $stage,
        float $totalMs,
        float $intervalMs,
        callable $onComplete,
    ): void {
        $stationId = $station->getStationId();
        $steps = max(1, (int) ($totalMs / $intervalMs));
        $stepDuration = $totalMs / $steps / 1000;
        $currentStep = 0;

        $this->timers->addPeriodicTimer("firmware-progress:{$stationId}:{$stage}", $stepDuration, function () use (
            $station, $stage, $steps, &$currentStep, $onComplete,
        ): void {
            $currentStep++;
            $percent = (int) (($currentStep / $steps) * 100);

            $station->emit('firmware.progress', [
                'stage' => $stage,
                'percent' => min($percent, 100),
            ]);

            if ($currentStep >= $steps) {
                $this->timers->cancelTimer("firmware-progress:{$station->getStationId()}:{$stage}");
                $onComplete();
            }
        });
    }

    private function shouldFail(float $failureRate): bool
    {
        return (mt_rand(0, 10000) / 10000) < $failureRate;
    }
}
