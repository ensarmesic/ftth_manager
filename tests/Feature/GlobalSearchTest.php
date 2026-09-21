<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\Odf;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_search_network_elements(): void
    {
        $project = Project::factory()->create(['name' => 'Projekat Lukavac', 'code' => 'LUK-77']);
        Odf::factory()->create(['project_id' => $project->id, 'name' => 'ODF Lukavac']);
        Cabinet::factory()->create(['project_id' => $project->id, 'name' => 'ODO Posebni 42']);
        House::factory()->create(['project_id' => $project->id, 'label' => 'KUCA-991', 'address' => 'Testna ulica 88']);

        $this->actingAs(User::factory()->viewer()->create())
            ->getJson(route('api.search', ['q' => 'Posebni']))
            ->assertOk()
            ->assertJsonPath('items.0.type', 'ODO')
            ->assertJsonPath('items.0.label', 'ODO Posebni 42');

        $this->getJson(route('api.search', ['q' => 'Testna ulica']))
            ->assertOk()->assertJsonFragment(['label' => 'KUCA-991']);
    }

    public function test_search_requires_authentication_and_a_meaningful_term(): void
    {
        $this->getJson(route('api.search', ['q' => 'x']))->assertOk()->assertExactJson(['items' => []]);
        auth()->logout();
        $this->get(route('api.search', ['q' => 'Lukavac']))->assertUnauthorized();
    }

    public function test_coordinates_produce_a_map_location_result(): void
    {
        $this->actingAs(User::factory()->viewer()->create())
            ->getJson(route('api.search', ['q' => '44.4493, 18.6498']))
            ->assertOk()
            ->assertJsonPath('items.0.type', 'Koordinate')
            ->assertJsonPath('items.0.label', '44.449300, 18.649800');
    }
}
