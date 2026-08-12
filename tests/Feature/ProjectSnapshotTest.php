<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\NetworkRoute;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_snapshot_restores_project_data(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->create();
        $house = House::factory()->create(['project_id' => $project->id, 'label' => 'Originalna kuća']);
        NetworkRoute::factory()->create(['project_id' => $project->id, 'name' => 'Originalna trasa']);

        $snapshotId = $this->postJson(route('projects.snapshots.store', $project), ['label' => 'Prije uvoza'])
            ->assertCreated()->json('snapshot.id');

        $house->update(['label' => 'Promijenjena kuća']);
        NetworkRoute::query()->where('project_id', $project->id)->delete();

        $this->postJson(route('projects.snapshots.restore', [$project, $snapshotId]))->assertOk();

        $this->assertDatabaseHas('houses', ['id' => $house->id, 'label' => 'Originalna kuća']);
        $this->assertDatabaseHas('routes', ['project_id' => $project->id, 'name' => 'Originalna trasa']);
    }

    public function test_snapshot_from_another_project_cannot_be_restored(): void
    {
        $this->actingAs(User::factory()->create());
        $first = Project::factory()->create();
        $second = Project::factory()->create();
        $snapshotId = $this->postJson(route('projects.snapshots.store', $first))->assertCreated()->json('snapshot.id');

        $this->postJson(route('projects.snapshots.restore', [$second, $snapshotId]))->assertNotFound();
    }

    public function test_project_snapshot_can_be_downloaded_as_versioned_json(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->create(['code' => 'JSON-TEST']);
        House::factory()->create(['project_id' => $project->id]);
        $snapshotId = $this->postJson(route('projects.snapshots.store', $project), ['label' => 'Arhiva'])->assertCreated()->json('snapshot.id');

        $response = $this->get(route('projects.snapshots.download', [$project, $snapshotId]))->assertOk();

        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $payload = json_decode($response->getContent(), true);
        $this->assertSame('ftth-manager-project-snapshot', $payload['format']);
        $this->assertSame(1, $payload['version']);
        $this->assertSame('Arhiva', $payload['snapshot']['label']);
        $this->assertCount(1, $payload['data']['houses']);
    }
}
