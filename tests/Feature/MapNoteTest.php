<?php

namespace Tests\Feature;

use App\Models\MapNote;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_write_resolve_reopen_and_delete_a_note(): void
    {
        $project = $this->project('NOTE-A');
        $viewer = User::factory()->viewer()->create(['name' => 'Recenzent']);
        $this->actingAs($viewer);

        $created = $this->postJson(route('projects.map-notes.store', $project), [
            'geometry_type' => 'point',
            'geometry' => [[44.4493, 18.6498]],
            'kind' => 'move',
            'body' => '  Pomjeriti ODO 7 bliže kućama.  ',
        ])->assertCreated()
            ->assertJsonPath('note.kind_label', 'Pomjeri')
            ->assertJsonPath('note.body', 'Pomjeriti ODO 7 bliže kućama.')
            ->assertJsonPath('note.author', 'Recenzent')
            ->assertJsonPath('note.status', 'open');
        $id = $created->json('note.id');

        $this->patchJson(route('projects.map-notes.update', [$project, $id]), ['status' => 'resolved'])
            ->assertOk()
            ->assertJsonPath('note.status', 'resolved')
            ->assertJsonPath('note.resolved_by', 'Recenzent');
        $this->assertNotNull(MapNote::find($id)->resolved_at);

        $this->patchJson(route('projects.map-notes.update', [$project, $id]), ['status' => 'open', 'body' => 'Novi tekst'])
            ->assertOk()
            ->assertJsonPath('note.status', 'open')
            ->assertJsonPath('note.resolved_by', null)
            ->assertJsonPath('note.body', 'Novi tekst');

        $this->getJson(route('projects.map-notes.index', $project))->assertOk()->assertJsonCount(1, 'notes');

        $this->deleteJson(route('projects.map-notes.destroy', [$project, $id]))->assertOk();
        $this->assertDatabaseMissing('map_notes', ['id' => $id]);
    }

    public function test_line_and_area_need_enough_points_and_valid_coordinates(): void
    {
        $project = $this->project('NOTE-B');
        $this->actingAs(User::factory()->designer()->create());
        $url = route('projects.map-notes.store', $project);

        $this->postJson($url, ['geometry_type' => 'line', 'geometry' => [[44.1, 18.1]], 'body' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('geometry');
        $this->postJson($url, ['geometry_type' => 'area', 'geometry' => [[44.1, 18.1], [44.2, 18.2]], 'body' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('geometry');
        $this->postJson($url, ['geometry_type' => 'point', 'geometry' => [[95, 18.1]], 'body' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('geometry.0.0');
        $this->postJson($url, ['geometry_type' => 'point', 'geometry' => [[44.1, 18.1]], 'body' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('body');

        $this->postJson($url, [
            'geometry_type' => 'area',
            'geometry' => [[44.1, 18.1], [44.2, 18.2], [44.1, 18.3]],
            'body' => 'Ove kuće prebaciti na ODO 5.',
        ])->assertCreated()->assertJsonPath('note.kind', 'general')->assertJsonCount(3, 'note.geometry');
    }

    public function test_note_of_another_project_cannot_be_changed_through_this_project(): void
    {
        $this->actingAs(User::factory()->viewer()->create());
        $own = $this->project('NOTE-C');
        $other = $this->project('NOTE-D');
        $note = $other->mapNotes()->create(['geometry_type' => 'point', 'geometry' => [[44.1, 18.1]], 'body' => 'Tuđa', 'status' => 'open']);

        $this->patchJson(route('projects.map-notes.update', [$own, $note]), ['status' => 'resolved'])->assertNotFound();
        $this->deleteJson(route('projects.map-notes.destroy', [$own, $note]))->assertNotFound();
        $this->getJson(route('projects.map-notes.index', $own))->assertOk()->assertJsonCount(0, 'notes');
    }

    public function test_guest_cannot_read_or_write_notes(): void
    {
        $project = $this->project('NOTE-E');
        auth()->logout();

        $this->getJson(route('projects.map-notes.index', $project))->assertUnauthorized();
        $this->postJson(route('projects.map-notes.store', $project), ['geometry_type' => 'point', 'geometry' => [[44.1, 18.1]], 'body' => 'x'])
            ->assertUnauthorized();
    }

    public function test_viewer_sees_the_notes_tool_on_the_project_map(): void
    {
        $project = $this->project('NOTE-F');
        $this->actingAs(User::factory()->viewer()->create());

        $this->get(route('map.dashboard', ['project' => $project->id]))
            ->assertOk()
            ->assertSee('id="notes-panel-btn"', false)
            ->assertSee('id="notes-panel"', false)
            ->assertSee('js/map/notes.js', false);
        // Without a chosen project there is nowhere to attach a note.
        $this->get(route('map.dashboard'))->assertOk()->assertDontSee('id="notes-panel-btn"', false);
    }

    public function test_deleting_a_project_removes_its_notes(): void
    {
        $project = $this->project('NOTE-G');
        $project->mapNotes()->create(['geometry_type' => 'point', 'geometry' => [[44.1, 18.1]], 'body' => 'x', 'status' => 'open']);

        $project->delete();

        $this->assertDatabaseCount('map_notes', 0);
    }

    private function project(string $code): Project
    {
        return Project::create(['name' => 'Projekat '.$code, 'code' => $code, 'location' => 'Banovići', 'status' => 'planning']);
    }
}
