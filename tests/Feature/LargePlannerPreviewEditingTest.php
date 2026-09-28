<?php

namespace Tests\Feature;

use App\Models\GisSegment;
use App\Models\House;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LargePlannerPreviewEditingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
    }

    public function test_element_is_snapped_to_corridor_and_can_be_locked(): void
    {
        [$project, $task] = $this->previewTask();
        $user = User::factory()->designer()->create();

        $moved = $this->actingAs($user)->patchJson(route('projects.large-planner.preview.move', [$project, $task]), [
            'type' => 'odo', 'key' => 'odo-0001', 'point' => [43.8511, 18.4110],
        ])->assertOk();
        $this->assertEqualsWithDelta(43.85105, $moved->json('preview.odo_placement.odos.0.point.0'), 0.0001);

        $this->actingAs($user)->patchJson(route('projects.large-planner.preview.lock', [$project, $task]), [
            'type' => 'odo', 'key' => 'odo-0001', 'locked' => true,
        ])->assertOk()->assertJsonPath('preview.odo_placement.odos.0.locked', true);

        $this->actingAs($user)->patchJson(route('projects.large-planner.preview.move', [$project, $task]), [
            'type' => 'odo', 'key' => 'odo-0001', 'point' => [43.852, 18.412],
        ])->assertUnprocessable()->assertJsonPath('message', 'Zaključani element nije moguće pomjeriti.');
        $this->assertDatabaseCount('cabinets', 0);
    }

    public function test_house_assignment_checks_capacity_and_drop_distance(): void
    {
        [$project, $task, $house] = $this->previewTask();
        $user = User::factory()->designer()->create();

        $this->actingAs($user)->patchJson(route('projects.large-planner.preview.house', [$project, $task]), [
            'house_id' => $house->id, 'odo_key' => 'odo-0001',
        ])->assertOk()->assertJsonPath('preview.odo_placement.odos.0.occupancy', 1);

        $farHouse = House::factory()->create(['project_id' => $project->id, 'latitude' => 44.0, 'longitude' => 19.0]);
        $this->actingAs($user)->patchJson(route('projects.large-planner.preview.house', [$project, $task]), [
            'house_id' => $farHouse->id, 'odo_key' => 'odo-0001',
        ])->assertUnprocessable();
    }

    public function test_all_manual_preview_changes_can_be_reset_to_original_result(): void
    {
        [$project, $task] = $this->previewTask();
        $user = User::factory()->designer()->create();

        $this->actingAs($user)->patchJson(route('projects.large-planner.preview.move', [$project, $task]), [
            'type' => 'odo', 'key' => 'odo-0001', 'point' => [43.8511, 18.4110],
        ])->assertOk();
        Storage::assertExists($task->result_path.'.original');

        $this->actingAs($user)->postJson(route('projects.large-planner.preview.reset', [$project, $task]))
            ->assertOk()
            ->assertJsonPath('preview.odo_placement.odos.0.point', [43.85, 18.41]);

        Storage::assertMissing($task->result_path.'.original');
        $this->actingAs($user)->postJson(route('projects.large-planner.preview.reset', [$project, $task]))
            ->assertConflict();
    }

    public function test_multiple_houses_are_reassigned_atomically_with_capacity_checks(): void
    {
        [$project, $task, $firstHouse] = $this->previewTask();
        $secondHouse = House::factory()->create(['project_id' => $project->id, 'latitude' => 43.8505, 'longitude' => 18.4105]);
        $thirdHouse = House::factory()->create(['project_id' => $project->id, 'latitude' => 43.8506, 'longitude' => 18.4106]);
        $user = User::factory()->designer()->create();

        $this->actingAs($user)->patchJson(route('projects.large-planner.preview.houses', [$project, $task]), [
            'house_ids' => [$firstHouse->id, $secondHouse->id],
            'odo_key' => 'odo-0001',
        ])->assertOk()
            ->assertJsonPath('preview.odo_placement.odos.0.occupancy', 2);

        $beforeFailure = Storage::get($task->result_path);
        $this->actingAs($user)->patchJson(route('projects.large-planner.preview.houses', [$project, $task]), [
            'house_ids' => [$thirdHouse->id],
            'odo_key' => 'odo-0001',
        ])->assertUnprocessable();
        $this->assertSame($beforeFailure, Storage::get($task->result_path));
    }

    public function test_two_completed_variants_can_be_compared(): void
    {
        [$project, $first] = $this->previewTask();
        [, $second] = $this->previewTask($project, 'second.json');

        $this->actingAs(User::factory()->viewer()->create())->getJson(route('projects.large-planner.preview.compare', [
            $project, 'first_task_id' => $first->id, 'second_task_id' => $second->id,
        ]))->assertOk()->assertJsonPath('first.odos', 1)->assertJsonPath('second.problems', 0);
    }

    public function test_preview_can_be_exported_with_network_elements_and_warnings(): void
    {
        [$project, $task] = $this->previewTask();

        $response = $this->actingAs(User::factory()->designer()->create())
            ->get(route('projects.large-planner.preview.export', [$project, $task]))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('Tip;Oznaka;Roditelj', $content);
        $this->assertStringContainsString('ODO;ODO-P-0001', $content);
    }

    public function test_preview_from_another_project_is_not_visible(): void
    {
        [$project] = $this->previewTask();
        [$other, $task] = $this->previewTask();

        $this->actingAs(User::factory()->viewer()->create())
            ->getJson(route('projects.large-planner.preview.show', [$project, $task]))
            ->assertNotFound();
        $this->assertNotSame($project->id, $other->id);
    }

    public function test_preview_export_from_another_project_is_not_accessible(): void
    {
        [$project] = $this->previewTask();
        [, $task] = $this->previewTask();

        $this->actingAs(User::factory()->designer()->create())
            ->get(route('projects.large-planner.preview.export', [$project, $task]))
            ->assertNotFound();
    }

    private function previewTask(?Project $project = null, string $name = 'preview.json'): array
    {
        $project ??= Project::factory()->create(['planning_mode' => 'large_auto']);
        if (! $project->largePlannerSetting()->exists()) {
            $project->largePlannerSetting()->create(['odo_capacity' => 2, 'max_drop_length_m' => 150, 'fiber_reserve_percent' => 20, 'optimization_goal' => 'weighted']);
            GisSegment::create(['project_id' => $project->id, 'name' => 'Koridor', 'source' => 'test', 'segment_type' => 'corridor', 'is_allowed' => true, 'planning_corridor_type' => 'secondary', 'length_m' => 500, 'path' => [[43.85, 18.41], [43.855, 18.415]]]);
        }
        $house = House::factory()->create(['project_id' => $project->id, 'latitude' => 43.851, 'longitude' => 18.411]);
        $preview = [
            'project_id' => $project->id,
            'clustering' => ['unroutable_houses' => []],
            'odo_placement' => ['odos' => [['key' => 'odo-0001', 'provisional_name' => 'ODO-P-0001', 'point' => [43.85, 18.41], 'corridor_id' => 1, 'component' => 0, 'capacity' => 2, 'occupancy' => 0, 'house_ids' => []]], 'warnings' => []],
            'odf_placement' => ['enabled' => false, 'odfs' => [], 'primary_routes' => [], 'warnings' => []],
            'routes' => ['secondary_routes' => [], 'drop_routes' => [], 'warnings' => [], 'summary' => ['secondary_length_m' => 0, 'drop_length_m' => 0]],
            'warnings' => ['items' => [], 'summary' => ['total' => 0], 'can_confirm' => true],
        ];
        $path = "background-tasks/{$project->id}/{$name}";
        Storage::put($path, json_encode($preview, JSON_THROW_ON_ERROR));
        $task = $project->backgroundTasks()->create(['type' => 'large_plan', 'status' => 'completed', 'progress' => 100, 'result_path' => $path, 'result_name' => $name]);

        return [$project, $task, $house];
    }
}
