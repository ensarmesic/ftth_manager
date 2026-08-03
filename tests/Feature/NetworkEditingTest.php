<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NetworkEditingTest extends TestCase
{
    use RefreshDatabase;

    public function test_network_elements_can_be_repositioned_with_valid_coordinates(): void
    {
        $project = Project::factory()->create();
        $odf = Odf::factory()->for($project)->create();
        $cabinet = Cabinet::factory()->for($project)->create();
        $house = House::factory()->for($project)->create();

        foreach ([
            [route('odfs.position.update', $odf), $odf],
            [route('cabinets.position.update', $cabinet), $cabinet],
            [route('houses.position.update', $house), $house],
        ] as $index => [$url, $element]) {
            $latitude = 44.4500 + ($index * 0.001);
            $longitude = 18.6500 + ($index * 0.001);

            $this->patchJson($url, [
                'latitude' => $latitude,
                'longitude' => $longitude,
            ])->assertOk()
                ->assertJsonPath('latitude', $latitude)
                ->assertJsonPath('longitude', $longitude);

            $element->refresh();
            $this->assertEqualsWithDelta($latitude, (float) $element->latitude, 0.0000001);
            $this->assertEqualsWithDelta($longitude, (float) $element->longitude, 0.0000001);
        }
    }

    public function test_route_can_be_split_and_both_segments_keep_continuous_geometry(): void
    {
        $project = Project::factory()->create();
        $route = NetworkRoute::factory()->for($project)->create([
            'name' => 'Trasa za podjelu',
            'path' => [
                [44.4490, 18.6490],
                [44.4500, 18.6500],
                [44.4510, 18.6510],
            ],
        ]);

        $response = $this->postJson(route('routes.split', $route), [
            'lat' => 44.4505,
            'lng' => 18.6505,
        ])->assertOk()
            ->assertJsonPath('first.id', $route->id)
            ->assertJsonPath('second.name', 'Trasa za podjelu-B');

        $first = $route->fresh();
        $second = NetworkRoute::findOrFail($response->json('second.id'));

        $this->assertSame($project->id, $second->project_id);
        $this->assertCount(3, $first->path);
        $this->assertCount(2, $second->path);
        $this->assertSame($first->path[array_key_last($first->path)], $second->path[0]);
        $this->assertGreaterThan(0, $first->duct_length_m);
        $this->assertGreaterThan(0, $second->duct_length_m);
    }

    public function test_map_ignores_a_nonexistent_project_filter(): void
    {
        $this->get(route('map.dashboard', ['project' => 999999]))
            ->assertOk()
            ->assertViewHas('activeProjectId', null)
            ->assertSee('Odaberi projekat');
    }
}
