<?php

namespace Tests\Feature;

use App\Models\Odf;
use App\Models\Project;
use App\Models\SurveyPoint;
use App\Services\SurveyPointImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SurveyObjectImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_supported_geodetic_labels_are_classified_and_original_text_is_preserved(): void
    {
        $labels = [
            'S.E' => 'electric_pole',
            'S.R.' => 'lighting_pole',
            'S.E-R' => 'electric_lighting_pole',
            'S.E+R' => 'electric_lighting_pole',
            'S.E-V.N' => 'high_voltage_pole',
            'S.E-V.N+R' => 'high_voltage_lighting_pole',
            'S.E+R-Drveni' => 'wooden_electric_lighting_pole',
            'Stub drveni-Telemach' => 'telemach_wooden_pole',
            'Stub prazan tek ugradjen' => 'empty_pole',
            '"Stub prazan tek ugradjen"' => 'empty_pole',
            'S.E.' => 'electric_pole',
            'S.R. + S.E.' => 'electric_lighting_pole',
            'drveni s.r.' => 'wooden_lighting_pole',
            'drveni s.r. + s.e.' => 'wooden_electric_lighting_pole',
            'novi stub' => 'empty_pole',
            'stub sa optikom' => 'fiber_pole',
            'ODF' => 'odf',
            'Z.P.-' => 'green_area_point',
        ];
        $contents = collect(array_keys($labels))->values()->map(
            fn (string $label, int $index) => ($index + 1).' '.(6549699.731 + $index).' '.(4923604.537 + $index).' 234.123 '.$label
        )->join("\n");

        $points = app(SurveyPointImportService::class)->parse($contents);

        $this->assertCount(count($labels), $points);
        foreach ($points as $point) {
            $this->assertSame($labels[$point['code']], $point['survey_category']);
            $this->assertSame('survey_object', $point['kind']);
        }
    }

    public function test_geodetic_odf_is_persisted_only_as_survey_object(): void
    {
        $project = Project::factory()->create();
        $contents = "1\t6549699.731\t4923604.537\t234.123\tODF";

        $result = app(SurveyPointImportService::class)->confirm($project, $contents, 'objekti.txt');

        $point = SurveyPoint::whereBelongsTo($project)->sole();
        $this->assertSame('odf', $point->survey_category);
        $this->assertSame('ODF', $point->original_label);
        $this->assertSame('EPSG:31276', $point->source_crs);
        $this->assertNotNull($point->imported_at);
        $this->assertSame(1, $result['points']);
        $this->assertSame(0, Odf::whereBelongsTo($project)->count());
    }

    public function test_preview_reports_invalid_rows_and_near_duplicates_without_quadratic_scan_requirement(): void
    {
        $project = Project::factory()->create();
        $contents = implode("\n", [
            '1 6549699.731 4923604.537 234.123 S.E',
            '2 6549699.781 4923604.537 234.123 S.R',
            'neispravan red',
        ]);

        $preview = app(SurveyPointImportService::class)->preview($project, $contents, 'kontrola.txt');

        $this->assertCount(1, $preview['invalid_rows']);
        $this->assertCount(1, $preview['possible_duplicates']);
        $this->assertSame(1, $preview['object_summary']['poles']);
        $this->assertSame(1, $preview['object_summary']['skipped_duplicates']);
        $this->assertSame(3, $preview['total_rows']);
    }

    public function test_survey_objects_within_half_a_meter_are_saved_only_once(): void
    {
        $project = Project::factory()->create();
        $contents = implode("\n", [
            '1 6549699.731 4923604.537 234.123 S.E',
            '2 6549700.131 4923604.537 234.123 S.R',
            '3 6549700.331 4923604.537 234.123 S.R',
        ]);

        $result = app(SurveyPointImportService::class)->confirm($project, $contents, 'duplikati.txt');

        $this->assertSame(2, $result['points']);
        $this->assertSame([1, 3], SurveyPoint::whereBelongsTo($project)->orderBy('point_no')->pluck('point_no')->all());
        $this->assertSame([1, 2], SurveyPoint::whereBelongsTo($project)->orderBy('pole_no')->pluck('pole_no')->all());
    }

    public function test_map_data_exposes_survey_objects_with_cad_label(): void
    {
        $project = Project::factory()->create();
        app(SurveyPointImportService::class)->confirm($project, '7 6549699.731 4923604.537 234.123 S.R.', 'stub.txt');

        $this->getJson(route('api.projects.map-data', $project))
            ->assertOk()
            ->assertJsonCount(1, 'survey_objects')
            ->assertJsonPath('survey_objects.0.category', 'lighting_pole')
            ->assertJsonPath('survey_objects.0.cad_label', 'SR1')
            ->assertJsonPath('survey_objects.0.is_pole', true)
            ->assertJsonPath('survey_objects.0.size', 12);
    }

    public function test_reclassifying_point_as_pole_assigns_next_pole_number(): void
    {
        $project = Project::factory()->create();
        app(SurveyPointImportService::class)->confirm($project, implode("\n", [
            '1 6549699.731 4923604.537 234.123 S.E',
            '2 6549709.731 4923604.537 234.123 ODF',
        ]), 'objekti.txt');
        $odfPoint = SurveyPoint::whereBelongsTo($project)->where('survey_category', 'odf')->sole();
        $this->assertNull($odfPoint->pole_no);

        $this->patchJson(route('projects.survey-points.update', [$project, $odfPoint]), ['survey_category' => 'electric_pole'])
            ->assertOk();

        $this->assertSame('electric_pole', $odfPoint->fresh()->survey_category);
        $this->assertSame(2, $odfPoint->fresh()->pole_no);
    }

    public function test_reclassifying_rejects_unknown_category_and_foreign_project(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        app(SurveyPointImportService::class)->confirm($project, '1 6549699.731 4923604.537 234.123 S.E', 'stub.txt');
        $point = SurveyPoint::whereBelongsTo($project)->sole();

        $this->patchJson(route('projects.survey-points.update', [$project, $point]), ['survey_category' => 'nepostojeca'])
            ->assertUnprocessable();
        $this->patchJson(route('projects.survey-points.update', [$other, $point]), ['survey_category' => 'odf'])
            ->assertNotFound();
    }
}
