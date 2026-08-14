<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSearchAndOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_search_filters_the_complete_paginated_collection(): void
    {
        $this->actingAs(User::factory()->viewer()->create());
        Project::factory()->count(13)->create();
        Project::factory()->create(['name' => 'Poseban Lukavac', 'code' => 'LUK-UNIQUE']);

        $this->get(route('projects.index', ['q' => 'LUK-UNIQUE']))
            ->assertOk()
            ->assertSee('Poseban Lukavac')
            ->assertViewHas('projects', fn ($projects) => $projects->total() === 1);
    }

    public function test_house_search_matches_its_project_name(): void
    {
        $this->actingAs(User::factory()->viewer()->create());
        $target = Project::factory()->create(['name' => 'Mreža Ilidža']);
        $other = Project::factory()->create(['name' => 'Drugi projekat']);
        House::create(['project_id' => $target->id, 'label' => 'K-1', 'address' => 'Prva 1', 'status' => 'planned']);
        House::create(['project_id' => $other->id, 'label' => 'K-2', 'address' => 'Druga 2', 'status' => 'planned']);

        $this->get(route('houses.index', ['q' => 'Ilidža']))
            ->assertOk()
            ->assertSee('K-1')
            ->assertDontSee('K-2');
    }

    public function test_guided_project_creation_opens_the_new_project_on_the_map(): void
    {
        $this->actingAs(User::factory()->designer()->create());

        $response = $this->post(route('projects.store'), [
            'name' => 'Vođeni projekat',
            'code' => 'GUIDE-001',
            'location' => 'Sarajevo',
            'status' => 'planning',
            'next' => 'map',
        ]);

        $project = Project::where('code', 'GUIDE-001')->firstOrFail();
        $response->assertRedirect(route('map.dashboard', ['project' => $project->id]));
    }
}
