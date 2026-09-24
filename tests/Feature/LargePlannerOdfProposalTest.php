<?php

namespace Tests\Feature;

use App\Models\GisSegment;
use App\Models\Odf;
use App\Models\Project;
use App\Services\GeometryService;
use App\Services\LargePlanner\CorridorGraphBuilder;
use App\Services\LargePlanner\NetworkRouteProposalService;
use App\Services\LargePlanner\OdfProposalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LargePlannerOdfProposalTest extends TestCase
{
    use RefreshDatabase;

    public function test_odf_proposals_are_disabled_by_default(): void
    {
        [$project, $graph, $corridor] = $this->projectWithCorridor(false, null);

        $result = app(OdfProposalService::class)->propose($project, $graph, $this->placement($corridor->id, 2));

        $this->assertFalse($result['enabled']);
        $this->assertSame([], $result['odfs']);
        $this->assertSame([], $result['primary_routes']);
        $this->assertDatabaseCount('odfs', 0);
    }

    public function test_proposed_odfs_respect_capacity_and_stay_on_allowed_corridor(): void
    {
        [$project, $graph, $corridor] = $this->projectWithCorridor(true, 2);

        $result = app(OdfProposalService::class)->propose($project, $graph, $this->placement($corridor->id, 5));

        $this->assertCount(3, $result['odfs']);
        foreach ($result['odfs'] as $odf) {
            $this->assertLessThanOrEqual(2, $odf['occupancy']);
            $this->assertSame($corridor->id, $odf['corridor_id']);
            $this->assertLessThan(0.2, app(GeometryService::class)->distanceToRoute($odf['point'][0], $odf['point'][1], $corridor->path));
        }
        $this->assertCount(2, $result['primary_routes']);
        $this->assertSame('odf-0001', $result['primary_routes'][0]['from_odf_key']);
        $this->assertDatabaseCount('odfs', 0);
    }

    public function test_existing_odf_is_used_as_source_for_each_primary_route(): void
    {
        [$project, $graph, $corridor] = $this->projectWithCorridor(true, 2);
        $existing = Odf::factory()->create([
            'project_id' => $project->id,
            'latitude' => 43.8500,
            'longitude' => 18.4100,
        ]);

        $result = app(OdfProposalService::class)->propose($project, $graph, $this->placement($corridor->id, 3));

        $this->assertCount(2, $result['odfs']);
        $this->assertCount(2, $result['primary_routes']);
        foreach ($result['primary_routes'] as $route) {
            $this->assertSame($existing->id, $route['from_odf_id']);
            $this->assertNull($route['from_odf_key']);
        }
    }

    public function test_secondary_routes_use_proposed_odfs_when_option_is_enabled(): void
    {
        [$project, $graph, $corridor] = $this->projectWithCorridor(true, 4);
        $placement = $this->placement($corridor->id, 2);
        $odfPlan = app(OdfProposalService::class)->propose($project, $graph, $placement);

        $routes = app(NetworkRouteProposalService::class)->propose($project, $graph, $placement, $odfPlan);

        $this->assertCount(2, $routes['secondary_routes']);
        $this->assertSame('odf-0001', $routes['secondary_routes'][0]['odf_key']);
        $this->assertNull($routes['secondary_routes'][0]['odf_id']);
        $this->assertNotContains('missing_source_odf', array_column($routes['warnings'], 'code'));
    }

    private function projectWithCorridor(bool $proposeOdfs, ?int $capacity): array
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $project->largePlannerSetting()->create([
            'odo_capacity' => 8,
            'max_drop_length_m' => 150,
            'fiber_reserve_percent' => 20,
            'optimization_goal' => 'weighted',
            'propose_odfs' => $proposeOdfs,
            'odf_capacity' => $capacity,
        ]);
        $corridor = GisSegment::create([
            'project_id' => $project->id,
            'name' => 'Dozvoljeni koridor',
            'source' => 'test',
            'segment_type' => 'corridor',
            'is_allowed' => true,
            'planning_corridor_type' => 'secondary',
            'length_m' => 1000,
            'path' => [[43.8500, 18.4100], [43.8550, 18.4150]],
        ]);

        return [$project, app(CorridorGraphBuilder::class)->build($project), $corridor];
    }

    private function placement(int $corridorId, int $count): array
    {
        $odos = [];
        for ($index = 0; $index < $count; $index++) {
            $number = $index + 1;
            $odos[] = [
                'key' => 'odo-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT),
                'provisional_name' => 'ODO-P-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT),
                'point' => [43.8505 + ($index * 0.0005), 18.4105 + ($index * 0.0005)],
                'corridor_id' => $corridorId,
                'component' => 0,
                'capacity' => 8,
                'occupancy' => 0,
                'house_ids' => [],
            ];
        }

        return ['odos' => $odos];
    }
}
