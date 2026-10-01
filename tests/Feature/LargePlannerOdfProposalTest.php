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
        $this->assertSame(['ODF 1', 'ODF 2', 'ODF 3'], collect($result['odfs'])->pluck('provisional_name')->all());
        foreach ($result['odfs'] as $odf) {
            $this->assertLessThanOrEqual(2, $odf['occupancy']);
            $this->assertSame($corridor->id, $odf['corridor_id']);
            $this->assertLessThan(0.2, app(GeometryService::class)->distanceToRoute($odf['point'][0], $odf['point'][1], $corridor->path));
            foreach ($this->placement($corridor->id, 5)['odos'] as $odo) {
                $this->assertGreaterThanOrEqual(5, app(GeometryService::class)->distanceBetweenPoints($odf['point'], $odo['point']));
            }
        }
        $this->assertCount(2, $result['primary_routes']);
        $this->assertEqualsCanonicalizing(
            ['odf-0001', 'odf-0002', 'odf-0003'],
            collect($result['primary_routes'])->flatMap(fn (array $route) => [$route['from_odf_key'], $route['to_odf_key']])->unique()->values()->all(),
        );
        $this->assertDatabaseCount('odfs', 0);
    }

    public function test_manual_existing_odf_prevents_an_unwanted_automatic_odf_proposal(): void
    {
        [$project, $graph, $corridor] = $this->projectWithCorridor(true, 2);
        $existing = Odf::factory()->create([
            'project_id' => $project->id,
            'latitude' => 43.8500,
            'longitude' => 18.4100,
        ]);

        $result = app(OdfProposalService::class)->propose($project, $graph, $this->placement($corridor->id, 3));

        $this->assertFalse($result['enabled']);
        $this->assertSame([], $result['odfs']);
        $this->assertSame([], $result['primary_routes']);
        $this->assertSame(1, $result['summary']['existing_source_odfs']);
        $this->assertNotNull($existing->id);
    }

    public function test_multiple_manual_odfs_are_connected_as_one_minimum_primary_network(): void
    {
        [$project, $graph, $corridor] = $this->projectWithCorridor(true, 8);
        $odfs = collect([
            [43.8502, 18.4102],
            [43.8525, 18.4125],
            [43.8548, 18.4148],
        ])->map(fn (array $point, int $index) => Odf::factory()->create([
            'project_id' => $project->id,
            'name' => 'ODF-'.($index + 1),
            'latitude' => $point[0],
            'longitude' => $point[1],
            'import_batch' => null,
        ]));

        $result = app(OdfProposalService::class)->propose($project, $graph, $this->placement($corridor->id, 3));

        $this->assertFalse($result['enabled']);
        $this->assertCount(2, $result['primary_routes']);
        $this->assertEqualsCanonicalizing($odfs->pluck('id')->all(), collect($result['primary_routes'])
            ->flatMap(fn (array $route) => [$route['from_odf_id'], $route['to_odf_id']])->unique()->values()->all());
        $this->assertSame([], $result['warnings']);
    }

    public function test_secondary_routes_use_proposed_odfs_when_option_is_enabled(): void
    {
        [$project, $graph, $corridor] = $this->projectWithCorridor(true, 4);
        $placement = $this->placement($corridor->id, 2);
        $odfPlan = app(OdfProposalService::class)->propose($project, $graph, $placement);

        $routes = app(NetworkRouteProposalService::class)->propose($project, $graph, $placement, $odfPlan);

        $this->assertCount(2, $routes['secondary_routes']);
        $this->assertEqualsCanonicalizing(['odo-0001', 'odo-0002'], collect($routes['secondary_routes'])->pluck('odo_keys')->flatten()->all());
        $this->assertTrue(collect($routes['secondary_routes'])->every(fn (array $route) => $route['odf_key'] === 'odf-0001'));
        $this->assertTrue(collect($routes['secondary_routes'])->every(fn (array $route) => $route['odf_id'] === null));
        $this->assertNotContains('missing_source_odf', array_column($routes['warnings'], 'code'));
    }

    public function test_a_corridor_is_not_split_between_odfs_when_its_odos_fit_capacity(): void
    {
        [$project, $graph, $corridor] = $this->projectWithCorridor(true, 3);
        $placement = $this->placement($corridor->id, 6);
        foreach ($placement['odos'] as $index => &$odo) {
            $odo['corridor_id'] = $index % 2 === 0 ? 101 : 202;
        }
        unset($odo);

        $result = app(OdfProposalService::class)->propose($project, $graph, $placement);

        $owners = collect($result['odfs'])->flatMap(fn (array $odf) => collect($odf['odo_keys'])
            ->mapWithKeys(fn (string $key) => [$key => $odf['key']]));
        $corridorOwners = collect($placement['odos'])->groupBy('corridor_id')
            ->map(fn ($odos) => $odos->map(fn (array $odo) => $owners[$odo['key']])->unique()->values()->all());

        $this->assertCount(2, $result['odfs']);
        $this->assertCount(1, $corridorOwners[101]);
        $this->assertCount(1, $corridorOwners[202]);
        $this->assertNotSame($corridorOwners[101][0], $corridorOwners[202][0]);
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
            'planning_corridor_type' => 'main',
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
