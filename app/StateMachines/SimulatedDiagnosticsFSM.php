<?php

declare(strict_types=1);

namespace App\StateMachines;

use App\Logging\ColoredConsoleOutput;
use App\Mqtt\MessageSender;
use App\Station\SimulatedStation;
use App\Timers\TimerManager;
use OneStopPay\OsppProtocol\Actions\OsppAction;
use OneStopPay\OsppProtocol\Enums\DiagnosticsStatus;
use OneStopPay\OsppProtocol\StateMachines\DiagnosticsTransitions;

final class SimulatedDiagnosticsFSM
{
    private readonly DiagnosticsTransitions $transitions;

    /** @var array<string, DiagnosticsStatus> stationId → current status */
    private array $states = [];

    public function __construct(
        private readonly MessageSender $sender,
        private readonly TimerManager $timers,
        private readonly ColoredConsoleOutput $output,
    ) {
        $this->transitions = new DiagnosticsTransitions();
    }

    public function getStatus(string $stationId): DiagnosticsStatus
    {
        return $this->states[$stationId] ?? DiagnosticsStatus::PENDING;
    }

    public function startDiagnostics(
        SimulatedStation $station,
        string $uploadUrl,
        float $collectionDurationMs,
        float $uploadDurationMs,
        float $failureRate,
    ): void {
        $stationId = $station->getStationId();
        $this->states[$stationId] = DiagnosticsStatus::PENDING;

        // PENDING → COLLECTING
        $this->transitionAndNotify($station, DiagnosticsStatus::COLLECTING);

        // Simulate collection
        $this->timers->addTimer("diag-collect:{$stationId}", $collectionDurationMs / 1000, function () use (
            $station, $uploadUrl, $uploadDurationMs, $failureRate,
        ): void {
            if ($this->shouldFail($failureRate)) {
                $this->transitionAndNotify($station, DiagnosticsStatus::FAILED);

                return;
            }

            // COLLECTING → UPLOADING
            $this->transitionAndNotify($station, DiagnosticsStatus::UPLOADING);

            // Simulate upload
            $this->timers->addTimer("diag-upload:{$station->getStationId()}", $uploadDurationMs / 1000, function () use (
                $station, $uploadUrl, $failureRate,
            ): void {
                if ($this->shouldFail($failureRate)) {
                    $this->transitionAndNotify($station, DiagnosticsStatus::FAILED);

                    return;
                }

                // UPLOADING → UPLOADED
                $this->transitionAndNotify($station, DiagnosticsStatus::UPLOADED);

                $this->sender->sendEvent($station, OsppAction::DIAGNOSTICS_NOTIFICATION, [
                    'stationId' => $station->getStationId(),
                    'status' => 'Uploaded',
                    'uploadUrl' => $uploadUrl,
                    'fileName' => "diag_{$station->getStationId()}_" . date('Ymd_His') . '.tar.gz',
                    'timestamp' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z'),
                ]);
            });
        });
    }

    private function transitionAndNotify(SimulatedStation $station, DiagnosticsStatus $newStatus): void
    {
        $stationId = $station->getStationId();
        $current = $this->getStatus($stationId);

        if (! $this->transitions->canTransition($current, $newStatus)) {
            $this->output->error("Invalid diagnostics transition: {$current->value} → {$newStatus->value} for {$stationId}");

            return;
        }

        $this->states[$stationId] = $newStatus;
        $this->output->diag("Station {$stationId}: diagnostics {$current->value} → {$newStatus->value}");

        $this->sender->sendEvent($station, OsppAction::DIAGNOSTICS_NOTIFICATION, [
            'stationId' => $stationId,
            'status' => ucfirst($newStatus->value),
            'timestamp' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z'),
        ]);
    }

    private function shouldFail(float $failureRate): bool
    {
        return (mt_rand(0, 10000) / 10000) < $failureRate;
    }
}
