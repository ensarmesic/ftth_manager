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

    public function test_locked_schema_rejects_every_operation_that_changes_its_contents(): void
    {
        ['project' => $project, 'cabinet' => $cabinet] = $this->projectWithFiberPath();
        $spliceId = $this->postJson(route('projects.fiber.splice.store', $project), [
            'cabinet_id' => $cabinet->id, 'fiber_number' => 1, 'tray' => 1, 'position' => 1, 'loss_db' => .1,
        ])->assertCreated()->json('splice.id');
        $versionId = $this->postJson(route('projects.fiber.versions.store', $project), ['label' => 'Zaključana verzija'])
            ->assertCreated()->json('version.id');
        $this->patchJson(route('projects.fiber.lock', $project), ['locked' => true])->assertOk();

        $this->deleteJson(route('projects.fiber.splice.destroy', [$project, $spliceId]))->assertStatus(423);
        $this->putJson(route('projects.fiber.layout', $project), ['positions' => []])->assertStatus(423);
        $this->patchJson(route('projects.fiber.budget-settings', $project), [])->assertStatus(423);
        $this->postJson(route('projects.fiber.versions.restore', [$project, $versionId]))->assertStatus(423);
    }

    public function test_splice_cannot_reference_a_cabinet_from_another_project(): void
    {
        ['project' => $project] = $this->projectWithFiberPath();
        $foreignCabinet = Cabinet::factory()->create();

        $this->postJson(route('projects.fiber.splice.store', $project), [
            'cabinet_id' => $foreignCabinet->id, 'fiber_number' => 1, 'tray' => 1, 'position' => 1, 'loss_db' => .1,
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('fiber_splices', [
            'project_id' => $project->id,
            'cabinet_id' => $foreignCabinet->id,
        ]);
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

    public function test_power_budget_rejects_levels_below_the_configured_receiver_sensitivity(): void
    {
        ['project' => $project] = $this->projectWithFiberPath();
        $project->update([
            'power_budget_confirmed' => true,
            'additional_passive_loss_db' => 4,
            'olt_tx_power_dbm' => 0,
            'onu_tx_power_dbm' => 0,
            'onu_rx_sensitivity_dbm' => -10,
            'olt_rx_sensitivity_dbm' => -10,
        ]);

        $plan = app(FiberPlanService::class)->build($project->fresh());
        $connection = $plan['connections']->first();

        $this->assertTrue($connection['receiver_level_invalid']);
        $this->assertSame('error', $connection['budget_status']);
        $this->assertTrue(collect($plan['issues'])->contains(
            fn (array $issue): bool => str_contains($issue['message'], 'ispod osjetljivosti prijemnika'),
        ));
    }

    public function test_reserved_last_fiber_in_a_tube_is_skipped(): void
    {
        ['project' => $project, 'odf' => $odf, 'branch' => $branch, 'cabinet' => $firstCabinet] = $this->projectWithFiberPath();
        $project->update(['fiber_layout' => '12x12', 'fiber_reserve_per_tube' => 1]);
        House::where('cabinet_id', $firstCabinet->id)->limit(2)->delete();
        $cabinets = collect([$firstCabinet]);
        foreach (range(2, 12) as $order) {
            $cabinet = Cabinet::factory()->create([
                'project_id' => $project->id, 'odf_id' => $odf->id, 'branch_id' => $branch->id,
                'branch_order' => $order, 'ports_per_splitter' => 4,
            ]);
            House::factory()->count(4)->create(['project_id' => $project->id, 'cabinet_id' => $cabinet->id]);
            $cabinets->push($cabinet);
        }

        $plan = app(FiberPlanService::class)->build($project->fresh());

        $this->assertSame(11, $plan['allocations'][$cabinets[10]->id]['from']);
        $this->assertSame(13, $plan['allocations'][$cabinets[11]->id]['from']);
        $this->assertSame(12, $plan['usedFibers']);
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

    public function test_restore_reinstates_versioned_settings_and_splice_details(): void
    {
        ['project' => $project, 'cabinet' => $cabinet] = $this->projectWithFiberPath();
        $project->update([
            'fiber_layout' => '12x12',
            'fiber_reserve_per_tube' => 2,
            'pon_profile' => 'xgs_n2',
            'engineering_margin_db' => 4,
        ]);
        $this->postJson(route('projects.fiber.splice.store', $project), [
            'cabinet_id' => $cabinet->id, 'fiber_number' => 1, 'tray' => 2, 'position' => 3,
            'incoming_label' => 'IN-v1', 'outgoing_label' => 'OUT-v1', 'loss_db' => .12, 'note' => 'Odobreno',
        ])->assertCreated();
        $versionId = $this->postJson(route('projects.fiber.versions.store', $project), ['label' => 'Kompletna v1'])
            ->assertCreated()->json('version.id');

        $project->update([
            'fiber_layout' => '6x24',
            'fiber_reserve_per_tube' => 0,
            'pon_profile' => 'gpon_b_plus',
            'engineering_margin_db' => 1,
        ]);
        $this->postJson(route('projects.fiber.splice.store', $project), [
            'cabinet_id' => $cabinet->id, 'fiber_number' => 1, 'tray' => 4, 'position' => 5,
            'incoming_label' => 'promijenjeno', 'loss_db' => .4,
        ])->assertOk();

        $this->postJson(route('projects.fiber.versions.restore', [$project, $versionId]))->assertOk();

        $project->refresh();
        $splice = $project->fiberSplices()->sole();
        $this->assertSame('12x12', $project->fiber_layout);
        $this->assertSame(2, $project->fiber_reserve_per_tube);
        $this->assertSame('xgs_n2', $project->pon_profile);
        $this->assertSame(4.0, $project->engineering_margin_db);
        $this->assertSame(2, $splice->tray);
        $this->assertSame(3, $splice->position);
        $this->assertSame('IN-v1', $splice->incoming_label);
        $this->assertSame('OUT-v1', $splice->outgoing_label);
        $this->assertSame('Odobreno', $splice->note);
    }

    public function test_schema_version_history_keeps_only_the_latest_twenty_versions(): void
    {
        ['project' => $project] = $this->projectWithFiberPath();

        foreach (range(1, 22) as $number) {
            $this->postJson(route('projects.fiber.versions.store', $project), ['label' => "Verzija {$number}"])
                ->assertCreated();
        }

        $labels = $project->fiberSchemaVersions()->latest()->pluck('label');
        $this->assertCount(20, $labels);
        $this->assertSame('Verzija 22', $labels->first());
        $this->assertSame('Verzija 3', $labels->last());
        $this->assertFalse($labels->contains('Verzija 1'));
        $this->assertFalse($labels->contains('Verzija 2'));
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

    public function test_each_odf_has_an_independent_fiber_range_and_capacity_check(): void
    {
        ['project' => $project, 'odf' => $firstOdf, 'cabinet' => $firstCabinet] = $this->projectWithFiberPath();
        $firstOdf->update(['fiber_capacity' => 1]);
        House::where('cabinet_id', $firstCabinet->id)->limit(2)->delete();

        $secondOdf = Odf::factory()->create(['project_id' => $project->id, 'fiber_capacity' => 1]);
        $secondRoute = NetworkRoute::factory()->create([
            'project_id' => $project->id, 'odf_id' => $secondOdf->id, 'route_type' => 'distribution',
        ]);
        $secondBranch = NetworkBranch::factory()->create([
            'project_id' => $project->id, 'odf_id' => $secondOdf->id, 'route_id' => $secondRoute->id,
        ]);
        $secondCabinet = Cabinet::factory()->create([
            'project_id' => $project->id, 'odf_id' => $secondOdf->id, 'branch_id' => $secondBranch->id,
            'ports_per_splitter' => 4,
        ]);
        House::factory()->count(4)->create(['project_id' => $project->id, 'cabinet_id' => $secondCabinet->id]);

        $plan = app(FiberPlanService::class)->build($project->fresh());

        $this->assertSame(1, $plan['allocations'][$firstCabinet->id]['from']);
        $this->assertSame(1, $plan['allocations'][$secondCabinet->id]['from']);
        $this->assertSame(1, $plan['odfs'][$firstOdf->id]['usedTo']);
        $this->assertSame(1, $plan['odfs'][$secondOdf->id]['usedTo']);
        $this->assertSame(2, $plan['odfs'][$firstOdf->id]['reserveFrom']);
        $this->assertSame(1, $plan['odfs'][$firstOdf->id]['reserveTo']);
        $this->assertSame(2, $plan['odfs'][$secondOdf->id]['reserveFrom']);
        $this->assertSame(1, $plan['odfs'][$secondOdf->id]['reserveTo']);
        $this->assertSame(2, $plan['capacity']);
        $this->assertSame(2, $plan['usedFibers']);
        $this->assertFalse(collect($plan['issues'])->contains(
            fn (array $issue): bool => str_contains($issue['message'], 'Dupla dodjela')
                || str_contains($issue['message'], 'Kapacitet je prekoračen'),
        ));
    }

    public function test_child_cabinet_inherits_its_parent_fiber_path(): void
    {
        ['project' => $project, 'odf' => $odf, 'branch' => $branch, 'cabinet' => $parent] = $this->projectWithFiberPath();
        $child = Cabinet::factory()->create([
            'project_id' => $project->id,
            'parent_cabinet_id' => $parent->id,
            'odf_id' => null,
            'branch_id' => null,
            'ports_per_splitter' => 4,
        ]);
        House::factory()->count(4)->create(['project_id' => $project->id, 'cabinet_id' => $child->id]);

        $plan = app(FiberPlanService::class)->build($project->fresh());
        $connection = $plan['connections']->firstWhere('cabinet_id', $child->id);

        $this->assertArrayHasKey($child->id, $plan['allocations']);
        $this->assertSame($odf->id, $plan['allocations'][$child->id]['odf_id']);
        $this->assertSame($branch->id, $plan['allocations'][$child->id]['branch_id']);
        $this->assertSame($odf->id, $connection['odf_id']);
        $this->assertSame($branch->name, $connection['branch']);
        $this->assertFalse(collect($plan['issues'])->contains(
            fn (array $issue): bool => str_contains($issue['message'], 'nema ODF vezu'),
        ));
    }

    public function test_plan_reports_a_splice_made_stale_by_a_topology_change(): void
    {
        ['project' => $project, 'odf' => $odf, 'branch' => $branch, 'cabinet' => $cabinet] = $this->projectWithFiberPath();
        $cabinet->update(['branch_order' => 2]);
        $this->postJson(route('projects.fiber.splice.store', $project), [
            'cabinet_id' => $cabinet->id, 'fiber_number' => 1, 'tray' => 1, 'position' => 1, 'loss_db' => .1,
        ])->assertCreated();

        $earlierCabinet = Cabinet::factory()->create([
            'project_id' => $project->id, 'odf_id' => $odf->id, 'branch_id' => $branch->id,
            'branch_order' => 1, 'name' => 'ODO prije postojećeg', 'ports_per_splitter' => 4,
        ]);
        House::factory()->count(4)->create(['project_id' => $project->id, 'cabinet_id' => $earlierCabinet->id]);

        $plan = app(FiberPlanService::class)->build($project->fresh());

        $this->assertGreaterThan(1, $plan['allocations'][$cabinet->id]['from']);
        $this->assertTrue(collect($plan['issues'])->contains(
            fn (array $issue): bool => str_contains($issue['message'], 'splice F1 više ne pripada'),
        ));
    }
}
