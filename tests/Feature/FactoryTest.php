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

/**
 * Guards the model factories: they must produce persistable records so new
 * tests can lean on `Model::factory()` instead of hand-writing 20-field arrays.
 */
class FactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_factories_persist_valid_records(): void
    {
        $project = Project::factory()->create();
        $odf = Odf::factory()->for($project)->create();
        $cabinet = Cabinet::factory()->for($project)->for($odf)->create();
        $branch = NetworkBranch::factory()->for($project)->create();
        House::factory()->for($project)->for($cabinet)->create();
        NetworkRoute::factory()->for($project)->create();
        NetworkRoute::factory()->for($project)->trench()->create();

        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseHas('odfs', ['id' => $odf->id, 'project_id' => $project->id]);
        $this->assertDatabaseHas('cabinets', ['id' => $cabinet->id, 'odf_id' => $odf->id]);
        $this->assertDatabaseHas('houses', ['project_id' => $project->id, 'cabinet_id' => $cabinet->id]);
        $this->assertDatabaseHas('network_branches', ['id' => $branch->id, 'type' => 'secondary']);
        $this->assertSame(1, NetworkRoute::where('route_type', 'distribution')->count());
        $this->assertSame(1, NetworkRoute::where('route_type', 'trench')->count());
    }
}
