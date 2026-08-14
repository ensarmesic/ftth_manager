<?php

namespace Tests\Feature;

use App\Models\FiberSplice;
use App\Models\House;
use App\Models\Project;
use App\Services\FiberPlanService;
use App\Services\FtthIntelligenceService;
use App\Services\ProjectBackupService;
use App\Services\ProjectMaterialService;
use App\Services\ProjectSnapshotService;
use App\Services\ProjectValidationService;
use App\Services\SurveyPointImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompleteProjectWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_project_flows_from_survey_import_to_reports_snapshot_and_backup_restore(): void
    {
        $project = Project::factory()->create([
            'name' => 'Kompletan FTTH workflow',
            'code' => 'E2E-PROJEKAT',
            'location' => 'Tuzla',
            'fiber_layout' => '6x24',
            'fiber_reserve_per_tube' => 1,
        ]);
        $contents = "1000 6550189.000 4921055.000 260.000 ODF1\n"
            .file_get_contents(base_path('tests/Fixtures/survey/test-4-ormara-kompletna-mreza.txt'));
        $survey = app(SurveyPointImportService::class);

        $preview = $survey->preview($project, $contents);
        $this->assertGreaterThan(30, $preview['total_points']);
        $this->assertCount(4, $preview['cabinets']);
        $this->assertGreaterThan(0, $preview['houses']);

        $created = $survey->confirm($project, $contents, 'kompletna-mreza.txt');
        $this->assertSame(4, $created['cabinets']);
        $this->assertGreaterThan(0, $created['odfs']);
        $this->assertGreaterThan(0, $created['trenches']);
        $this->assertGreaterThan(0, $created['ducts']);
        $this->assertSame(4, $created['houses']);

        $project->refresh()->load(['odfs', 'cabinets.houses', 'houses', 'routes', 'branches']);
        $this->assertCount(4, $project->cabinets);
        $this->assertCount(4, $project->houses);
        $this->assertNotEmpty($project->branches);
        $this->assertFalse($project->houses->contains(fn (House $house): bool => $house->cabinet_id === null));

        $intelligence = app(FtthIntelligenceService::class);
        $validation = app(ProjectValidationService::class)->validateProject($project);
        $materials = app(ProjectMaterialService::class)->summary($project);
        $this->assertNotEmpty($validation);
        $this->assertSame(4, $materials['odo_count']);
        $this->assertSame(4, $materials['house_count']);
        $this->assertGreaterThan(0, $materials['route_length_m']);

        $fiberPlan = app(FiberPlanService::class)->build($project);
        $this->assertCount(4, $fiberPlan['allocations'], $project->cabinets->map(fn ($cabinet) => [
            'name' => $cabinet->name,
            'odf_id' => $cabinet->odf_id,
            'branch_id' => $cabinet->branch_id,
        ])->toJson());
        $firstCabinet = $project->cabinets->first();
        $allocation = $fiberPlan['allocations'][$firstCabinet->id];
        FiberSplice::create([
            'project_id' => $project->id,
            'cabinet_id' => $firstCabinet->id,
            'fiber_number' => $allocation['from'],
            'tray' => 1,
            'position' => 1,
            'incoming_label' => 'ODF-'.$allocation['from'],
            'outgoing_label' => $firstCabinet->name,
            'loss_db' => .1,
        ]);

        $this->get(route('reports.project-appendix', $project))
            ->assertOk()
            ->assertSee($project->name)
            ->assertSee('FTTH ORMARI');
        $this->get(route('projects.fiber.csv', $project))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $snapshot = app(ProjectSnapshotService::class)->create($project, 'Prije terenske izmjene');
        $originalLabel = $project->houses->first()->label;
        $project->houses->first()->update(['label' => 'Privremeno promijenjena kuća']);
        app(ProjectSnapshotService::class)->restore($project, $snapshot);
        $this->assertDatabaseHas('houses', ['project_id' => $project->id, 'label' => $originalLabel]);
        $this->assertDatabaseHas('fiber_splices', ['project_id' => $project->id, 'cabinet_id' => $firstCabinet->id]);

        $project->refresh()->load(['odfs', 'branches', 'cabinets', 'houses', 'routes', 'materials', 'fiberSplices']);
        $backupService = app(ProjectBackupService::class);
        $backup = $backupService->backup($project);
        $restored = $backupService->restore($backup, 'Vraćeni kompletan workflow');
        $restored->load(['odfs', 'branches', 'cabinets', 'houses', 'routes', 'fiberSplices']);

        $this->assertSame($project->odfs->count(), $restored->odfs->count());
        $this->assertSame($project->branches->count(), $restored->branches->count());
        $this->assertSame($project->cabinets->count(), $restored->cabinets->count());
        $this->assertSame($project->houses->count(), $restored->houses->count());
        $this->assertSame($project->routes->count(), $restored->routes->count());
        $this->assertSame($project->fiberSplices->count(), $restored->fiberSplices->count());
        $this->assertFalse($restored->houses->contains(fn (House $house): bool => $house->cabinet_id === null));
        $this->assertFalse($restored->fiberSplices->contains(fn (FiberSplice $splice): bool => $splice->cabinet_id === null));

        $restoredPlan = app(FiberPlanService::class)->build($restored);
        $this->assertSame($fiberPlan['usedFibers'], $restoredPlan['usedFibers']);
        $this->assertSame($fiberPlan['capacity'], $restoredPlan['capacity']);
    }
}
