<?php

namespace Tests\Feature;

use App\Jobs\RunProjectBackgroundTask;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
}
