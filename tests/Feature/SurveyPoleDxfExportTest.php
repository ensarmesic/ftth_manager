<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\SurveyPoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SurveyPoleDxfExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_dxf_contains_cad_pole_symbol_on_original_gk_coordinate(): void
    {
        $project = Project::factory()->create(['code' => 'STUBOVI']);
        SurveyPoint::create([
            'project_id' => $project->id,
            'import_batch' => sha1('pole'),
            'point_no' => 430,
            'x' => 6549699.731,
            'y' => 4923604.537,
            'z' => 234.123,
            'latitude' => 44.4552,
            'longitude' => 18.6194,
            'code' => 'S.R.',
            'kind' => 'survey_object',
            'survey_category' => 'lighting_pole',
            'pole_no' => 1,
            'original_label' => 'S.R.',
            'source_crs' => 'EPSG:31276',
        ]);

        $response = $this->post(route('projects.dxf', $project));

        $response->assertOk()->assertHeader('content-type', 'application/dxf');
        $contents = file_get_contents($response->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('FTTH_SURVEY_POLES', $contents);
        $this->assertStringContainsString('6549699.731', $contents);
        $this->assertStringContainsString('4923604.537', $contents);
        $this->assertStringContainsString('SR1', $contents);
        $this->assertGreaterThanOrEqual(3, substr_count($contents, "CIRCLE\r\n8\r\nFTTH_SURVEY_POLES"));
        $this->assertGreaterThanOrEqual(12, substr_count($contents, "SOLID\r\n8\r\nFTTH_SURVEY_POLES"));
    }
}
