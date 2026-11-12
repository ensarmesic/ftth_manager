<?php

namespace Tests\Feature;

use App\Models\GisSegment;
use App\Models\House;
use App\Models\Odf;
use App\Models\Project;
use App\Services\RouteGraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GisPlanCabinetPlacementTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 44.45;

    private const LNG = 18.65;

    public function test_route_between_two_crossings_does_not_detour_to_the_end_of_the_street(): void
    {
        $project = Project::factory()->create();
        $this->road($project, [$this->point(0, 0), $this->point(0, 400)]);
        $this->road($project, [$this->point(-50, 100), $this->point(50, 100)]);
        $this->road($project, [$this->point(-50, 300), $this->point(50, 300)]);

        $route = app(RouteGraphService::class)->shortestPath($project->id, $this->point(50, 100), $this->point(50, 300));

        $this->assertNotNull($route);
        $this->assertEqualsWithDelta(300, $route['length_m'], 3);
    }

    public function test_street_ending_on_the_middle_of_another_street_is_connected(): void
    {
        $project = Project::factory()->create();
        $this->road($project, [$this->point(0, 0), $this->point(0, 400)]);
        $this->road($project, [$this->point(0.5, 200), $this->point(60, 200)]);

        $route = app(RouteGraphService::class)->shortestPath($project->id, $this->point(0, 0), $this->point(60, 200));

        $this->assertNotNull($route);
        $this->assertEqualsWithDelta(260, $route['length_m'], 3);
    }

    public function test_route_between_two_points_on_the_same_segment_goes_straight_along_it(): void
    {
        $project = Project::factory()->create();
        $this->road($project, [$this->point(0, 0), $this->point(0, 400)]);

        $route = app(RouteGraphService::class)->shortestPath($project->id, $this->point(10, 100), $this->point(10, 300));

        $this->assertNotNull($route);
        $this->assertEqualsWithDelta(220, $route['length_m'], 3);
    }

    public function test_houses_on_opposite_sides_of_the_odf_get_separate_cabinets_on_the_road(): void
    {
        $project = Project::factory()->create();
        $this->odf($project, $this->point(5, 0));
        $this->road($project, [$this->point(0, -300), $this->point(0, 300)]);
        foreach ([140, 160, 180, 200] as $distance) {
            $this->house($project, 'W'.$distance, $this->point(10, -$distance));
            $this->house($project, 'E'.$distance, $this->point(10, $distance));
        }

        $plan = $this->getJson(route('projects.gis-plan.preview', $project))->assertOk()->json();

        $this->assertSame(8, $plan['summary']['routed_houses']);
        $this->assertCount(2, $plan['cabinets']);
        foreach ($plan['cabinets'] as $cabinet) {
            $sides = collect($cabinet['houses'])->map(fn (array $house) => $house['label'][0])->unique()->values()->all();
            $this->assertCount(1, $sides, 'A cabinet must not serve houses on both sides of the ODF.');
            $this->assertCount(4, $cabinet['houses']);
            // The cabinet sits on the road, between its own houses.
            $this->assertEqualsWithDelta(self::LAT, $cabinet['lat'], 0.000002);
            $east = ($cabinet['lng'] - self::LNG) * 111320 * cos(deg2rad(self::LAT));
            $this->assertGreaterThanOrEqual(139, abs($east));
            $this->assertLessThanOrEqual(201, abs($east));
            $this->assertSame($sides[0] === 'E', $east > 0);
            $this->assertLessThanOrEqual(60, $cabinet['max_drop_m']);
        }
        $this->assertSame([], array_values(array_filter($plan['warnings'], fn (string $warning) => str_contains($warning, 'drop duzi'))));
    }

    public function test_a_full_street_gets_one_cabinet_in_the_middle_of_its_houses(): void
    {
        $project = Project::factory()->create();
        $this->odf($project, $this->point(0, 0));
        $this->road($project, [$this->point(0, 0), $this->point(0, 500)]);
        for ($index = 0; $index < 12; $index++) {
            $this->house($project, 'K'.($index + 1), $this->point(8, 300 + ($index * 10)));
        }

        $plan = $this->getJson(route('projects.gis-plan.preview', $project))->assertOk()->json();

        $this->assertCount(1, $plan['cabinets']);
        $cabinet = $plan['cabinets'][0];
        $this->assertSame(12, $cabinet['house_count']);
        $east = ($cabinet['lng'] - self::LNG) * 111320 * cos(deg2rad(self::LAT));
        $this->assertGreaterThanOrEqual(339, $east);
        $this->assertLessThanOrEqual(371, $east);
        $this->assertEqualsWithDelta(self::LAT, $cabinet['lat'], 0.000002);
        $this->assertLessThanOrEqual(70, $cabinet['max_drop_m']);
    }

    public function test_houses_on_two_side_streets_are_not_mixed_when_drops_would_be_too_long(): void
    {
        $project = Project::factory()->create();
        $this->odf($project, $this->point(0, 0));
        $this->road($project, [$this->point(0, 0), $this->point(0, 200)]);
        // Two side streets leave the main road at the same junction, north and south.
        $this->road($project, [$this->point(0, 200), $this->point(250, 200)]);
        $this->road($project, [$this->point(0, 200), $this->point(-250, 200)]);
        foreach ([170, 190, 210, 230] as $distance) {
            $this->house($project, 'N'.$distance, $this->point($distance, 206));
            $this->house($project, 'S'.$distance, $this->point(-$distance, 206));
        }

        $plan = $this->getJson(route('projects.gis-plan.preview', $project))->assertOk()->json();

        $this->assertCount(2, $plan['cabinets']);
        foreach ($plan['cabinets'] as $cabinet) {
            $this->assertCount(1, collect($cabinet['houses'])->map(fn (array $house) => $house['label'][0])->unique());
            $this->assertLessThanOrEqual(120, $cabinet['max_drop_m']);
        }
    }

    /**
     * A point given as metres north and east of the test origin.
     */
    private function point(float $north, float $east): array
    {
        return [
            round(self::LAT + ($north / 111320), 7),
            round(self::LNG + ($east / (111320 * cos(deg2rad(self::LAT)))), 7),
        ];
    }

    private function road(Project $project, array $path): GisSegment
    {
        return GisSegment::create([
            'project_id' => $project->id,
            'name' => 'Cesta',
            'segment_type' => 'road',
            'source' => 'test',
            'is_allowed' => true,
            'length_m' => 0,
            'path' => $path,
        ]);
    }

    private function odf(Project $project, array $point): Odf
    {
        return Odf::create([
            'project_id' => $project->id,
            'name' => 'ODF-1',
            'address' => 'Centar',
            'fiber_capacity' => 144,
            'port_count' => 48,
            'latitude' => $point[0],
            'longitude' => $point[1],
        ]);
    }

    private function house(Project $project, string $label, array $point): House
    {
        return House::create([
            'project_id' => $project->id,
            'label' => $label,
            'address' => 'Kuca',
            'status' => 'planned',
            'latitude' => $point[0],
            'longitude' => $point[1],
        ]);
    }
}
