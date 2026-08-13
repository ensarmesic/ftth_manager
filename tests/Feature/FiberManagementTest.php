<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Services\FiberPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiberManagementTest extends TestCase
{
    use RefreshDatabase;

    private function projectWithFiberPath(): array
    {
        $project = Project::factory()->create(['fiber_budget_limit_db' => 28]);
        $odf = Odf::factory()->create(['project_id' => $project->id, 'fiber_capacity' => 144]);
        $route = NetworkRoute::factory()->create(['project_id' => $project->id, 'odf_id' => $odf->id, 'route_type' => 'distribution', 'fiber_length_m' => 2000, 'fiber_count' => 24]);
        $branch = NetworkBranch::factory()->create(['project_id' => $project->id, 'odf_id' => $odf->id, 'route_id' => $route->id]);
        $cabinet = Cabinet::factory()->create(['project_id' => $project->id, 'odf_id' => $odf->id, 'branch_id' => $branch->id, 'splitter_count' => 3, 'ports_per_splitter' => 4]);
        House::factory()->count(6)->create(['project_id' => $project->id, 'cabinet_id' => $cabinet->id]);

        return compact('project', 'odf', 'route', 'branch', 'cabinet');
    }

    public function test_plan_allocates_fibers_and_calculates_optical_budget(): void
    {
        ['project' => $project, 'cabinet' => $cabinet] = $this->projectWithFiberPath();
        $plan = app(FiberPlanService::class)->build($project);
        $connection = $plan['connections']->first();

        $this->assertSame(1, $plan['allocations'][$cabinet->id]['from']);
        $this->assertSame(2, $plan['allocations'][$cabinet->id]['to']);
        $this->assertSame('1:4', $connection['splitter_ratio']);
        $this->assertGreaterThan(0, $connection['loss_db']);
        $this->assertSame('ok', $connection['budget_status']);
    }

    public function test_splice_versions_lock_and_comparison_workflow(): void
    {
        ['project' => $project, 'cabinet' => $cabinet] = $this->projectWithFiberPath();
        $this->postJson(route('projects.fiber.splice.store', $project), ['cabinet_id' => $cabinet->id, 'fiber_number' => 1, 'tray' => 1, 'position' => 1, 'loss_db' => .12])->assertCreated();
        $versionId = $this->postJson(route('projects.fiber.versions.store', $project), ['label' => 'Odobrena v1'])->assertCreated()->json('version.id');
        $this->getJson(route('projects.fiber.versions', $project))->assertOk()->assertJsonCount(1, 'versions');
        $this->getJson(route('projects.fiber.versions.compare', [$project, $versionId]))->assertOk()->assertJsonStructure(['changes' => ['used_fibers', 'health', 'connections', 'issues']]);
        $this->putJson(route('projects.fiber.layout', $project), ['positions' => ['cab-'.$cabinet->id => ['x' => 420.5, 'y' => 180]]])->assertOk();
        $this->assertSame(420.5, $project->fresh()->fiber_schema_layout['cab-'.$cabinet->id]['x']);
        $this->postJson(route('projects.fiber.versions.restore', [$project, $versionId]))->assertOk();
        $this->assertCount(2, $project->fiberSchemaVersions()->get());
        $this->patchJson(route('projects.fiber.lock', $project), ['locked' => true])->assertOk()->assertJsonPath('locked', true);
        $this->postJson(route('projects.fiber.splice.store', $project), ['cabinet_id' => $cabinet->id, 'fiber_number' => 2, 'tray' => 1, 'position' => 2, 'loss_db' => .1])->assertStatus(423);
    }

    public function test_csv_pdf_plan_api_and_qr_field_sheet_render(): void
    {
        ['project' => $project, 'cabinet' => $cabinet] = $this->projectWithFiberPath();
        $this->getJson(route('projects.fiber.plan', $project))->assertOk()->assertJsonStructure(['allocations', 'connections', 'issues', 'health']);
        $this->get(route('projects.fiber.csv', $project))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->assertSee('Gubitak dB');
        $this->get(route('projects.fiber.field-sheet', [$project, $cabinet]))->assertOk()->assertSee('Terenski fiber list')->assertSee('data:image/svg+xml;base64', false);
        $this->get(route('projects.fiber-schema-pdf', $project))->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
