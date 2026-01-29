<?php

namespace Tests\Unit;

use App\Services\StopIdGenerator;
use PHPUnit\Framework\TestCase;

class StopIdGeneratorTest extends TestCase
{
    public function test_rounds_coordinates_to_expected_precision(): void
    {
        $generator = new StopIdGenerator();

        $id = $generator->generate(1.234995, 36.987995);

        $this->assertSame('ST_N0123500_E3698800', $id);
    }

    public function test_handles_opposite_hemispheres(): void
    {
        $generator = new StopIdGenerator();

        $id = $generator->generate(-1.23456, -36.98765);

        $this->assertSame('ST_S0123456_W3698765', $id);
    }

    public function test_clamps_to_maximum_digits(): void
    {
        $generator = new StopIdGenerator();

        $id = $generator->generate(999.999999, 179.999999);

        $this->assertSame('ST_N9999999_E9999999', $id);
    }
}
