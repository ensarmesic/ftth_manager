<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Models\Subscriber;
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

    public function test_splitter_count_is_calculated_from_house_count(): void
    {
        $project = $this->projectWithHouses(9);
        $plan = $this->postJson(route('projects.odo-plan.preview', $project))->json();

        $this->assertSame(3, $plan['cabinets'][0]['splitter_count']);
        $this->postJson(route('projects.odo-plan.confirm', $project), ['plan' => $plan])->assertCreated();
        $this->assertSame(3, Cabinet::firstOrFail()->splitter_count);
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

    public function test_map_suggestion_save_links_houses_subscribers_and_odf(): void
    {
        $project = $this->projectWithHouses(1);
        $house = $project->houses()->firstOrFail();
        $odf = Odf::create(['project_id' => $project->id, 'name' => 'ODF-1', 'address' => 'Centar', 'fiber_capacity' => 144, 'port_count' => 48, 'latitude' => 44.4490, 'longitude' => 18.6490]);
        $subscriber = Subscriber::create(['project_id' => $project->id, 'name' => 'Korisnik', 'address' => $house->address, 'service_status' => 'planned']);

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
        $this->assertSame($cabinet->id, $subscriber->fresh()->cabinet_id);
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
}
