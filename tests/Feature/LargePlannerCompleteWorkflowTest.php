<?php

namespace Tests\Feature;

use App\Jobs\RunProjectBackgroundTask;
use App\Models\GisSegment;
use App\Models\House;
use App\Models\Odf;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LargePlannerCompleteWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_large_planner_workflow_uses_existing_network_models(): void
    {
        Storage::fake();
        Queue::fake();
        $user = User::factory()->designer()->create();
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $project->largePlannerSetting()->create([
            'odo_capacity' => 8,
            'max_drop_length_m' => 150,
            'fiber_reserve_percent' => 20,
            'optimization_goal' => 'weighted',
        ]);
        GisSegment::create([
            'project_id' => $project->id,
            'name' => 'Glavni testni koridor',
            'source' => 'workflow-test',
            'segment_type' => 'corridor',
            'is_allowed' => true,
            'planning_corridor_type' => 'secondary',
            'length_m' => 500,
            'path' => [[43.8500, 18.4100], [43.8550, 18.4150]],
        ]);
        Odf::factory()->create(['project_id' => $project->id, 'latitude' => 43.8500, 'longitude' => 18.4100]);
        House::factory()->count(3)->sequence(
            ['label' => 'K-1', 'latitude' => 43.8510, 'longitude' => 18.4110],
            ['label' => 'K-2', 'latitude' => 43.8520, 'longitude' => 18.4120],
            ['label' => 'K-3', 'latitude' => 43.8530, 'longitude' => 18.4130],
        )->create(['project_id' => $project->id]);

        $this->actingAs($user)->getJson(route('projects.large-planner.readiness', $project))
            ->assertOk()->assertJsonPath('ready', true);

        $queued = $this->actingAs($user)->postJson(route('projects.background-tasks.store', $project), ['type' => 'large_plan'])
            ->assertAccepted();
        $task = $project->backgroundTasks()->findOrFail($queued->json('task.id'));
        (new RunProjectBackgroundTask($task->id))->handle();

        $preview = $this->actingAs($user)->getJson(route('projects.large-planner.preview.show', [$project, $task]))
            ->assertOk()
            ->assertJsonPath('task.status', 'completed')
            ->assertJsonPath('preview.clustering.summary.clustered_houses', 3);
        $this->assertNotEmpty($preview->json('preview.odo_placement.odos'));

        $this->actingAs($user)->postJson(route('projects.large-planner.preview.validate-final', [$project, $task]))
            ->assertOk()->assertJsonPath('valid', true);
        $this->actingAs($user)->postJson(route('projects.large-planner.preview.confirm', [$project, $task]), ['acknowledge_warnings' => true])
            ->assertOk()
            ->assertJsonPath('summary.houses', 3)
            ->assertJsonPath('summary.materials.unclassified_routes', 0);

        $this->actingAs($user)->get(route('projects.large-planner.preview.export', [$project, $task]))
            ->assertOk()->assertDownload("veliki-plan-{$project->code}-varijanta-{$task->id}.csv");
        $this->assertDatabaseCount('cabinets', 1);
        $this->assertDatabaseCount('network_branches', 1);
        $this->assertDatabaseCount('routes', 4);
        $this->assertSame(3, $project->houses()->whereNotNull('cabinet_id')->count());
    }
}
