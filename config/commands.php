<?php

declare(strict_types=1);

return [
    'default' => NunoMaduro\LaravelConsoleSummary\SummaryCommand::class,

    'paths' => [app_path('Commands')],

    'add' => [],

    'hidden' => [
        NunoMaduro\LaravelConsoleSummary\SummaryCommand::class,
    ],

    'remove' => [],
];
