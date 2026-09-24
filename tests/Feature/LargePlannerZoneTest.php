<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LargePlannerZoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_large_project_zone_is_created_as_draft_with_polygon_geometry(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $geometry = [[43.85, 18.41], [43.86, 18.41], [43.86, 18.42]];

        $zone = $this->postJson(route('projects.large-planner.zones.store', $project), [
            'name' => 'Zona 1',
            'geometry' => $geometry,
        ])->assertCreated()->assertJsonPath('zone.status', 'draft')->json('zone');

        $this->assertDatabaseHas('large_planner_zones', [
            'id' => $zone['id'],
            'project_id' => $project->id,
            'name' => 'Zona 1',
            'status' => 'draft',
        ]);
        $this->getJson(route('api.projects.map-data', $project))
            ->assertOk()
            ->assertJsonPath('large_planner_zones.0.geometry', $geometry);
    }

    public function test_zone_name_must_be_unique_inside_its_project(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $payload = ['name' => 'Zona 1', 'geometry' => [[43.85, 18.41], [43.86, 18.41], [43.86, 18.42]]];

        $this->postJson(route('projects.large-planner.zones.store', $project), $payload)->assertCreated();
        $this->postJson(route('projects.large-planner.zones.store', $project), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_zone_from_another_project_cannot_be_changed_or_deleted(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $other = Project::factory()->create(['planning_mode' => 'large_auto']);
        $zone = $other->largePlannerZones()->create([
            'name' => 'Tuđa zona',
            'geometry' => [[43.85, 18.41], [43.86, 18.41], [43.86, 18.42]],
        ]);

        $this->patchJson(route('projects.large-planner.zones.update', [$project, $zone]), [
            'name' => 'Promjena',
            'geometry' => $zone->geometry,
        ])->assertNotFound();
        $this->deleteJson(route('projects.large-planner.zones.destroy', [$project, $zone]))->assertNotFound();
    }

    public function test_locked_zone_cannot_be_edited_or_deleted(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $zone = $project->largePlannerZones()->create([
            'name' => 'Zaključana zona',
            'status' => 'locked',
            'geometry' => [[43.85, 18.41], [43.86, 18.41], [43.86, 18.42]],
        ]);

        $this->patchJson(route('projects.large-planner.zones.update', [$project, $zone]), [
            'name' => 'Promjena',
            'geometry' => $zone->geometry,
        ])->assertStatus(423);
        $this->deleteJson(route('projects.large-planner.zones.destroy', [$project, $zone]))->assertStatus(423);
    }

    public function test_standard_project_cannot_manage_large_planner_zones(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'standard']);

        $this->postJson(route('projects.large-planner.zones.store', $project), [
            'name' => 'Zona 1',
            'geometry' => [[43.85, 18.41], [43.86, 18.41], [43.86, 18.42]],
        ])->assertNotFound();
    }

    public function test_houses_can_be_split_into_deterministic_initial_zones(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $now = now();
        $rows = [];
        for ($index = 0; $index < 450; $index++) {
            $rows[] = [
                'project_id' => $project->id,
                'label' => 'K-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'latitude' => 43.85 + (intdiv($index, 30) * 0.0001),
                'longitude' => 18.41 + (($index % 30) * 0.0001),
                'status' => 'planned',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        House::insert($rows);

        $response = $this->postJson(route('projects.large-planner.zones.auto-split', $project), [
            'target_size' => 200,
        ])->assertCreated()->assertJsonPath('created', 4);

        $zones = collect($response->json('zones'));
        $this->assertSame(['Zona 1', 'Zona 2', 'Zona 3', 'Zona 4'], $zones->pluck('name')->all());
        $this->assertTrue($zones->every(fn (array $zone) => count($zone['geometry']) === 4 && $zone['status'] === 'draft'));
        $this->assertSame(450, House::where('project_id', $project->id)->whereNotNull('large_planner_zone_id')->count());
        $this->assertLessThanOrEqual(200, House::where('project_id', $project->id)
            ->selectRaw('large_planner_zone_id, count(*) as total')->groupBy('large_planner_zone_id')->get()->max('total'));

        $this->postJson(route('projects.large-planner.zones.auto-split', $project), [
            'target_size' => 200,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('large_planner_zones', 4);
    }

    public function test_house_can_belong_to_only_one_zone_and_cannot_leave_a_locked_zone(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $first = $project->largePlannerZones()->create(['name' => 'Zona A', 'geometry' => [[43.85, 18.41], [43.86, 18.41], [43.86, 18.42]]]);
        $second = $project->largePlannerZones()->create(['name' => 'Zona B', 'geometry' => [[43.85, 18.42], [43.86, 18.42], [43.86, 18.43]]]);
        $house = House::factory()->create(['project_id' => $project->id]);

        $this->postJson(route('projects.large-planner.zones.houses.assign', [$project, $first]), ['house_ids' => [$house->id]])
            ->assertOk();
        $this->postJson(route('projects.large-planner.zones.houses.assign', [$project, $second]), ['house_ids' => [$house->id]])
            ->assertOk();
        $this->assertSame($second->id, $house->fresh()->large_planner_zone_id);

        $second->update(['status' => 'locked']);
        $this->postJson(route('projects.large-planner.zones.houses.assign', [$project, $first]), ['house_ids' => [$house->id]])
            ->assertUnprocessable();
        $this->assertSame($second->id, $house->fresh()->large_planner_zone_id);
    }

    public function test_zone_status_transitions_and_partial_replan_preserve_other_zones(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $geometry = [[43.85, 18.41], [43.86, 18.41], [43.86, 18.42]];
        $selected = $project->largePlannerZones()->create(['name' => 'Odabrana', 'geometry' => $geometry, 'status' => 'calculated']);
        $accepted = $project->largePlannerZones()->create(['name' => 'Prihvaćena', 'geometry' => $geometry, 'status' => 'accepted']);
        $locked = $project->largePlannerZones()->create(['name' => 'Zaključana', 'geometry' => $geometry, 'status' => 'accepted']);

        $this->patchJson(route('projects.large-planner.zones.status', [$project, $locked]), ['status' => 'locked'])
            ->assertOk()->assertJsonPath('zone.status', 'locked');

        $this->postJson(route('projects.large-planner.zones.prepare-replan', $project), ['zone_ids' => [$selected->id]])
            ->assertOk()->assertJsonPath('zone_ids.0', $selected->id);
        $this->assertSame('draft', $selected->fresh()->status);
        $this->assertSame('accepted', $accepted->fresh()->status);
        $this->assertSame('locked', $locked->fresh()->status);

        $this->postJson(route('projects.large-planner.zones.prepare-replan', $project), ['zone_ids' => [$locked->id]])
            ->assertUnprocessable();
        $this->assertSame('locked', $locked->fresh()->status);
    }

    public function test_manual_zone_assigns_only_unassigned_houses_inside_its_polygon(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $inside = House::factory()->create(['project_id' => $project->id, 'latitude' => 43.855, 'longitude' => 18.415]);
        $outside = House::factory()->create(['project_id' => $project->id, 'latitude' => 44.1, 'longitude' => 19.1]);

        $zoneId = $this->postJson(route('projects.large-planner.zones.store', $project), [
            'name' => 'Ručna zona',
            'geometry' => [[43.85, 18.41], [43.86, 18.41], [43.86, 18.42], [43.85, 18.42]],
        ])->assertCreated()->assertJsonPath('zone.houses_count', 1)->json('zone.id');

        $this->assertSame($zoneId, $inside->fresh()->large_planner_zone_id);
        $this->assertNull($outside->fresh()->large_planner_zone_id);
    }
}
