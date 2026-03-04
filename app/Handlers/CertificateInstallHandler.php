<?php

declare(strict_types=1);

namespace App\Handlers;

use App\AutoResponder\AutoResponderConfig;
use App\AutoResponder\DelaySimulator;
use App\AutoResponder\ResponseDecider;
use App\AutoResponder\ResponseDecision;
use App\Logging\ColoredConsoleOutput;
use App\Mqtt\MessageSender;
use App\Station\SimulatedStation;
use Ospp\Protocol\Actions\OsppAction;
use Ospp\Protocol\Enums\CertificateType;
use Ospp\Protocol\Envelope\MessageEnvelope;

final class CertificateInstallHandler
{
    public function __construct(
        private readonly MessageSender $sender,
        private readonly ResponseDecider $decider,
        private readonly DelaySimulator $delay,
        private readonly ColoredConsoleOutput $output,
    ) {}

    public function __invoke(SimulatedStation $station, MessageEnvelope $envelope): void
    {
        $stationId = $station->getStationId();
        $payload = $envelope->payload;
        $certificateType = $payload['certificateType'] ?? '';
        $certificate = $payload['certificate'] ?? '';

        $this->output->info("CertificateInstall for {$stationId}: type={$certificateType}");

        if (CertificateType::tryFrom($certificateType) === null) {
            $this->sender->sendResponse($station, OsppAction::CERTIFICATE_INSTALL, [
                'status' => 'Rejected',
                'errorCode' => 5001,
                'errorText' => 'Invalid certificate type',
            ], $envelope);

            return;
        }

        $behaviorConfig = $station->config->getBehaviorFor('certificate_install') ?? [];
        $config = AutoResponderConfig::fromArray('certificate_install', $behaviorConfig);
        $delayRange = $behaviorConfig['response_delay_ms'] ?? [50, 150];

        $this->delay->afterDelay("cert-install:{$stationId}", $delayRange, function () use ($station, $envelope, $config, $certificateType, $certificate): void {
            $decision = $this->decider->decide($config);

            if ($decision === ResponseDecision::ACCEPTED) {
                $station->state->certificates[$certificateType] = $certificate;
                $serial = 'SN-' . bin2hex(random_bytes(8));

                $this->sender->sendResponse($station, OsppAction::CERTIFICATE_INSTALL, [
                    'status' => 'Accepted',
                    'certificateSerialNumber' => $serial,
                ], $envelope);

                $this->output->info("Certificate installed: type={$certificateType} serial={$serial}");
            } else {
                $this->sender->sendResponse($station, OsppAction::CERTIFICATE_INSTALL, [
                    'status' => 'Rejected',
                    'errorCode' => 5001,
                    'errorText' => 'Installation rejected by auto-responder',
                ], $envelope);
            }
        });
    }
}
