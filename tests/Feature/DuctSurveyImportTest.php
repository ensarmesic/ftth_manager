<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Models\ProjectAppendixItem;
use App\Models\SurveyPoint;
use App\Services\DuctSurveyImportService;
use App\Services\SurveyImportMaintenanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class DuctSurveyImportTest extends TestCase
{
    use RefreshDatabase;

    /** Two survey days: the second continues the first (GK zone 6 metres). */
    private function files(): array
    {
        $x = 6538580.0;
        $y = 4926030.0;
        $line = fn (int $no, float $dx, float $dy, string $code) => sprintf('%d %.3f %.3f 200.000 %s', $no, $x + $dx, $y + $dy, $code);

        return [
            'dan1.txt' => implode("\n", [
                $line(1, 0, 0, 'ODF 3'),
                $line(2, 2, 0, 'Rov +MD + 14/10 Crvena'),
                $line(3, 6, 0, 'Rov +MD + 14/10 Crvena'),
                $line(4, 10, 0, 'Rov +MD + 14/10 Crvena'),
                $line(5, 10, 1, 'Spojnica 14/10 crvena'),
                $line(6, 14, 0, 'Rov +MD + 14/10 Crvena'),
                $line(7, 14, 1, 'Rov+ 14/10 Crvena dolaz na stub - odlazi'),
                $line(8, 14, 2, 'Box na stubu'),
            ]),
            'dan2.txt' => implode("\n", [
                $line(9, 18, 0, 'Rov +MD + 14/10 Crvena'),
                $line(10, 18, 1, 'ZO 1-S2-ODF 3'),
                $line(11, 22, 0, 'Rov +MD + 14/10 Crvena'),
                $line(12, 26, 0, 'Rov +MD + 14/10 Crvena'),
                $line(13, 26, 1, 'ZO 2-S2-ODF 3'),
            ]),
        ];
    }

    public function test_app_import_adds_day_by_day_and_removing_a_day_rebuilds(): void
    {
        $project = Project::factory()->create();
        $files = $this->files();
        $upload = fn (string $name) => UploadedFile::fake()->createWithContent($name, $files[$name]);

        // Day 1 alone: recognised as a duct survey, previewed with checks and map geometry.
        $this->postJson(route('projects.survey-points.preview', $project), ['points_file' => $upload('dan1.txt')])
            ->assertOk()
            ->assertJsonPath('format', 'duct_survey')
            ->assertJsonPath('summary.boxes', 1)
            ->assertJsonPath('summary.closures', 0)
            ->assertJsonStructure(['checks', 'geometry' => ['lines', 'points'], 'secondaries']);
        $this->postJson(route('projects.survey-points.import', $project), ['points_file' => $upload('dan1.txt')])->assertCreated();

        // Day 2 continues day 1: only the new file is uploaded, the network covers both.
        $this->postJson(route('projects.survey-points.preview', $project), ['points_file' => $upload('dan2.txt')])
            ->assertOk()
            ->assertJsonPath('format', 'duct_survey')
            ->assertJsonPath('total_points', 13)
            ->assertJsonPath('summary.closures', 2);
        $this->postJson(route('projects.survey-points.import', $project), ['points_file' => $upload('dan2.txt')])->assertCreated();
        $this->assertSame(2, Cabinet::where('project_id', $project->id)->where('kind', 'zo')->count());
        $this->assertCount(2, app(SurveyImportMaintenanceService::class)->importedBatches($project));

        // Removing day 2 rebuilds the network from day 1 only.
        $day2 = SurveyPoint::where('project_id', $project->id)->where('source_file', 'dan2.txt')->value('import_batch');
        $this->deleteJson(route('projects.survey-points.imports.destroy', [$project, $day2]))->assertOk();
        $this->assertSame(0, Cabinet::where('project_id', $project->id)->where('kind', 'zo')->count());
        $this->assertSame(1, Cabinet::where('project_id', $project->id)->where('kind', 'box')->count());
        $this->assertSame(8, SurveyPoint::where('project_id', $project->id)->count());
    }

    public function test_never_takes_over_a_project_with_an_older_format_import(): void
    {
        $project = Project::factory()->create();
        SurveyPoint::create([
            'project_id' => $project->id, 'import_batch' => sha1('stari'), 'source_file' => 'stari.txt', 'source' => 'txt',
            'point_no' => 1, 'x' => 6538580, 'y' => 4926030, 'z' => 200, 'latitude' => 44.47, 'longitude' => 18.48,
            'code' => 'rov+mc 10/8 X1 - zo3', 'kind' => 'trench',
        ]);
        $file = UploadedFile::fake()->createWithContent('dan1.txt', $this->files()['dan1.txt']);

        $this->postJson(route('projects.survey-points.import', $project), ['points_file' => $file])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'starijem formatu'));
        $this->assertSame(1, SurveyPoint::where('project_id', $project->id)->count(), 'the older import is untouched');
    }

    public function test_builds_the_network_and_reimport_replaces_it(): void
    {
        $project = Project::factory()->create();
        $service = app(DuctSurveyImportService::class);

        $first = $service->import($project, $this->files());
        $this->assertSame([], $first['result']['warnings']);

        $this->assertSame(['ODF-3'], Odf::where('project_id', $project->id)->pluck('name')->all());
        $this->assertEqualsCanonicalizing(
            ['ZO 1-S2-ODF III', 'ZO 2-S2-ODF III', 'BOX 1-ODF III'],
            Cabinet::where('project_id', $project->id)->pluck('name')->all(),
        );
        $box = Cabinet::where('name', 'BOX 1-ODF III')->firstOrFail();
        $this->assertSame('box', $box->kind);
        $this->assertSame(8, $box->surveyPoint?->point_no, 'the box knows the pole it hangs on');
        $this->assertSame('zo', Cabinet::where('name', 'ZO 1-S2-ODF III')->value('kind'));
        $zo2 = Cabinet::where('name', 'ZO 2-S2-ODF III')->firstOrFail();
        $this->assertSame('ZO 1-S2-ODF III', Cabinet::find($zo2->parent_cabinet_id)?->name);
        $this->assertSame(['SEKUNDARNI KRAK III-2'], NetworkBranch::where('project_id', $project->id)->pluck('name')->all());

        $tubes = NetworkRoute::where('project_id', $project->id)->where('route_type', 'distribution')->pluck('name')->unique()->sort()->values()->all();
        $this->assertSame(['MC 14/10 Crvena', 'MC MD 4x12/10'], $tubes);
        // The splice sits ON the line: the red tube is not cut there.
        $this->assertFalse(NetworkRoute::where('project_id', $project->id)->where('name', 'MC 14/10 Crvena')
            ->get()->contains(fn ($r) => str_contains($r->note, '–5') || str_contains($r->note, ' 5–')));

        $this->assertSame(
            ['SP 1 · 14/10 crvena | Tačka 5: Spojnica 14/10 crvena'],
            ProjectAppendixItem::where('project_id', $project->id)->where('type', 'splice')->pluck('note')->all(),
        );
        $this->assertSame('SP 7 · 14/10 plava · S3', DuctSurveyImportService::spliceTitle(7, 'Spojnica 14/10   plave-S3'));
        $this->assertSame('SP 2 · MD', DuctSurveyImportService::spliceTitle(2, 'Spojnica MD'));

        $counts = fn () => [
            Odf::where('project_id', $project->id)->count(),
            Cabinet::where('project_id', $project->id)->count(),
            NetworkBranch::where('project_id', $project->id)->count(),
            NetworkRoute::where('project_id', $project->id)->count(),
        ];
        $before = $counts();
        $service->import($project, $this->files());
        $this->assertSame($before, $counts(), 'a second import replaces, never duplicates');
    }
}
