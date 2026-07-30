<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkBranch;
use App\Models\Odf;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_network_elements_cannot_be_moved_to_another_project(): void
    {
        $first = Project::factory()->create();
        $second = Project::factory()->create();
        $odf = Odf::factory()->for($first)->create();
        $cabinet = Cabinet::factory()->for($first)->create();
        $house = House::factory()->for($first)->create();

        $this->put(route('odfs.update', $odf), [
            ...$odf->only(['name', 'address', 'fiber_capacity', 'port_count', 'latitude', 'longitude', 'notes']),
            'project_id' => $second->id,
        ])->assertSessionHasErrors('project_id');

        $this->put(route('cabinets.update', $cabinet), [
            ...$cabinet->only(['name', 'address', 'splitter_count', 'ports_per_splitter', 'latitude', 'longitude']),
            'project_id' => $second->id,
        ])->assertSessionHasErrors('project_id');

        $this->put(route('houses.update', $house), [
            ...$house->only(['label', 'address', 'latitude', 'longitude', 'status']),
            'project_id' => $second->id,
        ])->assertSessionHasErrors('project_id');

        $this->assertSame($first->id, $odf->fresh()->project_id);
        $this->assertSame($first->id, $cabinet->fresh()->project_id);
        $this->assertSame($first->id, $house->fresh()->project_id);
    }

    public function test_branches_from_different_projects_cannot_be_reordered_together(): void
    {
        $first = Project::factory()->create();
        $second = Project::factory()->create();
        $branchA = NetworkBranch::create([
            'project_id' => $first->id,
            'name' => 'A',
            'type' => 'primary',
            'sort_order' => 1,
        ]);
        $branchB = NetworkBranch::create([
            'project_id' => $second->id,
            'name' => 'B',
            'type' => 'primary',
            'sort_order' => 1,
        ]);

        $this->patchJson(route('branches.reorder'), [
            'ids' => [$branchA->id, $branchB->id],
        ])->assertUnprocessable();
    }
}
