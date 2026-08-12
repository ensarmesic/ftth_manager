<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FtthIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_plan_does_not_save_anything(): void
    {
        $project = $this->projectWithHouses(6);
        Odf::create(['project_id' => $project->id, 'name' => 'ODF-1', 'address' => 'Centar', 'fiber_capacity' => 144, 'port_count' => 48, 'latitude' => 44.4490, 'longitude' => 18.6490]);

        $response = $this->postJson(route('projects.odo-plan.preview', $project), ['max_distance_m' => 120]);

        $response->assertOk()
            ->assertJsonPath('summary.houses_with_coordinates', 6)
            ->assertJsonPath('summary.houses_without_coordinates', 0);
        $this->assertSame(0, Cabinet::count());
        $this->assertSame(0, House::whereNotNull('cabinet_id')->count());
    }

    public function test_confirmed_plan_creates_cabinets_and_links_houses(): void
    {
        $project = $this->projectWithHouses(10);
        Odf::create(['project_id' => $project->id, 'name' => 'ODF-1', 'address' => 'Centar', 'fiber_capacity' => 144, 'port_count' => 48, 'latitude' => 44.4490, 'longitude' => 18.6490]);
        $plan = $this->postJson(route('projects.odo-plan.preview', $project))->json();

        $this->postJson(route('projects.odo-plan.confirm', $project), ['plan' => $plan])
            ->assertCreated()
            ->assertJsonPath('linked_houses', 10);

        $this->assertGreaterThan(0, Cabinet::count());
        $this->assertSame(10, House::whereNotNull('cabinet_id')->count());
        $this->assertTrue(Cabinet::withCount('houses')->get()->every(fn (Cabinet $cabinet) => $cabinet->houses_count <= 12));
    }

    public function test_confirming_same_auto_plan_twice_does_not_duplicate_cabinets(): void
    {
        $project = $this->projectWithHouses(10);
        Odf::create(['project_id' => $project->id, 'name' => 'ODF-1', 'address' => 'Centar', 'fiber_capacity' => 144, 'port_count' => 48, 'latitude' => 44.4490, 'longitude' => 18.6490]);
        $plan = $this->postJson(route('projects.odo-plan.preview', $project))->json();

        $this->postJson(route('projects.odo-plan.confirm', $project), ['plan' => $plan])
            ->assertCreated()
            ->assertJsonPath('linked_houses', 10);
        $firstCabinetCount = Cabinet::where('project_id', $project->id)->count();

        $this->postJson(route('projects.odo-plan.confirm', $project), ['plan' => $plan])
            ->assertCreated()
            ->assertJsonPath('created', 0)
            ->assertJsonPath('linked_houses', 0);

        $this->assertSame($firstCabinetCount, Cabinet::where('project_id', $project->id)->count());
        $this->assertSame(10, House::whereNotNull('cabinet_id')->count());
    }

    public function test_splitter_count_is_calculated_from_house_count(): void
    {
        $project = $this->projectWithHouses(9);
        $plan = $this->postJson(route('projects.odo-plan.preview', $project))->json();

        $this->assertSame(3, $plan['cabinets'][0]['splitter_count']);
        $this->postJson(route('projects.odo-plan.confirm', $project), ['plan' => $plan])->assertCreated();
        $this->assertSame(3, Cabinet::firstOrFail()->splitter_count);
    }

    public function test_preview_plan_keeps_natural_house_clusters_together(): void
    {
        $project = Project::create(['name' => 'Zone', 'code' => 'ZONE', 'location' => 'Test', 'status' => 'planning']);

        foreach ([18.6400, 18.6700] as $zone => $longitude) {
            for ($i = 0; $i < 12; $i++) {
                House::create([
                    'project_id' => $project->id,
                    'label' => 'Z'.($zone + 1).'-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                    'address' => 'Zona '.($zone + 1),
                    'latitude' => 44.4490 + ($i * 0.00004),
                    'longitude' => $longitude + (($i % 3) * 0.00004),
                    'status' => 'planned',
                ]);
            }
        }

        $plan = $this->postJson(route('projects.odo-plan.preview', $project), [
            'max_houses_per_odo' => 12,
            'preferred_fill_min' => 8,
            'max_distance_m' => 180,
        ])->assertOk()->json();

        $this->assertSame(2, $plan['summary']['proposed_odo_count']);
        foreach ($plan['cabinets'] as $cabinet) {
            $zones = collect($cabinet['houses'])
                ->map(fn (array $house) => str_starts_with($house['label'], 'Z1-') ? 'Z1' : 'Z2')
                ->unique()
                ->values();

            $this->assertCount(1, $zones);
            $this->assertSame(12, $cabinet['house_count']);
            $this->assertLessThanOrEqual(180, $cabinet['max_house_distance_m']);
        }
    }

    public function test_houses_without_coordinates_are_skipped_and_reported(): void
    {
        $project = $this->projectWithHouses(3);
        House::create(['project_id' => $project->id, 'label' => 'Bez GPS', 'address' => 'N/A', 'status' => 'planned']);

        $this->postJson(route('projects.odo-plan.preview', $project))
            ->assertOk()
            ->assertJsonPath('summary.houses_with_coordinates', 3)
            ->assertJsonPath('summary.houses_without_coordinates', 1)
            ->assertJsonPath('summary.houses_without_coordinates_list.0.label', 'Bez GPS');
    }

    public function test_plan_rejects_house_from_another_project_and_rolls_back(): void
    {
        $project = $this->projectWithHouses(2);
        $otherProject = Project::create(['name' => 'Drugi', 'code' => 'DRUGI', 'location' => 'Test', 'status' => 'planning']);
        $otherHouse = House::create(['project_id' => $otherProject->id, 'label' => 'Tuđa', 'latitude' => 44.5, 'longitude' => 18.7, 'status' => 'planned']);
        $plan = $this->postJson(route('projects.odo-plan.preview', $project))->json();
        $plan['cabinets'][0]['houses'][] = ['id' => $otherHouse->id, 'label' => 'Tuđa', 'latitude' => 44.5, 'longitude' => 18.7];

        $this->postJson(route('projects.odo-plan.confirm', $project), ['plan' => $plan])
            ->assertUnprocessable();

        $this->assertSame(0, Cabinet::count());
        $this->assertSame(0, House::whereNotNull('cabinet_id')->count());
    }

    public function test_project_validation_finds_unlinked_house_unlinked_cabinet_and_bad_route(): void
    {
        $project = $this->projectWithHouses(1);
        Cabinet::create(['project_id' => $project->id, 'name' => 'ODO-1', 'address' => 'Test', 'splitter_count' => 1, 'ports_per_splitter' => 4]);
        NetworkRoute::create(['project_id' => $project->id, 'name' => 'T-1', 'route_type' => 'distribution', 'installation_type' => 'underground', 'duct_length_m' => 10, 'fiber_length_m' => 10, 'microduct_count' => 1, 'status' => 'planned']);

        $messages = collect($this->getJson(route('projects.validation', $project))->assertOk()->json('items'))->pluck('message')->join("\n");

        $this->assertStringContainsString('nema povezan ODO', $messages);
        $this->assertStringContainsString('nema povezan ODF', $messages);
        $this->assertStringContainsString('nema mikrocijev', $messages);
        $this->assertStringContainsString('nema kabal', $messages);
    }

    public function test_project_validation_finds_invalid_drop_endpoints_duplicate_points_and_length(): void
    {
        $project = Project::create(['name' => 'Geometrija', 'code' => 'GEO', 'location' => 'Test', 'status' => 'planning']);
        $cabinet = Cabinet::create([
            'project_id' => $project->id, 'name' => 'ODO-1', 'address' => 'Test',
            'splitter_count' => 1, 'ports_per_splitter' => 4, 'latitude' => 44.4500, 'longitude' => 18.6500,
        ]);
        $house = House::create([
            'project_id' => $project->id, 'cabinet_id' => $cabinet->id, 'label' => 'K-1',
            'latitude' => 44.4510, 'longitude' => 18.6510, 'status' => 'planned',
        ]);
        NetworkRoute::create([
            'project_id' => $project->id, 'cabinet_id' => $cabinet->id,
            'to_type' => 'house', 'to_id' => $house->id, 'name' => 'Drop pogrešan',
            'route_type' => 'drop', 'installation_type' => 'underground', 'duct_length_m' => 5,
            'fiber_length_m' => 5, 'fiber_count' => 4, 'microduct_count' => 1,
            'microduct_type' => '10/8', 'status' => 'planned',
            'path' => [[44.4520, 18.6520], [44.4520, 18.6520], [44.4530, 18.6530]],
        ]);

        $messages = collect($this->getJson(route('projects.validation', $project))->assertOk()->json('items'))->pluck('message')->join("\n");

        $this->assertStringContainsString('spremljena dužina ne odgovara geometriji', $messages);
        $this->assertStringContainsString('uzastopne duple tačke', $messages);
        $this->assertStringContainsString('ne završava na povezanoj kući', $messages);
        $this->assertStringContainsString('ne završava na dodijeljenom ODO', $messages);
    }

    public function test_material_summary_groups_lengths_by_microduct_and_fiber_type(): void
    {
        $project = Project::create(['name' => 'Materijali', 'code' => 'MAT', 'location' => 'Test', 'status' => 'planning']);
        NetworkRoute::create(['project_id' => $project->id, 'name' => 'A', 'route_type' => 'distribution', 'installation_type' => 'underground', 'duct_length_m' => 100, 'fiber_length_m' => 110, 'fiber_count' => 12, 'microduct_count' => 2, 'microduct_type' => '14/10', 'status' => 'planned']);
        NetworkRoute::create(['project_id' => $project->id, 'name' => 'B', 'route_type' => 'drop', 'installation_type' => 'underground', 'duct_length_m' => 30, 'fiber_length_m' => 30, 'fiber_count' => 4, 'microduct_count' => 1, 'microduct_type' => '10/8', 'status' => 'planned']);

        $materials = $this->getJson(route('projects.validation', $project))->assertOk()->json('materials');

        $this->assertSame(200, $materials['microduct_14_10_m']);
        $this->assertSame(30, $materials['microduct_10_8_m']);
        $this->assertSame(30, $materials['fiber_4_m']);
        $this->assertSame(110, $materials['fiber_12_m']);
    }

    public function test_map_suggestion_save_links_houses_and_odf(): void
    {
        $project = $this->projectWithHouses(1);
        $house = $project->houses()->firstOrFail();
        $odf = Odf::create(['project_id' => $project->id, 'name' => 'ODF-1', 'address' => 'Centar', 'fiber_capacity' => 144, 'port_count' => 48, 'latitude' => 44.4490, 'longitude' => 18.6490]);

        $this->postJson(route('map.suggestions.store'), [
            'project_id' => $project->id,
            'cabinets' => [[
                'name' => 'ODO-AUTO-01',
                'latitude' => 44.4491,
                'longitude' => 18.6491,
                'splitter_count' => 1,
                'odf_id' => $odf->id,
                'houses' => [[
                    'id' => $house->id,
                    'latitude' => (float) $house->latitude,
                    'longitude' => (float) $house->longitude,
                ]],
            ]],
        ])->assertOk()->assertJsonPath('linked_houses', 1);

        $cabinet = Cabinet::firstOrFail();
        $this->assertSame($odf->id, $cabinet->odf_id);
        $this->assertSame($cabinet->id, $house->fresh()->cabinet_id);
    }

    public function test_map_suggestion_save_reuses_existing_cabinet_by_name(): void
    {
        $project = $this->projectWithHouses(2);
        $houses = $project->houses()->orderBy('label')->get();
        $odf = Odf::create(['project_id' => $project->id, 'name' => 'ODF-1', 'address' => 'Centar', 'fiber_capacity' => 144, 'port_count' => 48, 'latitude' => 44.4490, 'longitude' => 18.6490]);

        $payload = [
            'project_id' => $project->id,
            'cabinets' => [[
                'name' => 'FTTH 1-1',
                'latitude' => 44.4491,
                'longitude' => 18.6491,
                'splitter_count' => 1,
                'odf_id' => $odf->id,
                'houses' => $houses->map(fn (House $house) => [
                    'id' => $house->id,
                    'latitude' => (float) $house->latitude,
                    'longitude' => (float) $house->longitude,
                ])->all(),
            ]],
        ];

        $this->postJson(route('map.suggestions.store'), $payload)
            ->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('linked_houses', 2);

        $payload['cabinets'][0]['latitude'] = 44.4492;
        $payload['cabinets'][0]['longitude'] = 18.6492;

        $this->postJson(route('map.suggestions.store'), $payload)
            ->assertOk()
            ->assertJsonPath('created', 0)
            ->assertJsonPath('linked_houses', 0);

        $this->assertSame(1, Cabinet::where('project_id', $project->id)->where('name', 'FTTH 1-1')->count());
        $this->assertSame(2, House::whereNotNull('cabinet_id')->count());
        $this->assertEquals(44.4492, (float) Cabinet::firstOrFail()->latitude);
    }

    public function test_map_suggestion_reassignment_replaces_the_old_drop_route(): void
    {
        $project = $this->projectWithHouses(2);
        $houses = $project->houses;
        $house = $houses->first();
        $otherHouse = $houses->last();
        $oldCabinet = Cabinet::factory()->for($project)->create();
        $otherCabinet = Cabinet::factory()->for($project)->create();
        $house->update(['cabinet_id' => $oldCabinet->id]);
        $otherHouse->update(['cabinet_id' => $otherCabinet->id]);
        $oldDrop = NetworkRoute::factory()->for($project)->create([
            'cabinet_id' => $oldCabinet->id,
            'from_type' => 'cabinet',
            'from_id' => $oldCabinet->id,
            'to_type' => 'house',
            'to_id' => $house->id,
            'route_type' => 'drop',
        ]);
        NetworkRoute::factory()->for($project)->create([
            'cabinet_id' => $otherCabinet->id,
            'from_type' => 'cabinet',
            'from_id' => $otherCabinet->id,
            'to_type' => 'house',
            'to_id' => $otherHouse->id,
            'route_type' => 'drop',
        ]);

        $this->postJson(route('map.suggestions.store'), [
            'project_id' => $project->id,
            'cabinets' => [[
                'name' => 'Novi ODO',
                'latitude' => 44.45,
                'longitude' => 18.65,
                'splitter_count' => 1,
                'houses' => $houses->map(fn (House $item) => [
                    'id' => $item->id,
                    'latitude' => (float) $item->latitude,
                    'longitude' => (float) $item->longitude,
                ])->all(),
            ]],
        ])->assertOk()->assertJsonPath('created_routes', 2);

        $newCabinet = Cabinet::where('name', 'Novi ODO')->firstOrFail();
        $this->assertSame($newCabinet->id, $house->fresh()->cabinet_id);
        $this->assertDatabaseMissing('routes', ['id' => $oldDrop->id]);
        $this->assertDatabaseCount('routes', 2);
        $this->assertDatabaseHas('routes', [
            'to_type' => 'house',
            'to_id' => $house->id,
            'cabinet_id' => $newCabinet->id,
        ]);
    }

    public function test_map_suggestion_rejects_more_houses_than_cabinet_capacity(): void
    {
        $project = $this->projectWithHouses(5);

        $this->postJson(route('map.suggestions.store'), [
            'project_id' => $project->id,
            'cabinets' => [[
                'name' => 'Premali ODO',
                'latitude' => 44.45,
                'longitude' => 18.65,
                'splitter_count' => 1,
                'houses' => $project->houses->map(fn (House $house) => [
                    'id' => $house->id,
                    'latitude' => (float) $house->latitude,
                    'longitude' => (float) $house->longitude,
                ])->all(),
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('cabinets');

        $this->assertDatabaseMissing('cabinets', ['name' => 'Premali ODO']);
        $this->assertSame(0, House::whereNotNull('cabinet_id')->count());
        $this->assertDatabaseCount('routes', 0);
    }

    public function test_houses_from_different_branches_are_never_mixed(): void
    {
        $project = Project::create(['name' => 'Krakovi', 'code' => 'KR', 'location' => 'Test', 'status' => 'planning']);
        $routeA = $this->branchRoute($project, 'Sekundarni krak 1', [[44.4490, 18.6400], [44.4510, 18.6400]]);
        $routeB = $this->branchRoute($project, 'Sekundarni krak 2', [[44.4490, 18.6700], [44.4510, 18.6700]]);
        $branchIds = NetworkBranch::whereIn('route_id', [$routeA->id, $routeB->id])->pluck('id')->all();
        foreach ([18.64005, 18.67005] as $zone => $longitude) {
            for ($i = 0; $i < 6; $i++) {
                House::create(['project_id' => $project->id, 'label' => 'B'.($zone + 1).'-'.$i, 'latitude' => 44.4491 + ($i * 0.0001), 'longitude' => $longitude, 'status' => 'planned']);
            }
        }

        $plan = $this->postJson(route('projects.odo-plan.preview', $project))->assertOk()->json();

        $this->assertCount(2, $plan['cabinets']);
        foreach ($plan['cabinets'] as $cabinet) {
            $this->assertCount(1, collect($cabinet['houses'])->pluck('branch_id')->unique());
            $this->assertContains($cabinet['branch_id'], $branchIds);
            $this->assertContains($cabinet['route_id'], [$routeA->id, $routeB->id]);
        }
    }

    public function test_house_is_assigned_to_nearest_branch_only_inside_distance_limit(): void
    {
        $project = Project::create(['name' => 'Limit', 'code' => 'LIM', 'location' => 'Test', 'status' => 'planning']);
        $route = $this->branchRoute($project, 'Krak 7', [[44.4490, 18.6400], [44.4510, 18.6400]]);
        $branch = NetworkBranch::where('route_id', $route->id)->firstOrFail();
        House::create(['project_id' => $project->id, 'label' => 'Blizu', 'latitude' => 44.4495, 'longitude' => 18.6401, 'status' => 'planned']);
        House::create(['project_id' => $project->id, 'label' => 'Daleko', 'latitude' => 44.4495, 'longitude' => 18.6450, 'status' => 'planned']);

        $plan = $this->postJson(route('projects.odo-plan.preview', $project), ['max_branch_distance_m' => 60])->assertOk()->json();

        $this->assertSame($branch->id, $plan['cabinets'][0]['branch_id']);
        $this->assertSame($route->id, $plan['cabinets'][0]['route_id']);
        $this->assertSame(['Daleko'], collect($plan['unassigned_houses'])->pluck('label')->all());
    }

    public function test_auto_odo_uses_only_secondary_routes_even_when_primary_is_closer(): void
    {
        $project = Project::create(['name' => 'Samo sekundarni', 'code' => 'SS', 'location' => 'Test', 'status' => 'planning']);
        $primary = NetworkRoute::create([
            'project_id' => $project->id,
            'name' => 'Primarni kabal',
            'route_type' => 'primarna',
            'installation_type' => 'underground',
            'duct_length_m' => 100,
            'fiber_length_m' => 100,
            'fiber_count' => 48,
            'microduct_count' => 1,
            'microduct_type' => '14/10',
            'status' => 'planned',
            'path' => [[44.4490, 18.6400], [44.4510, 18.6400]],
        ]);
        $secondary = $this->branchRoute($project, 'Sekundarni kabal', [[44.4490, 18.6410], [44.4510, 18.6410]]);
        $secondaryBranch = NetworkBranch::where('route_id', $secondary->id)->firstOrFail();
        House::create(['project_id' => $project->id, 'label' => 'Kuca', 'latitude' => 44.4495, 'longitude' => 18.6401, 'status' => 'planned']);

        $plan = $this->postJson(route('projects.odo-plan.preview', $project), ['max_branch_distance_m' => 120])->assertOk()->json();

        $this->assertSame($secondaryBranch->id, $plan['cabinets'][0]['branch_id']);
        $this->assertSame($secondary->id, $plan['cabinets'][0]['route_id']);
        $this->assertNotSame($primary->id, $plan['cabinets'][0]['route_id']);
        $this->assertEqualsWithDelta(18.6410, $plan['cabinets'][0]['proposed_longitude'], 0.00001);

        $this->postJson(route('projects.odo-plan.confirm', $project), ['plan' => $plan])->assertCreated();

        $cabinet = Cabinet::where('project_id', $project->id)->firstOrFail();
        $this->assertSame($secondaryBranch->id, $cabinet->branch_id);
        $this->assertEqualsWithDelta(18.6410, (float) $cabinet->longitude, 0.00001);
    }

    public function test_houses_are_sorted_by_chainage_and_gap_creates_new_group(): void
    {
        $project = Project::create(['name' => 'Chainage', 'code' => 'CH', 'location' => 'Test', 'status' => 'planning']);
        $this->branchRoute($project, 'Krak 1', [[44.4490, 18.6400], [44.4550, 18.6400]]);
        House::create(['project_id' => $project->id, 'label' => 'Treca', 'latitude' => 44.4535, 'longitude' => 18.64005, 'status' => 'planned']);
        House::create(['project_id' => $project->id, 'label' => 'Prva', 'latitude' => 44.4492, 'longitude' => 18.64005, 'status' => 'planned']);
        House::create(['project_id' => $project->id, 'label' => 'Druga', 'latitude' => 44.4497, 'longitude' => 18.64005, 'status' => 'planned']);

        $plan = $this->postJson(route('projects.odo-plan.preview', $project), ['max_gap_m' => 150])->assertOk()->json();

        $this->assertCount(2, $plan['cabinets']);
        $this->assertSame(['Prva', 'Druga'], collect($plan['cabinets'][0]['houses'])->pluck('label')->all());
        $this->assertSame('Treca', $plan['cabinets'][1]['houses'][0]['label']);
        $this->assertLessThan($plan['cabinets'][0]['houses'][1]['chainage_m'], $plan['cabinets'][0]['houses'][0]['chainage_m']);
    }

    public function test_odo_is_placed_on_route_and_named_by_branch(): void
    {
        $project = Project::create(['name' => 'Naming', 'code' => 'NM', 'location' => 'Test', 'status' => 'planning']);
        $this->branchRoute($project, 'Sekundarni krak 1', [[44.4490, 18.6400], [44.4510, 18.6400]]);
        $this->branchRoute($project, 'Sekundarni krak 2', [[44.4490, 18.6700], [44.4510, 18.6700]]);
        foreach ([[18.64008, 'A'], [18.67008, 'B']] as [$longitude, $prefix]) {
            for ($i = 0; $i < 13; $i++) {
                House::create(['project_id' => $project->id, 'label' => $prefix.$i, 'latitude' => 44.4490 + ($i * 0.00008), 'longitude' => $longitude, 'status' => 'planned']);
            }
        }

        $plan = $this->postJson(route('projects.odo-plan.preview', $project))->assertOk()->json();
        $names = collect($plan['cabinets'])->pluck('confirmed_name')->all();

        $this->assertContains('FTTH 1-1', $names);
        $this->assertContains('FTTH 1-2', $names);
        $this->assertContains('FTTH 2-1', $names);
        foreach ($plan['cabinets'] as $cabinet) {
            $this->assertEqualsWithDelta($cabinet['branch_index'] === 1 ? 18.6400 : 18.6700, $cabinet['proposed_longitude'], 0.00001);
            $this->assertLessThanOrEqual(12, $cabinet['house_count']);
        }
    }

    public function test_drop_preview_follows_existing_route_geometry(): void
    {
        $project = Project::create(['name' => 'Drop po trasi', 'code' => 'DPT', 'location' => 'Test', 'status' => 'planning']);
        $this->branchRoute($project, 'Sekundarni krak 1', [
            [44.4490, 18.6400],
            [44.4490, 18.6420],
            [44.4510, 18.6420],
        ]);
        House::create(['project_id' => $project->id, 'label' => 'Pocetak', 'latitude' => 44.44905, 'longitude' => 18.6402, 'status' => 'planned']);
        House::create(['project_id' => $project->id, 'label' => 'Kraj', 'latitude' => 44.4508, 'longitude' => 18.64205, 'status' => 'planned']);

        $plan = $this->postJson(route('projects.odo-plan.preview', $project), ['max_house_to_odo_m' => 400, 'max_gap_m' => 500])->assertOk()->json();
        $paths = collect($plan['cabinets'][0]['drop_preview'])->pluck('path');

        $this->assertTrue($paths->contains(fn (array $path) => count($path) > 2));
        $this->assertTrue($paths->flatten(1)->contains(fn (array $point) => abs($point[0] - 44.4490) < 0.000001 && abs($point[1] - 18.6420) < 0.000001));
    }

    public function test_auto_ftth_name_uses_branch_and_child_branch_notation(): void
    {
        $project = Project::create(['name' => 'Podkrak naziv', 'code' => 'PKN', 'location' => 'Test', 'status' => 'planning']);
        $this->branchRoute($project, 'Sekundarni krak 1.6.1', [
            [44.4490, 18.6400],
            [44.4510, 18.6400],
        ]);

        for ($i = 0; $i < 4; $i++) {
            House::create(['project_id' => $project->id, 'label' => 'K'.($i + 1), 'latitude' => 44.4492 + ($i * 0.0001), 'longitude' => 18.64005, 'status' => 'planned']);
        }

        $plan = $this->postJson(route('projects.odo-plan.preview', $project))->assertOk()->json();

        $this->assertSame('FTTH 1-6.1-1', $plan['cabinets'][0]['confirmed_name']);
    }

    public function test_auto_ftth_name_ignores_branch_outlet_suffix(): void
    {
        $project = Project::create(['name' => 'Izvod naziv', 'code' => 'IZV', 'location' => 'Test', 'status' => 'planning']);
        $this->branchRoute($project, 'Sekundarni krak 1.6.1-1', [
            [44.4490, 18.6400],
            [44.4510, 18.6400],
        ]);

        for ($i = 0; $i < 4; $i++) {
            House::create(['project_id' => $project->id, 'label' => 'I'.($i + 1), 'latitude' => 44.4492 + ($i * 0.0001), 'longitude' => 18.64005, 'status' => 'planned']);
        }

        $plan = $this->postJson(route('projects.odo-plan.preview', $project))->assertOk()->json();

        $this->assertSame('FTTH 1-6.1-1', $plan['cabinets'][0]['confirmed_name']);
    }

    public function test_confirm_rejects_cross_project_odf_and_cross_branch_house(): void
    {
        $project = Project::create(['name' => 'Confirm', 'code' => 'CF', 'location' => 'Test', 'status' => 'planning']);
        $otherProject = Project::create(['name' => 'Other', 'code' => 'OT', 'location' => 'Test', 'status' => 'planning']);
        $this->branchRoute($project, 'Krak 1', [[44.4490, 18.6400], [44.4510, 18.6400]]);
        $this->branchRoute($project, 'Krak 2', [[44.4490, 18.6700], [44.4510, 18.6700]]);
        House::create(['project_id' => $project->id, 'label' => 'A', 'latitude' => 44.4492, 'longitude' => 18.64005, 'status' => 'planned']);
        House::create(['project_id' => $project->id, 'label' => 'B', 'latitude' => 44.4492, 'longitude' => 18.67005, 'status' => 'planned']);
        $otherOdf = Odf::create(['project_id' => $otherProject->id, 'name' => 'ODF-X', 'address' => 'X', 'fiber_capacity' => 144, 'port_count' => 48, 'latitude' => 44.4490, 'longitude' => 18.6490]);
        $plan = $this->postJson(route('projects.odo-plan.preview', $project))->assertOk()->json();
        $plan['cabinets'][0]['nearest_odf_id'] = $otherOdf->id;

        $this->postJson(route('projects.odo-plan.confirm', $project), ['plan' => $plan])->assertUnprocessable();
        $this->assertSame(0, Cabinet::count());

        $plan = $this->postJson(route('projects.odo-plan.preview', $project))->assertOk()->json();
        $plan['cabinets'][0]['houses'][] = $plan['cabinets'][1]['houses'][0];
        $this->postJson(route('projects.odo-plan.confirm', $project), ['plan' => $plan])->assertUnprocessable();
        $this->assertSame(0, Cabinet::count());
    }

    public function test_fallback_warning_and_score_penalizes_unassigned_houses(): void
    {
        $project = $this->projectWithHouses(3);
        $fallback = $this->postJson(route('projects.odo-plan.preview', $project))->assertOk()->json();

        $this->assertStringContainsString('Nema definisanih krakova', $fallback['warnings'][0]['message']);

        $projectWithRoute = Project::create(['name' => 'Score', 'code' => 'SC', 'location' => 'Test', 'status' => 'planning']);
        $this->branchRoute($projectWithRoute, 'Krak 1', [[44.4490, 18.6400], [44.4510, 18.6400]]);
        House::create(['project_id' => $projectWithRoute->id, 'label' => 'Blizu', 'latitude' => 44.4495, 'longitude' => 18.6401, 'status' => 'planned']);
        House::create(['project_id' => $projectWithRoute->id, 'label' => 'Daleko', 'latitude' => 44.4495, 'longitude' => 18.6500, 'status' => 'planned']);

        $penalized = $this->postJson(route('projects.odo-plan.preview', $projectWithRoute))->assertOk()->json();

        $this->assertSame(1, $penalized['summary']['unassigned_house_count']);
        $this->assertLessThan($fallback['summary']['score'], $penalized['summary']['score']);
    }

    private function projectWithHouses(int $count): Project
    {
        $project = Project::create(['name' => 'Plan', 'code' => 'PR', 'location' => 'Test', 'status' => 'planning']);
        for ($i = 0; $i < $count; $i++) {
            House::create([
                'project_id' => $project->id,
                'label' => 'K-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'address' => 'Adresa '.$i,
                'latitude' => 44.4490 + ($i * 0.00008),
                'longitude' => 18.6490 + ($i * 0.00008),
                'status' => 'planned',
            ]);
        }

        return $project;
    }

    private function branchRoute(Project $project, string $name, array $path): NetworkRoute
    {
        $route = NetworkRoute::create([
            'project_id' => $project->id,
            'name' => $name,
            'route_type' => 'secondary',
            'installation_type' => 'underground',
            'duct_length_m' => 100,
            'fiber_length_m' => 100,
            'fiber_count' => 12,
            'microduct_count' => 1,
            'microduct_type' => '14/10',
            'status' => 'planned',
            'path' => $path,
        ]);

        NetworkBranch::create([
            'project_id' => $project->id,
            'route_id' => $route->id,
            'name' => $name,
            'type' => 'secondary',
        ]);

        return $route;
    }
}
