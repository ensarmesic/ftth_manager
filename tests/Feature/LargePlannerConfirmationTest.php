<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\Odf;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectSnapshotService;
use App\Services\ProjectValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LargePlannerConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
    }

    public function test_valid_preview_is_confirmed_transactionally_and_only_once(): void
    {
        [$project, $task, $house, $sourceOdf] = $this->taskWithPreview();
        $user = User::factory()->designer()->create();

        $this->actingAs($user)->postJson(route('projects.large-planner.preview.confirm', [$project, $task]))
            ->assertOk()->assertJsonPath('summary.odos', 1)->assertJsonPath('summary.routes', 2);

        $this->assertDatabaseCount('cabinets', 1);
        $this->assertDatabaseCount('network_branches', 1);
        $this->assertDatabaseCount('routes', 2);
        $this->assertSame($sourceOdf->id, $project->cabinets()->firstOrFail()->odf_id);
        $this->assertNotNull($house->fresh()->cabinet_id);
        $this->assertDatabaseCount('project_snapshots', 1);

        $secondPath = "background-tasks/{$project->id}/second-confirm.json";
        Storage::put($secondPath, Storage::get($task->result_path));
        $secondTask = $project->backgroundTasks()->create(['type' => 'large_plan', 'status' => 'completed', 'progress' => 100, 'result_path' => $secondPath]);
        $this->actingAs($user)->postJson(route('projects.large-planner.preview.confirm', [$project, $secondTask]))
            ->assertUnprocessable();
        $this->assertDatabaseCount('cabinets', 1);
        $this->assertDatabaseCount('routes', 2);
        $this->assertDatabaseHas('activity_logs', ['project_id' => $project->id, 'method' => 'CONFIRM', 'subject_id' => $task->id]);
        $validation = app(ProjectValidationService::class)->validateProject($project->fresh());
        $this->assertSame([], collect($validation)->where('level', 'error')->pluck('message')->all());

        $this->actingAs($user)->postJson(route('projects.large-planner.preview.confirm', [$project, $task]))
            ->assertOk()->assertJsonPath('summary.routes', 2);
        $this->assertDatabaseCount('cabinets', 1);
        $this->assertDatabaseCount('routes', 2);
        $this->assertDatabaseCount('project_snapshots', 1);
    }

    public function test_invalid_preview_rolls_back_without_network_changes(): void
    {
        [$project, $task] = $this->taskWithPreview();
        $preview = json_decode(Storage::get($task->result_path), true, flags: JSON_THROW_ON_ERROR);
        $preview['routes']['secondary_routes'] = [];
        Storage::put($task->result_path, json_encode($preview, JSON_THROW_ON_ERROR));

        $this->actingAs(User::factory()->designer()->create())
            ->postJson(route('projects.large-planner.preview.confirm', [$project, $task]))
            ->assertUnprocessable();

        $this->assertDatabaseCount('cabinets', 0);
        $this->assertDatabaseCount('routes', 0);
        $this->assertDatabaseCount('project_snapshots', 0);
        $this->assertNull($task->fresh()->confirmed_at);
    }

    public function test_restoring_pre_confirmation_snapshot_reopens_plan_without_orphans(): void
    {
        [$project, $task] = $this->taskWithPreview();
        $user = User::factory()->designer()->create();
        $this->actingAs($user)->postJson(route('projects.large-planner.preview.confirm', [$project, $task]))->assertOk();
        $snapshot = $task->fresh()->snapshot_id;

        app(ProjectSnapshotService::class)->restore($project, $project->snapshots()->findOrFail($snapshot));

        $this->assertDatabaseCount('cabinets', 0);
        $this->assertDatabaseCount('routes', 0);
        $this->assertNull($task->fresh()->confirmed_at);
        $this->assertNull($task->fresh()->snapshot_id);
        $this->assertNull($task->fresh()->confirmation_summary);

        $this->actingAs($user)->postJson(route('projects.large-planner.preview.confirm', [$project, $task]))->assertOk();
        $this->assertDatabaseCount('cabinets', 1);
        $this->assertDatabaseCount('routes', 2);
    }

    private function taskWithPreview(): array
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $project->largePlannerSetting()->create(['odo_capacity' => 8, 'max_drop_length_m' => 150, 'fiber_reserve_percent' => 20, 'optimization_goal' => 'weighted']);
        $house = House::factory()->create(['project_id' => $project->id, 'label' => 'K-1']);
        $sourceOdf = Odf::factory()->create(['project_id' => $project->id, 'latitude' => 43.85, 'longitude' => 18.41]);
        $secondaryPath = [[43.85, 18.41], [43.851, 18.411]];
        $dropPath = [[43.851, 18.411], [(float) $house->latitude, (float) $house->longitude]];
        $preview = [
            'project_id' => $project->id,
            'odo_placement' => ['odos' => [['key' => 'odo-0001', 'provisional_name' => 'ODO-P-0001', 'point' => [43.851, 18.411], 'capacity' => 8, 'occupancy' => 1, 'house_ids' => [$house->id]]]],
            'odf_placement' => ['odfs' => [], 'primary_routes' => []],
            'routes' => [
                'secondary_routes' => [['key' => 'secondary-odo-0001', 'odo_key' => 'odo-0001', 'odf_id' => $sourceOdf->id, 'odf_key' => null, 'path' => $secondaryPath, 'length_m' => 140]],
                'drop_routes' => [['key' => 'drop-1', 'odo_key' => 'odo-0001', 'house_id' => $house->id, 'path' => $dropPath, 'length_m' => 20]],
            ],
            'cable_capacity' => ['routes' => ['primary_routes' => [], 'secondary_routes' => [['key' => 'secondary-odo-0001', 'fiber_count' => 12]]]],
            'warnings' => ['items' => [], 'can_confirm' => true],
        ];
        $path = "background-tasks/{$project->id}/confirm.json";
        Storage::put($path, json_encode($preview, JSON_THROW_ON_ERROR));
        $task = $project->backgroundTasks()->create(['type' => 'large_plan', 'status' => 'completed', 'progress' => 100, 'result_path' => $path]);

        return [$project, $task, $house, $sourceOdf];
    }
}
