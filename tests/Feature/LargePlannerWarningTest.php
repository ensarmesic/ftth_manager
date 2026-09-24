<?php

namespace Tests\Feature;

use App\Services\LargePlanner\PlanWarningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LargePlannerWarningTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_combines_and_classifies_all_planner_problems(): void
    {
        $result = app(PlanWarningService::class)->collect(
            ['unroutable_houses' => [
                ['id' => 10, 'label' => 'H-10', 'reason' => 'outside_max_drop', 'distance_m' => 240],
            ]],
            ['warnings' => [['code' => 'odo_drop_limit_exceeded', 'odo_key' => 'odo-1', 'message' => 'Drop je prekoračen.']]],
            ['warnings' => [['code' => 'odf_without_primary_route', 'odf_key' => 'odf-1', 'message' => 'Nema primarne rute.']]],
            ['warnings' => [['code' => 'odo_without_odf_route', 'odo_key' => 'odo-2', 'message' => 'Nema sekundarne rute.']]],
            ['warnings' => [['code' => 'cable_capacity_exceeded', 'segment_key' => 's-1', 'message' => 'Prekoračen kapacitet.']]],
        );

        $this->assertFalse($result['can_confirm']);
        $this->assertSame(5, $result['summary']['total']);
        $this->assertSame(5, $result['summary']['errors']);
        $this->assertSame(1, $result['summary']['houses_without_route']);
        $this->assertSame(1, $result['summary']['capacity_exceeded']);
        $this->assertSame('Kuća H-10 nema dostupnu trasu unutar maksimalnog dropa.', $result['items'][0]['message']);
    }

    public function test_duplicate_problem_from_multiple_stages_is_returned_once(): void
    {
        $warning = ['code' => 'odo_without_odf_route', 'odo_key' => 'odo-1', 'message' => 'Nema rute.'];

        $result = app(PlanWarningService::class)->collect(
            ['unroutable_houses' => []],
            ['warnings' => []],
            ['warnings' => []],
            ['warnings' => [$warning, $warning]],
            ['warnings' => []],
        );

        $this->assertSame(1, $result['summary']['total']);
        $this->assertCount(1, $result['items']);
    }

    public function test_clean_preview_can_be_confirmed(): void
    {
        $empty = ['warnings' => []];

        $result = app(PlanWarningService::class)->collect(['unroutable_houses' => []], $empty, $empty, $empty, $empty);

        $this->assertTrue($result['can_confirm']);
        $this->assertSame(0, $result['summary']['total']);
    }
}
