<?php

namespace Tests\Unit;

use App\Support\FiberColorCode;
use PHPUnit\Framework\TestCase;

class FiberColorCodeTest extends TestCase
{
    public function test_it_maps_fibers_to_tia_598_tube_and_fiber_colors(): void
    {
        $this->assertSame(['Blue', 'Blue'], [FiberColorCode::describe(1)['tube']['english'], FiberColorCode::describe(1)['fiber']['english']]);
        $this->assertSame('Aqua', FiberColorCode::describe(12)['fiber']['english']);
        $this->assertSame(1, FiberColorCode::describe(13)['tube_number']);
        $this->assertTrue(FiberColorCode::describe(13)['traced']);
        $this->assertSame('Blue', FiberColorCode::describe(13)['fiber']['english']);
        $this->assertSame(2, FiberColorCode::describe(25)['tube_number']);
        $this->assertSame('Orange', FiberColorCode::describe(25)['tube']['english']);
        $this->assertSame(6, FiberColorCode::describe(144)['tube_number']);
        $this->assertSame('Aqua', FiberColorCode::describe(144)['fiber']['english']);
    }

    public function test_it_supports_din_vde_profile_and_twelve_fiber_tubes(): void
    {
        $first = FiberColorCode::describe(1, 12, 'din_vde');
        $thirteenth = FiberColorCode::describe(13, 12, 'din_vde');

        $this->assertSame('Red', $first['fiber']['english']);
        $this->assertSame(1, $first['tube_number']);
        $this->assertSame(2, $thirteenth['tube_number']);
        $this->assertFalse($thirteenth['traced']);
        $this->assertSame('Green', $thirteenth['tube']['english']);
    }
}
