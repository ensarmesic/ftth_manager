<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\Odf;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectHealthService;
use App\Services\ProjectValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_reports_the_same_counts_as_the_full_project_check(): void
    {
        $project = Project::factory()->create(['name' => 'Jedinstvena provjera']);
        $odf = Odf::factory()->create(['project_id' => $project->id]);
        Cabinet::factory()->create(['project_id' => $project->id, 'odf_id' => $odf->id]);
        House::factory()->count(3)->create(['project_id' => $project->id, 'cabinet_id' => null]);

        $items = collect(app(ProjectValidationService::class)->validateProject($project->fresh()));
        $errors = $items->where('level', 'error')->count();
        $warnings = $items->where('level', 'warning')->count();
        $health = app(ProjectHealthService::class)->forProject($project);

        $this->assertSame([$errors, $warnings, $errors + $warnings], [$health['errors'], $health['warnings'], $health['issues']]);
        $this->assertGreaterThanOrEqual(3, $warnings);

        $user = User::factory()->create();
        $this->actingAs($user)->get(route('project-check.index'))->assertOk()
            ->assertSee('Jedinstvena provjera')
            ->assertSee('Kuće bez ODO-a');
        $this->actingAs($user)->get(route('project-check.index', ['project' => $project->id]))->assertOk()
            ->assertSee('Upozorenja <b>'.number_format($warnings, 0, ',', '.').'</b>', false)
            ->assertSee('nema povezan ODO');
        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertSee(($errors + $warnings).' problema');
    }

    public function test_project_check_groups_findings_by_kind(): void
    {
        $project = Project::factory()->create(['name' => 'Grupe provjere']);
        $odf = Odf::factory()->create(['project_id' => $project->id]);
        Cabinet::factory()->create(['project_id' => $project->id, 'odf_id' => $odf->id]);
        House::factory()->count(4)->create(['project_id' => $project->id, 'cabinet_id' => null]);

        $items = app(ProjectHealthService::class)->forProject($project)['items'];
        $this->assertSame(4, collect($items)->where('rule', 'house_no_cabinet')->count());
        $this->assertSame('Kuće bez ODO-a', collect($items)->firstWhere('rule', 'house_no_cabinet')['rule_label']);

        $page = $this->get(route('project-check.index', ['project' => $project->id]))->assertOk();
        // One group with the shared advice, four findings inside it.
        $page->assertSee('Kuće bez ODO-a')
            ->assertSee('data-total="4"', false)
            ->assertSee('Dodijeli kuću ODO ormariću.')
            ->assertSee('data-pc-level="warning"', false)
            ->assertSee('data-pc-type="house"', false);
        $this->assertSame(1, substr_count($page->getContent(), 'Dodijeli kuću ODO ormariću.'));
    }

    public function test_cached_result_is_refreshed_when_the_project_changes(): void
    {
        $project = Project::factory()->create();
        $odf = Odf::factory()->create(['project_id' => $project->id]);
        $cabinet = Cabinet::factory()->create(['project_id' => $project->id, 'odf_id' => $odf->id]);
        $house = House::factory()->create(['project_id' => $project->id, 'cabinet_id' => null]);
        $health = app(ProjectHealthService::class);
        $before = $health->forProject($project)['warnings'];

        $this->travel(2)->seconds();
        $house->update(['cabinet_id' => $cabinet->id, 'latitude' => $cabinet->latitude, 'longitude' => $cabinet->longitude]);

        $this->assertLessThan($before, $health->forProject($project)['warnings']);
    }
}
