<?php

namespace Tests\Unit;

use App\Services\GeometryService;
use App\Services\SurveyRouteEntryPointService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SurveyRouteEntryPointServiceTest extends TestCase
{
    public function test_it_returns_the_last_real_intersection_without_reordering_user_points(): void
    {
        $userPath = [[44.0002, 18.0], [44.0001, 18.0], [43.9999, 18.0]];
        $result = $this->service()->find($userPath, [
            ['id' => 123, 'path' => [[44.0, 17.9998], [44.0, 18.0002]]],
        ]);

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(44.0, $result['entry_point'][0], 0.000000001);
        $this->assertEqualsWithDelta(18.0, $result['entry_point'][1], 0.000000001);
        $this->assertSame(2, $result['user_segment_end_index']);
        $this->assertSame(123, $result['main_route_id']);
        $this->assertSame(0, $result['main_segment_index']);
        $this->assertSame('intersection', $result['match_type']);
        $this->assertSame([[44.0002, 18.0], [44.0001, 18.0], [43.9999, 18.0]], $userPath);
    }

    public function test_it_accepts_a_shared_endpoint_within_documented_precision(): void
    {
        $result = $this->service()->find(
            [[44.0001, 18.0], [44.0000003, 18.0]],
            [['id' => 7, 'path' => [[44.0, 18.0], [44.0, 18.0002]]]],
        );

        $this->assertNotNull($result);
        $this->assertSame('shared_endpoint', $result['match_type']);
        $this->assertSame([44.0000003, 18.0], $result['entry_point']);
    }

    public function test_it_rejects_a_close_but_unconnected_route(): void
    {
        $result = $this->service()->find(
            [[44.0002, 18.0], [44.00001, 18.0]],
            [['id' => 9, 'path' => [[44.0, 17.9998], [44.0, 18.0002]]]],
        );

        $this->assertNull($result);
    }

    public function test_tolerance_cannot_be_expanded_to_snap_nearby_routes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->find([], [], 0.051);
    }

    private function service(): SurveyRouteEntryPointService
    {
        return new SurveyRouteEntryPointService(new GeometryService);
    }
}
