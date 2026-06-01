<?php

namespace Tests\Feature;

use App\Models\Cabinet;
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
        $this->get(route('map.index'))->assertOk()->assertSee('S-01');
    }
}
