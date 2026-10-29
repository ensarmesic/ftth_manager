<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_with_linked_network_elements_can_be_deleted(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $odf = Odf::factory()->create(['project_id' => $project->id]);
        $branch = NetworkBranch::factory()->create(['project_id' => $project->id, 'odf_id' => $odf->id]);
        $parent = Cabinet::factory()->create(['project_id' => $project->id, 'odf_id' => $odf->id, 'branch_id' => $branch->id]);
        $cabinet = Cabinet::factory()->create(['project_id' => $project->id, 'odf_id' => $odf->id, 'parent_cabinet_id' => $parent->id]);
        House::factory()->count(3)->create(['project_id' => $project->id, 'cabinet_id' => $cabinet->id, 'branch_id' => $branch->id]);
        NetworkRoute::factory()->create(['project_id' => $project->id, 'odf_id' => $odf->id, 'cabinet_id' => $cabinet->id]);
        $keptHouse = House::factory()->create(['project_id' => $other->id]);

        $this->actingAs(User::factory()->create())
            ->delete(route('projects.delete', $project->id))
            ->assertSessionHas('success');

        $this->assertModelMissing($project);
        $this->assertSame(0, House::where('project_id', $project->id)->count());
        $this->assertSame(0, Cabinet::where('project_id', $project->id)->count());
        $this->assertSame(0, Odf::where('project_id', $project->id)->count());
        $this->assertModelExists($keptHouse);
    }
}
