<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_requires_authentication(): void
    {
        auth()->logout();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_dashboard_renders_operational_network_summary(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['name' => 'Banovici FTTH']);
        $odf = Odf::factory()->create(['project_id' => $project->id]);
        $cabinet = Cabinet::factory()->create([
            'project_id' => $project->id,
            'odf_id' => $odf->id,
            'splitter_count' => 3,
            'ports_per_splitter' => 4,
        ]);
        House::factory()->create(['project_id' => $project->id, 'cabinet_id' => $cabinet->id]);
        House::factory()->create(['project_id' => $project->id, 'cabinet_id' => null]);
        NetworkRoute::factory()->create([
            'project_id' => $project->id,
            'route_type' => 'distribution',
            'duct_length_m' => 1500,
            'microduct_type' => null,
            'fiber_count' => null,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('AKTIVNI PROJEKAT')
            ->assertSee('Banovici FTTH')
            ->assertSee('1.50')
            ->assertSee('2 problema')
            ->assertSee('1 / 12 portova');
    }

    public function test_legacy_map_url_redirects_to_map_workspace(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('map.index'))
            ->assertRedirect(route('map.dashboard'));
    }
}
