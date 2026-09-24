<?php

namespace Tests\Feature;

use App\Jobs\RunProjectBackgroundTask;
use App\Models\GisSegment;
use App\Models\House;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectBackgroundTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_designer_can_queue_heavy_project_operation(): void
    {
        Queue::fake();
        $project = Project::factory()->create();
        $this->actingAs(User::factory()->designer()->create())->post(route('projects.background-tasks.store', $project), ['type' => 'auto_plan', 'limit' => 80])
            ->assertRedirect()->assertSessionHas('success');
        $task = $project->backgroundTasks()->firstOrFail();
        $this->assertSame('queued', $task->status);
        Queue::assertPushed(RunProjectBackgroundTask::class, fn ($job) => $job->taskId === $task->id);
    }

    public function test_viewer_cannot_queue_background_operation(): void
    {
        $project = Project::factory()->create();
        $this->actingAs(User::factory()->viewer()->create())->post(route('projects.background-tasks.store', $project), ['type' => 'dxf'])->assertForbidden();
    }

    public function test_large_project_plan_can_be_queued_only_once_while_active(): void
    {
        Queue::fake();
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $user = User::factory()->designer()->create();

        $this->actingAs($user)->postJson(route('projects.background-tasks.store', $project), ['type' => 'large_plan'])
            ->assertAccepted()
            ->assertJsonPath('task.progress', 0);
        $this->actingAs($user)->postJson(route('projects.background-tasks.store', $project), ['type' => 'large_plan'])
            ->assertConflict();

        $this->assertDatabaseCount('project_background_tasks', 1);
        Queue::assertPushed(RunProjectBackgroundTask::class, 1);
    }

    public function test_standard_project_cannot_queue_large_plan(): void
    {
        Queue::fake();
        $project = Project::factory()->create(['planning_mode' => 'standard']);

        $this->actingAs(User::factory()->designer()->create())
            ->postJson(route('projects.background-tasks.store', $project), ['type' => 'large_plan'])
            ->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_failed_large_plan_can_be_safely_requeued(): void
    {
        Queue::fake();
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $task = $project->backgroundTasks()->create([
            'user_id' => User::factory()->designer()->create()->id,
            'type' => 'large_plan',
            'status' => 'failed',
            'progress' => 65,
            'status_message' => 'Greška.',
            'error' => 'Privremena greška.',
            'finished_at' => now(),
        ]);

        $this->actingAs(User::factory()->designer()->create())
            ->postJson(route('projects.background-tasks.retry', [$project, $task]))
            ->assertAccepted()
            ->assertJsonPath('task.status', 'queued')
            ->assertJsonPath('task.progress', 0);

        $this->assertNull($task->fresh()->error);
        Queue::assertPushed(RunProjectBackgroundTask::class, fn ($job) => $job->taskId === $task->id);
    }

    public function test_large_plan_job_stores_preview_and_completes_progress(): void
    {
        Storage::fake();
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $project->largePlannerSetting()->create([
            'odo_capacity' => 16,
            'max_drop_length_m' => 150,
            'fiber_reserve_percent' => 20,
            'optimization_goal' => 'weighted',
        ]);
        GisSegment::create([
            'project_id' => $project->id,
            'name' => 'Koridor',
            'source' => 'test',
            'segment_type' => 'corridor',
            'is_allowed' => true,
            'planning_corridor_type' => 'secondary',
            'length_m' => 1000,
            'path' => [[43.8500, 18.4100], [43.8600, 18.4200]],
        ]);
        House::factory()->create(['project_id' => $project->id, 'latitude' => 43.8550, 'longitude' => 18.4150]);
        $task = $project->backgroundTasks()->create(['type' => 'large_plan']);

        (new RunProjectBackgroundTask($task->id))->handle();

        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertSame(100, $task->progress);
        $this->assertSame(1, $task->attempt_count);
        Storage::assertExists($task->result_path);
        $result = json_decode(Storage::get($task->result_path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($project->id, $result['project_id']);
        $this->assertDatabaseCount('routes', 0);

        $result['odo_placement']['odos'][0]['locked'] = true;
        $result['odo_placement']['odos'][0]['point'] = [43.856, 18.416];
        Storage::put($task->result_path, json_encode($result, JSON_THROW_ON_ERROR));
        $replan = $project->backgroundTasks()->create(['type' => 'large_plan', 'options' => ['base_task_id' => $task->id]]);
        (new RunProjectBackgroundTask($replan->id))->handle();
        $replanned = json_decode(Storage::get($replan->fresh()->result_path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($replanned['odo_placement']['odos'][0]['locked']);
        $this->assertSame([43.856, 18.416], $replanned['odo_placement']['odos'][0]['point']);
    }
}
