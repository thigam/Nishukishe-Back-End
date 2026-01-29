<?php

namespace Tests\Unit;

use App\Services\FareCalculator;
use Carbon\Carbon;
use Tests\TestCase;

class FareCalculatorTest extends TestCase
{
    public function test_short_distance_off_peak(): void
    {
        $service = new FareCalculator();

        $result = $service->calculate(
            4.2,
            25.0,
            Carbon::create(2024, 1, 1, 11, 0, 0, 'Africa/Nairobi'),
            false,
            150.0,
            120.0
        );

        $this->assertSame(60.0, $result['fare']);
        $this->assertSame(60.0, $result['off_peak_fare']);
        $this->assertSame(90.0, $result['peak_fare']);
        $this->assertFalse($result['requires_manual_fare']);
    }

    public function test_mid_range_distance_returns_second_tier(): void
    {
        $service = new FareCalculator();

        $result = $service->calculate(
            12.4,
            25.0,
            Carbon::create(2024, 1, 1, 13, 0, 0, 'Africa/Nairobi'),
            false,
            160.0,
            140.0
        );

        $this->assertSame(100.0, $result['fare']);
        $this->assertSame(100.0, $result['off_peak_fare']);
        $this->assertSame(130.0, $result['peak_fare']);
        $this->assertFalse($result['requires_manual_fare']);
    }

    public function test_evening_commute_uses_peak_fare(): void
    {
        $service = new FareCalculator();

        $result = $service->calculate(
            30.0,
            40.0,
            Carbon::create(2024, 1, 1, 17, 30, 0, 'Africa/Nairobi'),
            false,
            210.0,
            200.0,
            true,
            false
        );

        $this->assertSame(210.0, $result['fare']);
        $this->assertTrue($result['is_peak_fare']);
        $this->assertFalse($result['requires_manual_fare']);
    }

    public function test_long_distance_sets_manual_flag(): void
    {
        $service = new FareCalculator();

        $result = $service->calculate(
            52.0,
            50.0,
            Carbon::create(2024, 1, 1, 12, 0, 0, 'Africa/Nairobi'),
            false,
            270.0,
            250.0
        );

        $this->assertSame(250.0, $result['fare']);
        $this->assertSame(270.0, $result['peak_fare']);
        $this->assertTrue($result['requires_manual_fare']);
    }

    public function test_event_day_forces_peak_fare(): void
    {
        $service = new FareCalculator();

        $result = $service->calculate(
            6.0,
            20.0,
            Carbon::create(2024, 1, 1, 11, 0, 0, 'Africa/Nairobi'),
            true,
            180.0,
            150.0
        );

        $this->assertSame($result['peak_fare'], $result['fare']);
        $this->assertSame(150.0, $result['peak_fare']);
        $this->assertSame(110.0, $result['off_peak_fare']);
        $this->assertTrue($result['is_peak_fare']);
    }
}
