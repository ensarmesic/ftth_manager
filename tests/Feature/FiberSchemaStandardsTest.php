<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Models\User;
use App\Services\FiberPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiberSchemaStandardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_optical_path_uses_the_complete_route_from_odf_to_child_cabinet(): void
    {
        $project = Project::factory()->create(['power_budget_confirmed' => true]);
        $odf = Odf::factory()->create(['project_id' => $project->id]);
        $rootRoute = NetworkRoute::factory()->create(['project_id' => $project->id, 'odf_id' => $odf->id, 'fiber_length_m' => 1000, 'duct_length_m' => 1000]);
        $rootBranch = NetworkBranch::factory()->create(['project_id' => $project->id, 'odf_id' => $odf->id, 'route_id' => $rootRoute->id]);
        $parent = Cabinet::factory()->create(['project_id' => $project->id, 'odf_id' => $odf->id, 'branch_id' => $rootBranch->id]);
        $childRoute = NetworkRoute::factory()->create([
            'project_id' => $project->id, 'odf_id' => $odf->id, 'from_type' => 'cabinet', 'from_id' => $parent->id,
            'fiber_length_m' => 500, 'duct_length_m' => 500,
        ]);
        $childBranch = NetworkBranch::factory()->create(['project_id' => $project->id, 'odf_id' => $odf->id, 'route_id' => $childRoute->id]);
        $child = Cabinet::factory()->create(['project_id' => $project->id, 'odf_id' => $odf->id, 'parent_cabinet_id' => $parent->id, 'branch_id' => $childBranch->id]);

        $connection = app(FiberPlanService::class)->build($project)['connections']->firstWhere('cabinet_id', $child->id);

        $this->assertSame(1.5, $connection['route_km']);
    }

    public function test_fiber_schema_can_be_scoped_to_one_project(): void
    {
        $this->actingAs(User::factory()->viewer()->create());
        $selected = Project::factory()->create(['name' => 'Odabrana fiber šema']);
        Project::factory()->create(['name' => 'Druga fiber šema']);

        $this->get(route('fiber-schema.index', ['project' => $selected->id]))
            ->assertOk()
            ->assertViewHas('projects', fn ($projects) => $projects->count() === 1 && $projects->first()->is($selected));
    }

    public function test_fiber_schema_defaults_to_exactly_one_project(): void
    {
        $this->actingAs(User::factory()->viewer()->create());
        Project::factory()->create(['name' => 'B projekat']);
        $first = Project::factory()->create(['name' => 'A projekat']);

        $this->get(route('fiber-schema.index'))
            ->assertOk()
            ->assertViewHas('selectedProjectId', $first->id)
            ->assertViewHas('projects', fn ($projects) => $projects->count() === 1 && $projects->first()->is($first));
    }

    public function test_path_beyond_active_profile_distance_is_reported(): void
    {
        $project = Project::factory()->create(['pon_profile' => 'xgs_n1']);
        $odf = Odf::factory()->create(['project_id' => $project->id]);
        $route = NetworkRoute::factory()->create(['project_id' => $project->id, 'odf_id' => $odf->id, 'fiber_length_m' => 21000]);
        $branch = NetworkBranch::factory()->create(['project_id' => $project->id, 'odf_id' => $odf->id, 'route_id' => $route->id]);
        Cabinet::factory()->create(['project_id' => $project->id, 'odf_id' => $odf->id, 'branch_id' => $branch->id]);

        $plan = app(FiberPlanService::class)->build($project->fresh());

        $this->assertTrue(collect($plan['issues'])->contains(
            fn (array $issue): bool => $issue['level'] === 'error' && str_contains($issue['message'], 'iznad DD20'),
        ));
    }

    public function test_all_fiber_exports_identify_the_same_plan_revision(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $project = Project::factory()->create();
        $odf = Odf::factory()->create(['project_id' => $project->id]);
        $route = NetworkRoute::factory()->create(['project_id' => $project->id, 'odf_id' => $odf->id, 'fiber_length_m' => 750]);
        $branch = NetworkBranch::factory()->create(['project_id' => $project->id, 'odf_id' => $odf->id, 'route_id' => $route->id]);
        Cabinet::factory()->create(['project_id' => $project->id, 'odf_id' => $odf->id, 'branch_id' => $branch->id]);
        $signature = app(FiberPlanService::class)->build($project->fresh())['signature'];

        $this->get(route('fiber-schema.index', ['project' => $project->id]))
            ->assertOk()->assertSee('data-plan-signature="'.$signature.'"', false);
        $this->get(route('projects.fiber.csv', $project))
            ->assertOk()->assertHeader('X-Fiber-Plan-Signature', $signature);
        $this->get(route('projects.fiber-schema-dxf', $project))
            ->assertOk()->assertHeader('X-Fiber-Plan-Signature', $signature)->assertSee('PLAN ID '.$signature);
        $this->get(route('projects.fiber-schema-pdf', $project))
            ->assertOk()->assertHeader('X-Fiber-Plan-Signature', $signature);
    }
}
