<?php

namespace Tests\Feature;

use App\Models\GisSegment;
use App\Models\House;
use App\Models\Odf;
use App\Models\Project;
use App\Services\LargePlanner\CorridorGraphBuilder;
use App\Services\LargePlanner\NetworkRouteProposalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LargePlannerRouteProposalTest extends TestCase
{
    use RefreshDatabase;

    public function test_secondary_and_drop_routes_are_proposed_without_persistence(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $this->corridor($project, [[43.8500, 18.4100], [43.8510, 18.4100], [43.8510, 18.4120]]);
        $odf = Odf::factory()->create(['project_id' => $project->id, 'latitude' => 43.8500, 'longitude' => 18.4100]);
        $house = House::factory()->create(['project_id' => $project->id, 'latitude' => 43.8511, 'longitude' => 18.4121]);
        $placement = $this->placement($house, [43.8510, 18.4120]);

        $result = app(NetworkRouteProposalService::class)->propose(
            $project,
            app(CorridorGraphBuilder::class)->build($project),
            $placement,
        );

        $this->assertCount(1, $result['secondary_routes']);
        $this->assertSame($odf->id, $result['secondary_routes'][0]['odf_id']);
        $this->assertContains([43.851, 18.41], $result['secondary_routes'][0]['path']);
        $this->assertCount(1, $result['drop_routes']);
        $this->assertSame($house->id, $result['drop_routes'][0]['house_id']);
        $this->assertDatabaseCount('routes', 0);
    }

    public function test_missing_odf_is_reported_and_drops_are_still_previewed(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $this->corridor($project, [[43.8500, 18.4100], [43.8510, 18.4110]]);
        $house = House::factory()->create(['project_id' => $project->id, 'latitude' => 43.8502, 'longitude' => 18.4102]);

        $result = app(NetworkRouteProposalService::class)->propose(
            $project,
            app(CorridorGraphBuilder::class)->build($project),
            $this->placement($house, [43.8501, 18.4101]),
        );

        $this->assertSame('missing_source_odf', $result['warnings'][0]['code']);
        $this->assertSame('odo_without_odf_route', $result['warnings'][1]['code']);
        $this->assertCount(0, $result['secondary_routes']);
        $this->assertCount(1, $result['drop_routes']);
    }

    public function test_odf_outside_allowed_corridor_is_not_silently_connected(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $this->corridor($project, [[43.8500, 18.4100], [43.8510, 18.4110]]);
        Odf::factory()->create(['project_id' => $project->id, 'latitude' => 44.0000, 'longitude' => 19.0000]);
        $house = House::factory()->create(['project_id' => $project->id, 'latitude' => 43.8502, 'longitude' => 18.4102]);

        $result = app(NetworkRouteProposalService::class)->propose(
            $project,
            app(CorridorGraphBuilder::class)->build($project),
            $this->placement($house, [43.8501, 18.4101]),
        );

        $this->assertCount(0, $result['secondary_routes']);
        $this->assertSame('odo_without_odf_route', $result['warnings'][0]['code']);
    }

    private function placement(House $house, array $point): array
    {
        return ['odos' => [[
            'key' => 'odo-0001',
            'provisional_name' => 'ODO-P-0001',
            'point' => $point,
            'house_ids' => [$house->id],
        ]]];
    }

    private function corridor(Project $project, array $path): GisSegment
    {
        return GisSegment::create([
            'project_id' => $project->id,
            'name' => 'Koridor',
            'source' => 'test',
            'segment_type' => 'corridor',
            'is_allowed' => true,
            'planning_corridor_type' => 'secondary',
            'length_m' => 500,
            'path' => $path,
        ]);
    }
}
