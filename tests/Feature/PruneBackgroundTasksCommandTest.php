<?php

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneBackgroundTasksCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_expires_only_old_unconfirmed_task_files(): void
    {
        Storage::fake();
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $old = $project->backgroundTasks()->create([
            'type' => 'large_plan',
            'status' => 'completed',
            'result_path' => 'background-tasks/1/large-plan.json',
            'result_name' => 'large-plan.json',
            'updated_at' => now()->subDays(31),
        ]);
        $confirmed = $project->backgroundTasks()->create([
            'type' => 'large_plan',
            'status' => 'completed',
            'result_path' => 'background-tasks/2/large-plan.json',
            'confirmed_at' => now()->subDays(31),
            'updated_at' => now()->subDays(31),
        ]);
        $recent = $project->backgroundTasks()->create([
            'type' => 'large_plan',
            'status' => 'failed',
            'result_path' => 'background-tasks/3/large-plan.json',
        ]);
        Storage::put($old->result_path, '{}');
        Storage::put($confirmed->result_path, '{}');
        Storage::put($recent->result_path, '{}');

        $this->artisan('ftth:prune-background-tasks --days=30')
            ->expectsOutput('Očišćeno zadataka: 1; datoteka: 1.')
            ->assertSuccessful();

        $this->assertSame('expired', $old->fresh()->status);
        $this->assertNull($old->fresh()->result_path);
        Storage::assertMissing('background-tasks/1/large-plan.json');
        Storage::assertExists('background-tasks/2/large-plan.json');
        Storage::assertExists('background-tasks/3/large-plan.json');
        $this->assertSame('completed', $confirmed->fresh()->status);
        $this->assertSame('failed', $recent->fresh()->status);
    }
}
