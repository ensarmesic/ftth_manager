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
        $this->assertSame([43.851, 18.412], $result['drop_routes'][0]['path'][0]);
        $this->assertSame([(float) $house->latitude, (float) $house->longitude], collect($result['drop_routes'][0]['path'])->last());
        $this->assertDatabaseCount('routes', 0);
    }

    public function test_drop_follows_the_main_corridor_before_its_short_house_connection(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $this->corridor($project, [[43.8500, 18.4100], [43.8500, 18.4140]]);
        $house = House::factory()->create(['project_id' => $project->id, 'latitude' => 43.8510, 'longitude' => 18.4140]);

        $result = app(NetworkRouteProposalService::class)->propose(
            $project,
            app(CorridorGraphBuilder::class)->build($project),
            $this->placement($house, [43.8500, 18.4100]),
        );

        $drop = $result['drop_routes'][0];
        $this->assertSame([43.85, 18.41], $drop['path'][0]);
        $this->assertContains([43.85, 18.414], $drop['path']);
        $this->assertSame([(float) $house->latitude, (float) $house->longitude], $drop['path'][array_key_last($drop['path'])]);
        $this->assertGreaterThan(300, $drop['corridor_length_m']);
        $this->assertGreaterThan(100, $drop['house_connection_length_m']);
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

    public function test_colocated_odf_and_odo_keep_both_logical_route_endpoints(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $this->corridor($project, [[43.8500, 18.4100], [43.8510, 18.4110]]);
        $odf = Odf::factory()->create(['project_id' => $project->id, 'latitude' => 43.8500, 'longitude' => 18.4100]);
        $house = House::factory()->create(['project_id' => $project->id, 'latitude' => 43.8501, 'longitude' => 18.4101]);

        $result = app(NetworkRouteProposalService::class)->propose(
            $project,
            app(CorridorGraphBuilder::class)->build($project),
            $this->placement($house, [43.8500, 18.4100]),
        );

        $route = $result['secondary_routes'][0];
        $this->assertSame($odf->id, $route['odf_id']);
        $this->assertCount(2, $route['path']);
        $this->assertSame($route['path'][0], $route['path'][1]);
    }

    public function test_secondary_route_passes_through_required_waypoint(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $this->corridor($project, [[43.8500, 18.4100], [43.8520, 18.4100]]);
        $this->corridor($project, [[43.8500, 18.4100], [43.8500, 18.4120], [43.8520, 18.4100]]);
        Odf::factory()->create(['project_id' => $project->id, 'latitude' => 43.8500, 'longitude' => 18.4100]);
        $house = House::factory()->create(['project_id' => $project->id, 'latitude' => 43.8520, 'longitude' => 18.4100]);
        $project->largePlannerConstraints()->create([
            'type' => 'required_waypoint',
            'name' => 'Obavezni prolaz',
            'geometry' => [43.8500, 18.4120],
        ]);

        $result = app(NetworkRouteProposalService::class)->propose(
            $project,
            app(CorridorGraphBuilder::class)->build($project),
            $this->placement($house, [43.8520, 18.4100]),
        );

        $this->assertCount(1, $result['secondary_routes']);
        $this->assertContains([43.85, 18.412], $result['secondary_routes'][0]['path']);
        $this->assertGreaterThan(350, $result['secondary_routes'][0]['length_m']);
    }

    public function test_single_odo_branch_becomes_numbered_lateral_from_nearest_odo(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $this->corridor($project, [[43.8500, 18.4100], [43.8520, 18.4100]]);
        $this->corridor($project, [[43.8510, 18.4100], [43.8510, 18.4120]]);
        $odf = Odf::factory()->create(['project_id' => $project->id, 'latitude' => 43.8500, 'longitude' => 18.4100]);
        $placement = ['odos' => [
            ['key' => 'odo-0001', 'provisional_name' => 'ODO-1', 'point' => [43.8505, 18.4100], 'house_ids' => []],
            ['key' => 'odo-0002', 'provisional_name' => 'ODO-2', 'point' => [43.8520, 18.4100], 'house_ids' => []],
            ['key' => 'odo-0003', 'provisional_name' => 'ODO-3', 'point' => [43.8510, 18.4120], 'house_ids' => []],
        ]];

        $result = app(NetworkRouteProposalService::class)->propose(
            $project,
            app(CorridorGraphBuilder::class)->build($project),
            $placement,
        );

        $lateral = collect($result['secondary_routes'])->firstWhere('is_lateral', true);
        $this->assertNotNull($lateral);
        $this->assertSame($odf->id, $lateral['odf_id']);
        $this->assertSame('Sekundarni krak 1.1.1', $lateral['name']);
        $this->assertSame('secondary-branch-1-1', $lateral['parent_branch_key']);
        $this->assertContains($lateral['from_odo_key'], ['odo-0001', 'odo-0002', 'odo-0003']);
        $this->assertNotSame($lateral['from_odo_key'], $lateral['terminal_odo_key']);
        $this->assertSame('ZO-1.1.1.1', collect($result['odos'])->firstWhere('key', $lateral['terminal_odo_key'])['provisional_name']);
    }

    public function test_secondary_branch_numbers_restart_under_each_odf_number(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $this->corridor($project, [[43.8500, 18.4100], [43.8600, 18.4100]]);
        $placement = ['odos' => collect(range(1, 4))->map(fn (int $number) => [
            'key' => 'odo-000'.$number,
            'provisional_name' => 'ODO-'.$number,
            'point' => [43.8500 + ($number * 0.002), 18.4100],
            'house_ids' => [],
        ])->all()];
        $odfPlan = ['enabled' => true, 'odfs' => [
            ['key' => 'odf-0001', 'odf_number' => 1, 'point' => [43.8500, 18.4100], 'odo_keys' => ['odo-0001', 'odo-0002']],
            ['key' => 'odf-0002', 'odf_number' => 2, 'point' => [43.8600, 18.4100], 'odo_keys' => ['odo-0003', 'odo-0004']],
        ]];

        $result = app(NetworkRouteProposalService::class)->propose(
            $project,
            app(CorridorGraphBuilder::class)->build($project),
            $placement,
            $odfPlan,
        );

        $this->assertEqualsCanonicalizing(
            ['Sekundarni krak 1.1', 'Sekundarni krak 2.1'],
            collect($result['secondary_routes'])->pluck('name')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['ZO-1.1.1', 'ZO-1.1.2', 'ZO-2.1.1', 'ZO-2.1.2'],
            collect($result['odos'])->pluck('provisional_name')->all(),
        );
    }

    public function test_normal_secondary_branches_have_at_most_two_odos(): void
    {
        $result = $this->linearBranchResult(43.8520);
        $main = collect($result['secondary_routes'])->where('is_lateral', false);

        $this->assertTrue($main->every(fn (array $route) => count($route['odo_keys']) <= 2));
        $this->assertCount(1, collect($result['secondary_routes'])->where('is_lateral', true));
    }

    public function test_three_odos_are_used_only_to_avoid_a_separate_long_branch(): void
    {
        $result = $this->linearBranchResult(43.8600);
        $sizes = collect($result['secondary_routes'])->where('is_lateral', false)
            ->map(fn (array $route) => count($route['odo_keys']))->sort()->values()->all();

        $this->assertSame([2, 3], $sizes);
        $this->assertCount(0, collect($result['secondary_routes'])->where('is_lateral', true));
    }

    private function linearBranchResult(float $endLatitude): array
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $this->corridor($project, [[43.8500, 18.4100], [$endLatitude, 18.4100]]);
        Odf::factory()->create(['project_id' => $project->id, 'latitude' => 43.8500, 'longitude' => 18.4100]);
        $step = ($endLatitude - 43.8500) / 5;
        $odos = collect(range(1, 5))->map(fn (int $number) => [
            'key' => 'odo-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT),
            'provisional_name' => 'ODO-'.$number,
            'point' => [43.8500 + ($step * $number), 18.4100],
            'house_ids' => [],
        ])->all();

        return app(NetworkRouteProposalService::class)->propose(
            $project,
            app(CorridorGraphBuilder::class)->build($project),
            ['odos' => $odos],
        );
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
