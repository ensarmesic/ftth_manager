<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_lookup_limits_and_scopes_large_select_results(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        Cabinet::factory()->create(['project_id' => $project->id, 'name' => 'Traženi ODO']);
        Cabinet::factory()->create(['project_id' => $other->id, 'name' => 'Traženi drugi']);
        $this->actingAs(User::factory()->viewer()->create())->getJson(route('api.lookup', ['type' => 'cabinets', 'project' => $project->id, 'q' => 'Traženi']))
            ->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.text', 'Traženi ODO');
    }
}
