<?php

declare(strict_types=1);

return [
    'host' => env('MQTT_HOST', 'localhost'),
    'port' => (int) env('MQTT_PORT', 1883),
    'tls_enabled' => (bool) env('MQTT_TLS_ENABLED', false),
    'client_id_prefix' => env('MQTT_CLIENT_ID_PREFIX', 'sim'),
    'connection_mode' => env('MQTT_CONNECTION_MODE', 'shared'), // shared|per_station
    'qos' => (int) env('MQTT_QOS', 1),
    'keep_alive' => (int) env('MQTT_KEEP_ALIVE', 60),
    'username' => env('MQTT_USERNAME', ''),
    'password' => env('MQTT_PASSWORD', ''),
    'reconnect' => [
        'initial_delay_ms' => 1000,
        'max_delay_ms' => 30000,
        'multiplier' => 2.0,
        'jitter_percent' => 30,
    ],
];
