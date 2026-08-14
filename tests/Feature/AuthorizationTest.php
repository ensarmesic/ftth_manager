<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_permission_matrix_is_explicit(): void
    {
        $administrator = User::factory()->administrator()->create();
        $designer = User::factory()->designer()->create();
        $field = User::factory()->field()->create();
        $viewer = User::factory()->viewer()->create();

        $this->assertTrue($administrator->hasPermission('system.manage'));
        $this->assertTrue($administrator->hasPermission('destructive'));
        $this->assertTrue($designer->hasPermission('project.edit'));
        $this->assertTrue($designer->hasPermission('project.export'));
        $this->assertFalse($designer->hasPermission('destructive'));
        $this->assertTrue($field->hasPermission('field.capture'));
        $this->assertFalse($field->hasPermission('project.edit'));
        $this->assertTrue($viewer->hasPermission('project.view'));
        $this->assertFalse($viewer->hasPermission('project.export'));
    }

    public function test_viewer_can_read_but_cannot_modify_or_export_projects(): void
    {
        $project = $this->project();
        $this->actingAs(User::factory()->viewer()->create());

        $this->get(route('projects.index'))
            ->assertOk()
            ->assertDontSee('action="'.route('projects.restore').'"', false)
            ->assertDontSee('action="'.route('projects.store').'"', false)
            ->assertDontSee('data-dxf-export="'.route('projects.dxf', $project).'"', false)
            ->assertDontSee('href="'.route('settings.index').'"', false);
        $this->post(route('projects.store'), $this->projectPayload('VIEW-NEW'))->assertForbidden();
        $this->get(route('projects.geojson', $project))->assertForbidden();
        $this->delete(route('projects.delete', $project))->assertForbidden();
        $this->get(route('map.dashboard', ['project' => $project->id]))
            ->assertOk()
            ->assertDontSee('id="survey-panel-btn"', false)
            ->assertDontSee('Nova GPS tačka');
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_designer_can_edit_export_and_backup_but_cannot_delete_or_restore(): void
    {
        $project = $this->project();
        $this->actingAs(User::factory()->designer()->create());

        $this->post(route('projects.store'), $this->projectPayload('DESIGN-NEW'))->assertRedirect();
        $this->get(route('projects.geojson', $project))->assertOk();
        $this->get(route('projects.backup', $project))->assertOk();
        $this->get(route('projects.index'))
            ->assertOk()
            ->assertSee('Novi projekat')
            ->assertSee('data-dxf-export', false)
            ->assertSee('Backup')
            ->assertDontSee('Vrati backup')
            ->assertDontSee('data-confirm-name="'.$project->name.'"', false);
        $this->delete(route('projects.delete', $project))->assertForbidden();
        $this->post(route('projects.restore'))->assertForbidden();
    }

    public function test_field_role_can_capture_points_but_cannot_use_designer_actions(): void
    {
        $project = $this->project();
        $this->actingAs(User::factory()->field()->create());

        $this->postJson(route('projects.field-points.store', $project), [
            'session_uuid' => (string) Str::uuid(),
            'latitude' => 43.8563,
            'longitude' => 18.4131,
            'kind' => 'cabinet',
            'code' => 'TEREN-001',
        ])->assertCreated();

        $this->post(route('projects.store'), $this->projectPayload('FIELD-NEW'))->assertForbidden();
        $this->get(route('projects.backup', $project))->assertForbidden();

        $this->get(route('map.dashboard', ['project' => $project->id]))
            ->assertOk()
            ->assertSee('map-readonly', false)
            ->assertSee('Nova GPS tačka')
            ->assertDontSee('Uvoz geodetskog TXT fajla');
    }

    public function test_only_administrator_can_access_system_settings_and_destructive_routes(): void
    {
        $project = $this->project();

        foreach (['designer', 'field', 'viewer'] as $state) {
            $this->actingAs(User::factory()->{$state}()->create());
            $this->get(route('system.health'))->assertForbidden();
            $this->get(route('settings.index'))->assertForbidden();
        }

        $this->actingAs(User::factory()->administrator()->create());
        $this->getJson(route('system.health'))->assertOk();
        $this->get(route('settings.index'))->assertOk();
        $this->delete(route('projects.delete', $project))->assertRedirect();
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    private function project(): Project
    {
        return Project::create($this->projectPayload('AUTH-'.Str::random(8)));
    }

    private function projectPayload(string $code): array
    {
        return [
            'name' => 'Projekt dozvola',
            'code' => $code,
            'location' => 'Sarajevo',
            'status' => 'planning',
        ];
    }
}
