<?php

declare(strict_types=1);

namespace App\Scenarios\StepTypes;

use App\Scenarios\ScenarioContext;

interface StepInterface
{
    /** @param array<string, mixed> $config */
    public function execute(array $config, ScenarioContext $context): bool;

    public function getLastMessage(): string;
}
