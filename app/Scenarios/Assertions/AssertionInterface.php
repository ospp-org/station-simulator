<?php

declare(strict_types=1);

namespace App\Scenarios\Assertions;

use App\Scenarios\ScenarioContext;

interface AssertionInterface
{
    /** @param array<string, mixed> $params */
    public function evaluate(array $params, ScenarioContext $context): bool;

    public function getLastMessage(): string;
}
