<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_authenticated_mutation_is_audited_without_sensitive_values(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('projects.store'), [
            'name' => 'Audit projekat', 'code' => 'AUDIT', 'location' => 'Test',
            'status' => 'planning', 'password' => 'ne-smije-biti-snimljeno',
        ])->assertRedirect();

        $project = Project::where('code', 'AUDIT')->firstOrFail();
        $log = ActivityLog::latest()->firstOrFail();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('POST', $log->method);
        $this->assertSame('projects.store', $log->route_name);
        $this->assertContains('name', $log->metadata['fields']);
        $this->assertNotContains('password', $log->metadata['fields']);
        $this->assertStringNotContainsString('ne-smije', json_encode($log->metadata));
        $this->assertNull($log->project_id, 'Novo kreirani projekat nije route parametar; audit ipak bilježi samu akciju.');
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }
}
