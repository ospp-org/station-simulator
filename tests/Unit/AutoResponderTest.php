<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\AutoResponder\AutoResponderConfig;
use App\AutoResponder\ResponseDecider;
use App\AutoResponder\ResponseDecision;
use PHPUnit\Framework\TestCase;

final class AutoResponderTest extends TestCase
{
    private ResponseDecider $decider;

    protected function setUp(): void
    {
        $this->decider = new ResponseDecider();
    }

    public function test_100_percent_accept_rate_always_returns_accepted(): void
    {
        $config = new AutoResponderConfig(
            action: 'start_service',
            acceptRate: 1.0,
            notSupportedRate: 0.0,
            rebootRequiredRate: 0.0,
        );

        // Run multiple times to verify determinism at boundary
        for ($i = 0; $i < 50; $i++) {
            $this->assertSame(ResponseDecision::ACCEPTED, $this->decider->decide($config));
        }
    }

    public function test_0_percent_accept_rate_returns_rejected(): void
    {
        $config = new AutoResponderConfig(
            action: 'start_service',
            acceptRate: 0.0,
            notSupportedRate: 0.0,
            rebootRequiredRate: 0.0,
        );

        for ($i = 0; $i < 50; $i++) {
            $this->assertSame(ResponseDecision::REJECTED, $this->decider->decide($config));
        }
    }

    public function test_100_percent_reboot_required_rate_triggers_reboot_required(): void
    {
        $config = new AutoResponderConfig(
            action: 'change_configuration',
            acceptRate: 0.0,
            notSupportedRate: 0.0,
            rebootRequiredRate: 1.0,
        );

        for ($i = 0; $i < 50; $i++) {
            $this->assertSame(ResponseDecision::REBOOT_REQUIRED, $this->decider->decide($config));
        }
    }

    public function test_100_percent_not_supported_rate_triggers_not_supported(): void
    {
        $config = new AutoResponderConfig(
            action: 'change_configuration',
            acceptRate: 0.0,
            notSupportedRate: 1.0,
            rebootRequiredRate: 0.0,
        );

        for ($i = 0; $i < 50; $i++) {
            $this->assertSame(ResponseDecision::NOT_SUPPORTED, $this->decider->decide($config));
        }
    }

    public function test_should_fail_with_0_rate_returns_false(): void
    {
        $config = new AutoResponderConfig(action: 'update_firmware', failureRate: 0.0);

        for ($i = 0; $i < 50; $i++) {
            $this->assertFalse($this->decider->shouldFail($config));
        }
    }

    public function test_should_fail_with_1_rate_returns_true(): void
    {
        $config = new AutoResponderConfig(action: 'update_firmware', failureRate: 1.0);

        for ($i = 0; $i < 50; $i++) {
            $this->assertTrue($this->decider->shouldFail($config));
        }
    }

    public function test_should_report_already_ended_with_0_rate_returns_false(): void
    {
        $config = new AutoResponderConfig(action: 'stop_service', alreadyEndedRate: 0.0);

        for ($i = 0; $i < 50; $i++) {
            $this->assertFalse($this->decider->shouldReportAlreadyEnded($config));
        }
    }

    public function test_should_report_unknown_key_with_1_rate_returns_true(): void
    {
        $config = new AutoResponderConfig(action: 'get_configuration', unknownKeyRate: 1.0);

        for ($i = 0; $i < 50; $i++) {
            $this->assertTrue($this->decider->shouldReportUnknownKey($config));
        }
    }
}
