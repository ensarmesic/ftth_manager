<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\Material;
use App\Models\NetworkRoute;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class MediaskyWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_material_calculation_groups_microducts_and_fibers(): void
    {
        $project = Project::create(['name' => 'Test', 'code' => 'TEST', 'location' => 'Test', 'status' => 'planning']);
        NetworkRoute::create(['project_id' => $project->id, 'name' => 'T1', 'route_type' => 'distribution', 'installation_type' => 'underground', 'duct_length_m' => 100, 'fiber_length_m' => 120, 'fiber_count' => 12, 'microduct_count' => 2, 'microduct_type' => '14/10', 'status' => 'planned']);

        $this->post(route('materials.calculate', $project))->assertRedirect();

        $this->assertDatabaseHas('materials', ['project_id' => $project->id, 'name' => 'Mikrocijev 14/10', 'planned_quantity' => 200]);
        $this->assertDatabaseHas('materials', ['project_id' => $project->id, 'name' => 'Opticki kabl 12 niti', 'planned_quantity' => 120]);
    }

    public function test_dxf_line_import_creates_route(): void
    {
        $project = Project::create(['name' => 'DXF', 'code' => 'DXF', 'location' => 'Test', 'status' => 'planning']);
        $dxf = "0\nSECTION\n2\nENTITIES\n0\nLINE\n10\n18.6498\n20\n44.4493\n11\n18.6508\n21\n44.4503\n0\nENDSEC\n0\nEOF\n";

        $this->post(route('routes.dxf.import'), ['project_id' => $project->id, 'dxf' => UploadedFile::fake()->createWithContent('trasa.dxf', $dxf)])->assertRedirect();

        $this->assertDatabaseHas('routes', ['project_id' => $project->id, 'name' => 'DXF trasa 1', 'microduct_type' => '14/10']);
    }

    public function test_map_plan_saves_route_path_without_cabinet_link(): void
    {
        $project = Project::create(['name' => 'Mapa', 'code' => 'MAPA', 'location' => 'Test', 'status' => 'planning']);
        $plan = ['routes' => [['name' => 'S-01', 'route_type' => 'distribution', 'duct_length_m' => 42, 'fiber_length_m' => 42, 'path' => [[44.4493, 18.6498], [44.4500, 18.6505]]]]];

        $this->postJson(route('map.plan.store'), ['project_id' => $project->id, 'plan' => json_encode($plan)])->assertOk();

        $this->assertDatabaseHas('routes', ['project_id' => $project->id, 'name' => 'S-01', 'duct_length_m' => 42]);
        $this->get(route('dashboard'))->assertOk()->assertSee('S-01')->assertSee('Predlozi ODO');
        $this->get(route('map.index'))->assertRedirect(route('dashboard'));
    }

    public function test_confirmed_cabinet_suggestion_links_house_and_creates_drop_route(): void
    {
        $project = Project::create(['name' => 'Auto plan', 'code' => 'AUTO', 'location' => 'Test', 'status' => 'planning']);
        $house = House::create(['project_id' => $project->id, 'label' => 'K-001', 'latitude' => 44.4493, 'longitude' => 18.6498, 'status' => 'planned']);

        $this->postJson(route('map.suggestions.store'), [
            'project_id' => $project->id,
            'cabinets' => [[
                'name' => 'ODO-001',
                'latitude' => 44.4495,
                'longitude' => 18.6500,
                'splitter_count' => 1,
                'houses' => [[
                    'latitude' => 44.4493,
                    'longitude' => 18.6498,
                    'path' => [[44.4495, 18.6500], [44.4494, 18.6499], [44.4493, 18.6498]],
                ]],
            ]],
        ])->assertOk()->assertJson(['created' => 1, 'linked_houses' => 1, 'created_routes' => 1]);

        $this->assertNotNull($house->fresh()->cabinet_id);
        $this->assertDatabaseHas('routes', ['project_id' => $project->id, 'route_type' => 'drop', 'fiber_count' => 4, 'microduct_type' => '10/8']);
        $this->assertCount(3, NetworkRoute::where('route_type', 'drop')->firstOrFail()->path);
    }

    public function test_route_drawn_on_map_is_measured_by_server_and_returned_as_json(): void
    {
        $project = Project::create(['name' => 'Crtanje', 'code' => 'CRTANJE', 'location' => 'Test', 'status' => 'planning']);
        $path = [[44.4493, 18.6498], [44.4503, 18.6508]];

        $response = $this->postJson(route('routes.store'), [
            'project_id' => $project->id,
            'name' => 'Trasa sa mape',
            'route_type' => 'distribution',
            'installation_type' => 'underground',
            'duct_length_m' => 1,
            'fiber_length_m' => 1,
            'fiber_count' => 12,
            'microduct_count' => 1,
            'microduct_type' => '14/10',
            'status' => 'planned',
            'path' => json_encode($path),
        ]);

        $response->assertCreated()->assertJsonPath('route.name', 'Trasa sa mape');
        $this->assertGreaterThan(100, $response->json('route.length'));
        $this->assertDatabaseHas('routes', ['project_id' => $project->id, 'name' => 'Trasa sa mape', 'fiber_count' => 12]);
    }

    public function test_deleting_cabinet_releases_houses_and_removes_only_its_drop_routes(): void
    {
        $project = Project::create(['name' => 'Replan', 'code' => 'REPLAN', 'location' => 'Test', 'status' => 'planning']);
        $cabinet = Cabinet::create(['project_id' => $project->id, 'name' => 'ODO-001', 'address' => 'Test', 'splitter_count' => 2, 'ports_per_splitter' => 4]);
        $house = House::create(['project_id' => $project->id, 'cabinet_id' => $cabinet->id, 'label' => 'K-001', 'latitude' => 44.4493, 'longitude' => 18.6498, 'status' => 'planned']);
        NetworkRoute::create(['project_id' => $project->id, 'cabinet_id' => $cabinet->id, 'name' => 'Drop ODO-001-1', 'route_type' => 'drop', 'installation_type' => 'underground', 'duct_length_m' => 10, 'fiber_length_m' => 10, 'fiber_count' => 4, 'microduct_count' => 1, 'microduct_type' => '10/8', 'status' => 'planned']);
        $distribution = NetworkRoute::create(['project_id' => $project->id, 'cabinet_id' => $cabinet->id, 'name' => 'Glavna', 'route_type' => 'distribution', 'installation_type' => 'underground', 'duct_length_m' => 20, 'fiber_length_m' => 20, 'fiber_count' => 12, 'microduct_count' => 1, 'microduct_type' => '14/10', 'status' => 'planned']);

        $this->deleteJson(route('cabinets.delete', $cabinet))->assertOk();

        $this->assertNull($house->fresh()->cabinet_id);
        $this->assertDatabaseMissing('routes', ['name' => 'Drop ODO-001-1']);
        $this->assertDatabaseHas('routes', ['id' => $distribution->id, 'name' => 'Glavna']);
    }

    public function test_route_geometry_edit_recalculates_length(): void
    {
        $project = Project::create(['name' => 'Edit', 'code' => 'EDIT', 'location' => 'Test', 'status' => 'planning']);
        $route = NetworkRoute::create(['project_id' => $project->id, 'name' => 'T1', 'route_type' => 'distribution', 'installation_type' => 'underground', 'duct_length_m' => 1, 'fiber_length_m' => 1, 'fiber_count' => 12, 'microduct_count' => 1, 'microduct_type' => '14/10', 'status' => 'planned', 'path' => [[44.4493, 18.6498], [44.4494, 18.6499]]]);

        $response = $this->patchJson(route('routes.geometry.update', $route), [
            'path' => [[44.4493, 18.6498], [44.4503, 18.6508]],
        ]);

        $response->assertOk()->assertJsonPath('route.id', $route->id);
        $this->assertGreaterThan(100, $route->fresh()->duct_length_m);
        $this->assertSame($route->fresh()->duct_length_m, $route->fresh()->fiber_length_m);
    }

    public function test_route_metadata_can_be_updated_without_changing_geometry(): void
    {
        $project = Project::create(['name' => 'Meta', 'code' => 'META', 'location' => 'Test', 'status' => 'planning']);
        $route = NetworkRoute::create(['project_id' => $project->id, 'name' => 'Stari naziv', 'route_type' => 'distribution', 'installation_type' => 'underground', 'duct_length_m' => 25, 'fiber_length_m' => 25, 'fiber_count' => 12, 'microduct_count' => 1, 'microduct_type' => '14/10', 'status' => 'planned', 'path' => [[44.4493, 18.6498], [44.4494, 18.6499]]]);

        $this->patchJson(route('routes.update', $route), [
            'name' => 'Novi naziv',
            'route_type' => 'drop',
            'microduct_type' => '10/8',
            'fiber_count' => 4,
        ])->assertOk()->assertJsonPath('route.name', 'Novi naziv');

        $this->assertDatabaseHas('routes', ['id' => $route->id, 'name' => 'Novi naziv', 'route_type' => 'drop', 'fiber_count' => 4]);
        $this->assertSame([[44.4493, 18.6498], [44.4494, 18.6499]], $route->fresh()->path);
    }
}
