<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\GisSegment;
use App\Models\House;
use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the relationship/deletion behaviour that only shows up with real,
 * connected data — the class of bug plain unit tests miss.
 */
class DataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_odf_detaches_connected_elements_without_losing_them(): void
    {
        $project = Project::factory()->create();
        $odf = Odf::factory()->for($project)->create();
        $branch = NetworkBranch::factory()->for($project)->create(['odf_id' => $odf->id]);
        $cabinet = Cabinet::factory()->for($project)->create(['odf_id' => $odf->id, 'branch_id' => $branch->id]);
        $house = House::factory()->for($project)->for($cabinet)->create();
        $linkedRoute = NetworkRoute::factory()->for($project)->create(['odf_id' => $odf->id, 'cabinet_id' => $cabinet->id]);
        $feederRoute = NetworkRoute::factory()->for($project)->create(['odf_id' => $odf->id, 'from_type' => 'odf', 'from_id' => $odf->id]);

        $this->deleteJson(route('odfs.delete', $odf->id))->assertOk();

        // ODF gone, but every dependent survives with its ODF link cleared.
        $this->assertDatabaseMissing('odfs', ['id' => $odf->id]);
        $this->assertDatabaseHas('cabinets', ['id' => $cabinet->id, 'odf_id' => null]);
        $this->assertDatabaseHas('houses', ['id' => $house->id, 'cabinet_id' => $cabinet->id]);
        $this->assertDatabaseHas('network_branches', ['id' => $branch->id, 'odf_id' => null]);
        $this->assertDatabaseHas('routes', ['id' => $linkedRoute->id, 'odf_id' => null]);
        $this->assertDatabaseHas('routes', ['id' => $feederRoute->id, 'odf_id' => null, 'from_type' => null, 'from_id' => null]);
    }

    public function test_odo_parent_can_be_changed_but_cycles_are_rejected(): void
    {
        $project = Project::factory()->create();
        $parent = Cabinet::factory()->for($project)->create(['name' => 'FTTH 1-1']);
        $child = Cabinet::factory()->for($project)->create(['name' => 'FTTH 1-1.1']);

        $payload = fn (Cabinet $c, ?int $parentId) => [
            'project_id' => $project->id,
            'parent_cabinet_id' => $parentId,
            'name' => $c->name,
            'address' => $c->address,
            'splitter_count' => 1,
            'ports_per_splitter' => 4,
        ];

        // Valid: child is fed from parent.
        $this->from(route('cabinets.index'))
            ->patch(route('cabinets.update', $child->id), $payload($child, $parent->id))
            ->assertRedirect();
        $this->assertDatabaseHas('cabinets', ['id' => $child->id, 'parent_cabinet_id' => $parent->id]);

        // Cycle: parent cannot now be fed from its own child.
        $this->from(route('cabinets.index'))
            ->patch(route('cabinets.update', $parent->id), $payload($parent, $child->id))
            ->assertSessionHasErrors('parent_cabinet_id');
        $this->assertDatabaseHas('cabinets', ['id' => $parent->id, 'parent_cabinet_id' => null]);

        // Self-parenting is rejected too.
        $this->from(route('cabinets.index'))
            ->patch(route('cabinets.update', $parent->id), $payload($parent, $parent->id))
            ->assertSessionHasErrors('parent_cabinet_id');
    }

    public function test_odo_at_capacity_twelve_rejects_the_thirteenth_house(): void
    {
        $project = Project::factory()->create();
        $cabinet = Cabinet::factory()->for($project)->create(['splitter_count' => 3, 'ports_per_splitter' => 4]); // capacity 12
        $houses = House::factory()->for($project)->count(13)->create(['cabinet_id' => null]);

        // First 12 connect fine.
        $this->postJson(route('cabinets.houses.connect', $cabinet->id), [
            'house_ids' => $houses->take(12)->pluck('id')->all(),
        ])->assertOk();
        $this->assertSame(12, $cabinet->houses()->count());

        // The 13th is refused — capacity is hard-capped at 12.
        $this->postJson(route('cabinets.houses.connect', $cabinet->id), [
            'house_ids' => [$houses->last()->id],
        ])->assertStatus(422);
        $this->assertSame(12, $cabinet->houses()->count());
        $this->assertNull($houses->last()->fresh()->cabinet_id);
    }

    public function test_auto_route_returns_422_when_graph_is_broken(): void
    {
        $project = Project::factory()->create();
        // Two disconnected road segments (far apart, no shared node): the start
        // snaps to one, the destination to the other, and Dijkstra finds no path.
        GisSegment::create([
            'project_id' => $project->id, 'name' => 'Cesta A', 'segment_type' => 'road',
            'source' => 'test', 'is_allowed' => true, 'length_m' => 0,
            'path' => [[44.4493, 18.6498], [44.4503, 18.6498]],
        ]);
        GisSegment::create([
            'project_id' => $project->id, 'name' => 'Cesta B', 'segment_type' => 'road',
            'source' => 'test', 'is_allowed' => true, 'length_m' => 0,
            'path' => [[44.5000, 18.7000], [44.5010, 18.7000]],
        ]);

        $this->getJson(route('map.auto-route', [
            'project_id' => $project->id,
            'from_lat' => 44.4498, 'from_lng' => 18.6498,
            'to_lat' => 44.5005, 'to_lng' => 18.7000,
        ]))->assertStatus(422)
            ->assertJsonFragment(['message' => 'Nema dovoljno postojece trase/GIS grafa za automatsku rutu.']);
    }
}
