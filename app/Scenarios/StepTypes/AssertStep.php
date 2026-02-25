<?php

declare(strict_types=1);

namespace App\Scenarios\StepTypes;

use App\Scenarios\Assertions\AssertionRunner;
use App\Scenarios\ScenarioContext;

final class AssertStep implements StepInterface
{
    private string $lastMessage = '';

    public function __construct(
        private readonly AssertionRunner $assertionRunner,
    ) {}

    /** @param array<string, mixed> $config */
    public function execute(array $config, ScenarioContext $context): bool
    {
        $assertType = $config['assertion'] ?? $config['assert'] ?? '';
        $params = $config['params'] ?? $config;

        $result = $this->assertionRunner->run($assertType, $params, $context);
        $this->lastMessage = $this->assertionRunner->getLastMessage();

        return $result;
    }

    public function getLastMessage(): string
    {
        return $this->lastMessage;
    }
}
