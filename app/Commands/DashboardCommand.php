<?php

declare(strict_types=1);

namespace App\Commands;

use App\Api\Controllers\BayController;
use App\Api\Controllers\MessageController;
use App\Api\Controllers\OfflineController;
use App\Api\Controllers\ScenarioController;
use App\Api\Controllers\SecurityController;
use App\Api\Controllers\StationController;
use App\Api\Controllers\StationControlController;
use App\Api\HttpServer;
use App\Logging\ColoredConsoleOutput;
use App\Logging\MessageLogger;
use App\Mqtt\MessageSender;
use App\Scenarios\Results\ResultStore;
use App\Station\SimulatedStation;
use App\Station\StationConfig;
use App\WebSocket\EventBroadcaster;
use App\WebSocket\WebSocketServer;
use LaravelZero\Framework\Commands\Command;
use React\EventLoop\Loop;

final class DashboardCommand extends Command
{
    protected $signature = 'dashboard
        {--ws-port=8085 : WebSocket server port}
        {--api-port=8086 : REST API server port}';

    protected $description = 'Start the WebSocket and REST API dashboard servers';

    public function handle(): int
    {
        $wsPort = (int) $this->option('ws-port');
        $apiPort = (int) $this->option('api-port');

        $loop = Loop::get();
        $output = new ColoredConsoleOutput($this->output);
        $output->info("OSPP Station Simulator Dashboard");

        // Note: In practice, this command is used alongside SimulateCommand
        // which owns the stations. For standalone usage, it starts empty.
        $output->info("Dashboard provides WS (port {$wsPort}) + REST API (port {$apiPort})");
        $output->info("Connect stations via the 'simulate' command to populate data.");

        // Create WebSocket server
        $ws = new WebSocketServer($loop, $output, $wsPort);
        $ws->start();

        // Create REST API with empty station registry
        $stations = [];
        $messageLogger = new MessageLogger();
        $resultStore = new ResultStore();

        $configPath = base_path('config/stations/default.yaml');
        $stationConfig = StationConfig::fromYamlFile($configPath);

        $api = new HttpServer($loop, $output, $apiPort);
        $api->registerController(new StationController($stations, $stationConfig));
        $api->registerController(new BayController($stations));
        $api->registerController(new MessageController($messageLogger));
        $api->registerController(new ScenarioController($resultStore));
        $api->start();

        $output->info("Dashboard ready. Press Ctrl+C to stop.");

        if (function_exists('pcntl_signal')) {
            $loop->addSignal(SIGTERM, fn () => $loop->stop());
            $loop->addSignal(SIGINT, fn () => $loop->stop());
        }

        $loop->run();

        return 0;
    }
}
