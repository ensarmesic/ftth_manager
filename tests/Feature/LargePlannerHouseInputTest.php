<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class LargePlannerHouseInputTest extends TestCase
{
    use RefreshDatabase;

    public function test_house_can_be_added_manually_to_a_large_project(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);

        $this->postJson(route('projects.large-planner.houses.store', $project), [
            'label' => 'K-001',
            'address' => 'Testna 1',
            'latitude' => 43.8563,
            'longitude' => 18.4131,
        ])->assertCreated()->assertJsonPath('house.label', 'K-001');

        $this->assertDatabaseHas('houses', [
            'project_id' => $project->id,
            'label' => 'K-001',
            'status' => 'planned',
            'cabinet_id' => null,
        ]);
    }

    public function test_csv_import_creates_valid_houses_in_one_batch(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $csv = "label;address;latitude;longitude\nK-001;Prva 1;43.8563;18.4131\nK-002;Druga 2;43.8564;18.4132\n";

        $this->post(route('projects.large-planner.houses.import', $project), [
            'houses_file' => UploadedFile::fake()->createWithContent('kuce.csv', $csv),
        ])->assertRedirect()->assertSessionHas('success');

        $houses = House::where('project_id', $project->id)->orderBy('label')->get();
        $this->assertCount(2, $houses);
        $this->assertNotNull($houses[0]->import_batch);
        $this->assertSame($houses[0]->import_batch, $houses[1]->import_batch);
    }

    public function test_invalid_csv_row_prevents_partial_import(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $csv = "label,address,latitude,longitude\nK-001,Prva 1,43.8563,18.4131\nK-002,Druga 2,999,18.4132\n";

        $this->post(route('projects.large-planner.houses.import', $project), [
            'houses_file' => UploadedFile::fake()->createWithContent('kuce.csv', $csv),
        ])->assertSessionHasErrors('houses_file');

        $this->assertSame(0, House::where('project_id', $project->id)->count());
    }

    public function test_duplicate_labels_are_rejected(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        House::factory()->create(['project_id' => $project->id, 'label' => 'K-001']);

        $this->postJson(route('projects.large-planner.houses.store', $project), [
            'label' => 'K-001',
            'latitude' => 43.8563,
            'longitude' => 18.4131,
        ])->assertUnprocessable()->assertJsonValidationErrors('label');
    }

    public function test_standard_project_cannot_use_large_project_house_input(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'standard']);

        $this->postJson(route('projects.large-planner.houses.store', $project), [
            'label' => 'K-001',
            'latitude' => 43.8563,
            'longitude' => 18.4131,
        ])->assertNotFound();

        $this->assertDatabaseCount('houses', 0);
    }
}
