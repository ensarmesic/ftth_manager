<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SessionAndAuditManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_see_and_revoke_another_session(): void
    {
        $admin = User::factory()->administrator()->create();
        $other = User::factory()->designer()->create();
        DB::table('sessions')->insert([
            'id' => 'other-session', 'user_id' => $other->id, 'ip_address' => '192.0.2.10',
            'user_agent' => 'Test Browser', 'payload' => '', 'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($admin)->get(route('settings.index'))
            ->assertOk()->assertSee('Test Browser')->assertSee('192.0.2.10');

        $this->actingAs($admin)->delete(route('settings.sessions.destroy', 'other-session'))
            ->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseMissing('sessions', ['id' => 'other-session']);
    }

    public function test_audit_can_be_filtered_and_exported_as_csv(): void
    {
        $admin = User::factory()->administrator()->create();
        $project = Project::factory()->create();
        ActivityLog::create([
            'user_id' => $admin->id, 'project_id' => $project->id, 'method' => 'PATCH',
            'route_name' => 'projects.update', 'path' => '/projekti/'.$project->id,
            'subject_type' => 'Project', 'subject_id' => $project->id, 'status_code' => 302,
            'metadata' => ['fields' => ['name']], 'ip_address' => '127.0.0.1',
        ]);
        ActivityLog::create([
            'user_id' => $admin->id, 'method' => 'DELETE', 'route_name' => 'other',
            'path' => '/other', 'status_code' => 204,
        ]);

        $this->actingAs($admin)->get(route('settings.index', ['audit_method' => 'PATCH']))
            ->assertOk()->assertSee('projects.update')->assertDontSee('other</td>', false);

        $response = $this->actingAs($admin)->get(route('settings.audit.export', ['audit_method' => 'PATCH']));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('projects.update', $response->streamedContent());
        $this->assertStringNotContainsString('/other', $response->streamedContent());
    }
}
