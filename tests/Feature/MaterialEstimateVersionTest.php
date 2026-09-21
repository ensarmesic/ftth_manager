<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaterialEstimateVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_designer_can_snapshot_material_costs(): void
    {
        $project = Project::factory()->create();
        Material::create(['project_id' => $project->id, 'name' => 'Kabl', 'unit' => 'm', 'planned_quantity' => 100, 'used_quantity' => 40, 'unit_price' => 2.5]);
        $this->actingAs(User::factory()->designer()->create())
            ->post(route('materials.versions.store', $project), ['label' => 'Ponuda v1'])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('material_estimate_versions', ['project_id' => $project->id, 'label' => 'Ponuda v1', 'planned_total' => 250, 'used_total' => 100]);
        $this->assertCount(1, $project->materialEstimateVersions()->first()->items);
    }
}
