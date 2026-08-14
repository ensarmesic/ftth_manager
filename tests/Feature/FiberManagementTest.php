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
use Illuminate\Support\Facades\DB;
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
        $this->assertSame('estimate', $connection['budget_status']);
        $this->assertTrue($connection['below_minimum']);
        $this->assertSame(1490, $connection['downstream_nm']);
        $this->assertSame(1310, $connection['upstream_nm']);
        $this->assertGreaterThan($connection['downstream_loss_db'], $connection['upstream_loss_db']);
        $this->assertFalse($plan['assumptionsConfirmed']);
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
        $this->get(route('projects.fiber.csv', $project))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->assertSee('ODN gubitak dB');
        $this->get(route('projects.fiber.field-sheet', [$project, $cabinet]))->assertOk()->assertSee('Terenski fiber list')->assertSee('data:image/svg+xml;base64', false);
        $this->get(route('projects.fiber-schema-pdf', $project))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_rack_and_tracing_render_real_fiber_allocations_without_placeholders(): void
    {
        ['project' => $project, 'cabinet' => $cabinet] = $this->projectWithFiberPath();

        $this->get(route('fiber-schema.index'))
            ->assertOk()
            ->assertSee($cabinet->name)
            ->assertSee('data-fiber-range="1-2"', false)
            ->assertDontSee('F ???', false)
            ->assertDontSee('data-fiber-range="?"', false);
    }

    public function test_splice_must_use_the_cabinets_allocated_fiber_and_a_free_tray_position(): void
    {
        ['project' => $project, 'cabinet' => $cabinet] = $this->projectWithFiberPath();

        $this->postJson(route('projects.fiber.splice.store', $project), [
            'cabinet_id' => $cabinet->id, 'fiber_number' => 3, 'tray' => 1, 'position' => 1, 'loss_db' => .1,
        ])->assertUnprocessable()->assertJsonValidationErrors('fiber_number');

        $this->postJson(route('projects.fiber.splice.store', $project), [
            'cabinet_id' => $cabinet->id, 'fiber_number' => 1, 'tray' => 1, 'position' => 1, 'loss_db' => .1,
        ])->assertCreated();

        $this->postJson(route('projects.fiber.splice.store', $project), [
            'cabinet_id' => $cabinet->id, 'fiber_number' => 2, 'tray' => 1, 'position' => 1, 'loss_db' => .1,
        ])->assertUnprocessable()->assertJsonValidationErrors('position');

        $this->postJson(route('projects.fiber.splice.store', $project), [
            'cabinet_id' => $cabinet->id, 'fiber_number' => 1, 'tray' => 1, 'position' => 1, 'loss_db' => .08,
        ])->assertOk()->assertJsonPath('splice.loss_db', .08);
    }

    public function test_csv_quotes_delimiters_and_neutralizes_spreadsheet_formulas(): void
    {
        ['project' => $project, 'cabinet' => $cabinet] = $this->projectWithFiberPath();
        $cabinet->update(['name' => '=HYPERLINK("https://invalid.test";"ODO")']);

        $content = $this->get(route('projects.fiber.csv', $project))->assertOk()->getContent();

        $this->assertStringContainsString('"\'=HYPERLINK(""https://invalid.test"";""ODO"")"', $content);
        $this->assertStringContainsString('"ODN gubitak dB"', $content);
    }

    public function test_power_budget_uses_itu_profile_both_directions_and_engineering_margin(): void
    {
        ['project' => $project] = $this->projectWithFiberPath();
        $project->update(['pon_profile' => 'xgs_n2', 'additional_passive_loss_db' => 7, 'engineering_margin_db' => 3, 'power_budget_confirmed' => true, 'olt_tx_power_dbm' => 4, 'onu_tx_power_dbm' => 4, 'onu_rx_sensitivity_dbm' => -28, 'olt_rx_sensitivity_dbm' => -28]);

        $plan = app(FiberPlanService::class)->build($project->fresh());
        $connection = $plan['connections']->first();

        $this->assertSame('XGS-PON N2', $plan['profile']['label']);
        $this->assertSame(16.0, $plan['profile']['min']);
        $this->assertSame(31.0, $plan['profile']['max']);
        $this->assertSame(1577, $connection['downstream_nm']);
        $this->assertSame(1270, $connection['upstream_nm']);
        $this->assertSame(7.0, $connection['additional_passive_loss_db']);
        $this->assertSame(round($connection['loss_db'] + 3, 2), $connection['design_loss_db']);
        $this->assertFalse($connection['below_minimum']);
        $this->assertSame('ok', $connection['budget_status']);
        $this->assertTrue($plan['assumptionsConfirmed']);
        $this->assertSame(round(4 - $connection['downstream_loss_db'], 2), $connection['downstream_rx_dbm']);
        $this->assertSame(round(4 - $connection['upstream_loss_db'], 2), $connection['upstream_rx_dbm']);
        $this->assertSame(round($connection['downstream_rx_dbm'] + 28, 2), $connection['downstream_receiver_margin_db']);
    }

    public function test_restore_rejects_a_version_that_references_a_deleted_cabinet(): void
    {
        ['project' => $project, 'cabinet' => $cabinet] = $this->projectWithFiberPath();
        $this->postJson(route('projects.fiber.splice.store', $project), [
            'cabinet_id' => $cabinet->id, 'fiber_number' => 1, 'tray' => 1, 'position' => 1, 'loss_db' => .1,
        ])->assertCreated();
        $versionId = $this->postJson(route('projects.fiber.versions.store', $project), ['label' => 'Prije brisanja'])->assertCreated()->json('version.id');
        $cabinet->delete();

        $this->postJson(route('projects.fiber.versions.restore', [$project, $versionId]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('version');
    }

    public function test_guided_budget_setup_saves_equipment_levels_and_confirms_the_calculation(): void
    {
        ['project' => $project] = $this->projectWithFiberPath();

        $this->patchJson(route('projects.fiber.budget-settings', $project), [
            'pon_profile' => 'gpon_b_plus', 'feeder_splitter_ratio' => 16, 'olt_tx_power_dbm' => 3, 'onu_tx_power_dbm' => 2,
            'onu_rx_sensitivity_dbm' => -27, 'olt_rx_sensitivity_dbm' => -28,
            'engineering_margin_db' => 3, 'connector_count' => 2, 'connector_loss_db' => .5,
            'planned_splice_count' => 2, 'splice_allowance_db' => .1, 'additional_passive_loss_db' => 4,
        ])->assertOk();

        $project->refresh();
        $this->assertTrue($project->power_budget_confirmed);
        $this->assertSame(3.0, $project->olt_tx_power_dbm);
        $this->assertSame(16, $project->feeder_splitter_ratio);
        $connection = app(FiberPlanService::class)->build($project)['connections']->first();
        $this->assertSame(round(3 - $connection['downstream_loss_db'], 2), $connection['downstream_rx_dbm']);
    }

    public function test_fiber_plan_query_count_stays_constant_for_many_cabinets(): void
    {
        ['project' => $project, 'odf' => $odf, 'branch' => $branch] = $this->projectWithFiberPath();
        $cabinets = Cabinet::factory()->count(40)->create([
            'project_id' => $project->id, 'odf_id' => $odf->id, 'branch_id' => $branch->id,
        ]);
        foreach ($cabinets as $cabinet) {
            House::factory()->create(['project_id' => $project->id, 'cabinet_id' => $cabinet->id]);
        }

        $project->unsetRelations();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $plan = app(FiberPlanService::class)->build($project);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(41, $plan['connections']);
        $this->assertLessThanOrEqual(10, $queryCount, "Fiber plan je izvršio {$queryCount} SQL upita.");
    }
}
