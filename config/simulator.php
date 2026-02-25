<?php

declare(strict_types=1);

return [
    'stations' => (int) env('SIMULATOR_STATIONS', 1),
    'auto_boot' => (bool) env('SIMULATOR_AUTO_BOOT', true),
    'ws_port' => (int) env('SIMULATOR_WS_PORT', 8085),
    'api_port' => (int) env('SIMULATOR_API_PORT', 8086),
    'log_level' => env('SIMULATOR_LOG_LEVEL', 'info'),
    'station_config' => env('SIMULATOR_STATION_CONFIG', 'default'),
    'mqtt_poll_interval_ms' => (int) env('SIMULATOR_MQTT_POLL_MS', 50),
];
