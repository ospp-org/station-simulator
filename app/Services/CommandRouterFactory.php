<?php

declare(strict_types=1);

namespace App\Services;

use App\AutoResponder\DelaySimulator;
use App\AutoResponder\ResponseDecider;
use App\Generators\MeterValueGenerator;
use App\Handlers\CancelReservationHandler;
use App\Handlers\CertificateInstallHandler;
use App\Handlers\ChangeConfigurationHandler;
use App\Handlers\DataTransferHandler;
use App\Handlers\GetConfigurationHandler;
use App\Handlers\GetDiagnosticsHandler;
use App\Handlers\IncomingCommandRouter;
use App\Handlers\ReserveBayHandler;
use App\Handlers\ResetHandler;
use App\Handlers\SetMaintenanceModeHandler;
use App\Handlers\StartServiceHandler;
use App\Handlers\StopServiceHandler;
use App\Handlers\TriggerCertificateRenewalHandler;
use App\Handlers\TriggerMessageHandler;
use App\Handlers\UpdateFirmwareHandler;
use App\Handlers\UpdateServiceCatalogHandler;
use App\Logging\ColoredConsoleOutput;
use App\Mqtt\MessageSender;
use App\StateMachines\SimulatedBayFSM;
use App\StateMachines\SimulatedDiagnosticsFSM;
use App\StateMachines\SimulatedFirmwareFSM;
use App\StateMachines\SimulatedSessionFSM;
use App\StateMachines\StationLifecycle;
use App\Station\SimulatedStation;
use App\Timers\TimerManager;
use Ospp\Protocol\Actions\OsppAction;
use Ospp\Protocol\Enums\MessageType;

final class CommandRouterFactory
{
    public static function create(
        MessageSender $sender,
        TimerManager $timers,
        StationLifecycle $lifecycle,
        BootService $bootService,
        HeartbeatService $heartbeatService,
        ColoredConsoleOutput $output,
    ): IncomingCommandRouter {
        $bayFSM = new SimulatedBayFSM();
        $sessionFSM = new SimulatedSessionFSM();
        $decider = new ResponseDecider();
        $delay = new DelaySimulator($timers);
        $meterGenerator = new MeterValueGenerator();
        $firmwareFSM = new SimulatedFirmwareFSM($sender, $timers, $output);
        $diagnosticsFSM = new SimulatedDiagnosticsFSM($sender, $timers, $output);

        $statusService = new StatusNotificationService($sender);
        $router = new IncomingCommandRouter($output);

        $router->registerHandler(
            OsppAction::START_SERVICE,
            new StartServiceHandler($sender, $bayFSM, $sessionFSM, $decider, $delay, $timers, $meterGenerator, $output),
        );

        $router->registerHandler(
            OsppAction::STOP_SERVICE,
            new StopServiceHandler($sender, $bayFSM, $sessionFSM, $decider, $delay, $timers, $meterGenerator, $statusService, $output),
        );

        $router->registerHandler(
            OsppAction::RESERVE_BAY,
            new ReserveBayHandler($sender, $bayFSM, $decider, $delay, $timers, $output),
        );

        $router->registerHandler(
            OsppAction::CANCEL_RESERVATION,
            new CancelReservationHandler($sender, $bayFSM, $delay, $timers, $output),
        );

        $router->registerHandler(
            OsppAction::GET_CONFIGURATION,
            new GetConfigurationHandler($sender, $decider, $delay, $output),
        );

        $router->registerHandler(
            OsppAction::CHANGE_CONFIGURATION,
            new ChangeConfigurationHandler($sender, $decider, $delay, $output),
        );

        $router->registerHandler(
            OsppAction::UPDATE_FIRMWARE,
            new UpdateFirmwareHandler($sender, $firmwareFSM, $decider, $delay, $output),
        );

        $router->registerHandler(
            OsppAction::GET_DIAGNOSTICS,
            new GetDiagnosticsHandler($sender, $diagnosticsFSM, $decider, $delay, $output),
        );

        $router->registerHandler(
            OsppAction::RESET,
            new ResetHandler(
                $sender, $lifecycle, $decider, $delay, $timers, $output,
                fn (SimulatedStation $station) => $bootService->boot($station),
            ),
        );

        $router->registerHandler(
            OsppAction::SET_MAINTENANCE_MODE,
            new SetMaintenanceModeHandler($sender, $decider, $delay, $output),
        );

        $router->registerHandler(
            OsppAction::UPDATE_SERVICE_CATALOG,
            new UpdateServiceCatalogHandler($sender, $decider, $delay, $output),
        );

        $signCertificateService = new SignCertificateService($sender, $output);

        $router->registerHandler(
            OsppAction::CERTIFICATE_INSTALL,
            new CertificateInstallHandler($sender, $decider, $delay, $output),
        );

        $router->registerHandler(
            OsppAction::TRIGGER_CERTIFICATE_RENEWAL,
            new TriggerCertificateRenewalHandler($sender, $decider, $delay, $signCertificateService, $output),
        );

        $router->registerHandler(
            OsppAction::TRIGGER_MESSAGE,
            new TriggerMessageHandler($sender, $decider, $delay, $statusService, $output),
        );

        $router->registerHandler(
            OsppAction::DATA_TRANSFER,
            new DataTransferHandler($sender, $decider, $delay, $output),
        );

        // Handle boot and heartbeat responses
        $router->registerHandler(OsppAction::BOOT_NOTIFICATION, function (SimulatedStation $station, $envelope) use ($bootService): void {
            if ($envelope->messageType === MessageType::RESPONSE) {
                $bootService->handleResponse($station, $envelope->payload);
            }
        });

        $router->registerHandler(OsppAction::HEARTBEAT, function (SimulatedStation $station, $envelope) use ($heartbeatService): void {
            if ($envelope->messageType === MessageType::RESPONSE) {
                $heartbeatService->handleResponse($station, $envelope->payload);
            }
        });

        return $router;
    }
}
