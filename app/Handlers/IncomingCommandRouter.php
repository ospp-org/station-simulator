<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Logging\ColoredConsoleOutput;
use App\Station\SimulatedStation;
use Ospp\Protocol\Actions\OsppAction;
use Ospp\Protocol\Envelope\MessageEnvelope;

final class IncomingCommandRouter
{
    /** @var array<string, callable> action => handler callable */
    private array $handlers = [];

    public function __construct(
        private readonly ColoredConsoleOutput $output,
    ) {}

    public function registerHandler(string $action, callable $handler): void
    {
        $this->handlers[$action] = $handler;
    }

    public function route(SimulatedStation $station, MessageEnvelope $envelope): void
    {
        $action = $envelope->action;

        if (! OsppAction::isValid($action)) {
            $this->output->warning("Unknown OSPP action: {$action} for station {$station->getStationId()}");

            return;
        }

        $handler = $this->handlers[$action] ?? null;
        if ($handler === null) {
            $this->output->debug("No handler registered for action: {$action}");

            return;
        }

        try {
            $handler($station, $envelope);
        } catch (\Throwable $e) {
            $this->output->error("Handler error for {$action} on {$station->getStationId()}: {$e->getMessage()}");
        }
    }
}
