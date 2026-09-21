<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_follows_controlled_workflow_and_records_history(): void
    {
        $user = User::factory()->designer()->create();
        $project = Project::factory()->create(['workflow_stage' => 'draft']);

        $this->actingAs($user)->patch(route('projects.workflow.update', $project), ['stage' => 'review', 'note' => 'Spremno za kontrolu'])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'workflow_stage' => 'review', 'workflow_changed_by' => $user->id]);
        $this->assertDatabaseHas('project_stage_histories', ['project_id' => $project->id, 'from_stage' => 'draft', 'to_stage' => 'review', 'note' => 'Spremno za kontrolu']);
    }

    public function test_project_cannot_skip_workflow_stages(): void
    {
        $project = Project::factory()->create(['workflow_stage' => 'draft']);
        $this->actingAs(User::factory()->designer()->create())
            ->patch(route('projects.workflow.update', $project), ['stage' => 'approved'])
            ->assertStatus(422);
        $this->assertSame('draft', $project->fresh()->workflow_stage);
    }

    public function test_viewer_cannot_change_project_workflow(): void
    {
        $project = Project::factory()->create();
        $this->actingAs(User::factory()->viewer()->create())
            ->patch(route('projects.workflow.update', $project), ['stage' => 'review'])
            ->assertForbidden();
    }
}
