<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\Odf;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapDataApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_receives_only_the_requested_project_map_data(): void
    {
        $project = Project::factory()->create();
        $otherProject = Project::factory()->create();
        $odf = Odf::factory()->create(['project_id' => $project->id, 'latitude' => 44.45, 'longitude' => 18.65]);
        $cabinet = Cabinet::factory()->create([
            'project_id' => $project->id, 'odf_id' => $odf->id, 'latitude' => 44.451, 'longitude' => 18.651,
        ]);
        House::factory()->create([
            'project_id' => $project->id, 'cabinet_id' => $cabinet->id, 'latitude' => 44.452, 'longitude' => 18.652,
        ]);
        House::factory()->create([
            'project_id' => $otherProject->id, 'latitude' => 44.5, 'longitude' => 18.7,
        ]);

        $this->actingAs(User::factory()->viewer()->create())
            ->getJson(route('api.projects.map-data', $project))
            ->assertOk()
            ->assertJsonCount(1, 'odfs')
            ->assertJsonCount(1, 'cabinets')
            ->assertJsonCount(1, 'houses')
            ->assertJsonPath('odfs.0.project_id', $project->id)
            ->assertJsonPath('cabinets.0.project_id', $project->id)
            ->assertJsonPath('houses.0.project_id', $project->id)
            ->assertHeader('X-Map-Elements')
            ->assertHeader('X-Map-Payload-Bytes')
            ->assertHeader('X-Map-Build-Ms')
            ->assertJsonStructure(['drafts', 'odfs', 'cabinets', 'houses', 'routes', 'gis_segments', 'gis_restricted_areas', 'appendix_items']);
    }

    public function test_map_data_api_requires_authentication(): void
    {
        $project = Project::factory()->create();
        auth()->logout();

        $this->getJson(route('api.projects.map-data', $project))->assertUnauthorized();
    }

    public function test_map_data_can_be_limited_to_viewport_bbox(): void
    {
        $project = Project::factory()->create();
        House::factory()->create(['project_id' => $project->id, 'latitude' => 44.45, 'longitude' => 18.65]);
        House::factory()->create(['project_id' => $project->id, 'latitude' => 45.50, 'longitude' => 19.70]);
        $this->actingAs(User::factory()->viewer()->create())->getJson(route('api.projects.map-data', [$project, 'bbox' => '44.0,18.0,45.0,19.0']))
            ->assertOk()->assertJsonCount(1, 'houses');
    }
}
