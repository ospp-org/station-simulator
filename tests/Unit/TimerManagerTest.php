<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Timers\TimerManager;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

final class TimerManagerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private LoopInterface&MockInterface $loop;
    private TimerManager $manager;

    protected function setUp(): void
    {
        $this->loop = Mockery::mock(LoopInterface::class);
        $this->manager = new TimerManager($this->loop);
    }

    public function test_add_periodic_timer_registers_on_loop(): void
    {
        $timer = Mockery::mock(TimerInterface::class);

        $this->loop->shouldReceive('addPeriodicTimer')
            ->once()
            ->with(5.0, Mockery::type('callable'))
            ->andReturn($timer);

        $this->manager->addPeriodicTimer('heartbeat', 5.0, function (): void {});

        $this->assertTrue($this->manager->hasTimer('heartbeat'));
        $this->assertSame(1, $this->manager->getCount());
    }

    public function test_add_timer_registers_on_loop(): void
    {
        $timer = Mockery::mock(TimerInterface::class);

        $this->loop->shouldReceive('addTimer')
            ->once()
            ->with(10.0, Mockery::type('callable'))
            ->andReturn($timer);

        $this->manager->addTimer('timeout', 10.0, function (): void {});

        $this->assertTrue($this->manager->hasTimer('timeout'));
        $this->assertSame(1, $this->manager->getCount());
    }

    public function test_cancel_timer_removes_and_cancels_on_loop(): void
    {
        $timer = Mockery::mock(TimerInterface::class);

        $this->loop->shouldReceive('addPeriodicTimer')
            ->once()
            ->andReturn($timer);

        $this->loop->shouldReceive('cancelTimer')
            ->once()
            ->with($timer);

        $this->manager->addPeriodicTimer('meter', 10.0, function (): void {});
        $this->assertTrue($this->manager->hasTimer('meter'));

        $this->manager->cancelTimer('meter');

        $this->assertFalse($this->manager->hasTimer('meter'));
        $this->assertSame(0, $this->manager->getCount());
    }

    public function test_cancel_timer_noop_for_nonexistent(): void
    {
        // Should not throw
        $this->manager->cancelTimer('nonexistent');
        $this->assertSame(0, $this->manager->getCount());
    }

    public function test_cancel_all_without_prefix(): void
    {
        $timer1 = Mockery::mock(TimerInterface::class);
        $timer2 = Mockery::mock(TimerInterface::class);

        $this->loop->shouldReceive('addPeriodicTimer')
            ->andReturn($timer1, $timer2);

        $this->loop->shouldReceive('cancelTimer')->twice();

        $this->manager->addPeriodicTimer('meter:bay_1', 10.0, function (): void {});
        $this->manager->addPeriodicTimer('heartbeat', 30.0, function (): void {});

        $this->assertSame(2, $this->manager->getCount());

        $this->manager->cancelAll();

        $this->assertSame(0, $this->manager->getCount());
    }

    public function test_cancel_all_with_prefix_only_cancels_matching(): void
    {
        $timerMeter1 = Mockery::mock(TimerInterface::class);
        $timerMeter2 = Mockery::mock(TimerInterface::class);
        $timerHb = Mockery::mock(TimerInterface::class);

        $this->loop->shouldReceive('addPeriodicTimer')
            ->andReturn($timerMeter1, $timerMeter2, $timerHb);

        $this->loop->shouldReceive('cancelTimer')
            ->with($timerMeter1)->once();
        $this->loop->shouldReceive('cancelTimer')
            ->with($timerMeter2)->once();

        $this->manager->addPeriodicTimer('meter:bay_1', 10.0, function (): void {});
        $this->manager->addPeriodicTimer('meter:bay_2', 10.0, function (): void {});
        $this->manager->addPeriodicTimer('heartbeat', 30.0, function (): void {});

        $this->assertSame(3, $this->manager->getCount());

        $this->manager->cancelAll('meter:');

        $this->assertSame(1, $this->manager->getCount());
        $this->assertFalse($this->manager->hasTimer('meter:bay_1'));
        $this->assertFalse($this->manager->hasTimer('meter:bay_2'));
        $this->assertTrue($this->manager->hasTimer('heartbeat'));
    }

    public function test_has_timer_returns_false_for_nonexistent(): void
    {
        $this->assertFalse($this->manager->hasTimer('nonexistent'));
    }

    public function test_get_count_returns_zero_initially(): void
    {
        $this->assertSame(0, $this->manager->getCount());
    }

    public function test_add_periodic_timer_cancels_existing_with_same_name(): void
    {
        $timer1 = Mockery::mock(TimerInterface::class);
        $timer2 = Mockery::mock(TimerInterface::class);

        $this->loop->shouldReceive('addPeriodicTimer')
            ->andReturn($timer1, $timer2);

        $this->loop->shouldReceive('cancelTimer')
            ->with($timer1)
            ->once();

        $this->manager->addPeriodicTimer('meter', 10.0, function (): void {});
        $this->manager->addPeriodicTimer('meter', 5.0, function (): void {});

        $this->assertSame(1, $this->manager->getCount());
    }

    public function test_get_active_timers(): void
    {
        $timer = Mockery::mock(TimerInterface::class);

        $this->loop->shouldReceive('addPeriodicTimer')
            ->andReturn($timer);

        $this->manager->addPeriodicTimer('timer_a', 10.0, function (): void {});

        $active = $this->manager->getActiveTimers();
        $this->assertSame(['timer_a'], $active);
    }
}
