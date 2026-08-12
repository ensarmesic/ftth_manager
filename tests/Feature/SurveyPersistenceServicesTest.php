<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\SurveyPoint;
use App\Services\SurveyBaseElementImportService;
use App\Services\SurveyNetworkPersistenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SurveyPersistenceServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_base_element_importer_persists_points_and_network_endpoints(): void
    {
        $project = Project::factory()->create();
        $points = [
            $this->point(1, 'cabinet', 'ZO 7', 44.45000, 18.65000),
            $this->point(2, 'odf', 'ODF 2', 44.45100, 18.65100),
            $this->point(3, 'sling', 'Kuca 3', 44.45200, 18.65200),
        ];

        $result = app(SurveyBaseElementImportService::class)->import(
            $project,
            $points,
            'base-batch',
            'survey.txt',
            5.0,
            15.0,
        );

        $this->assertSame(['points' => 3, 'cabinets' => 1, 'odfs' => 1, 'houses' => 1], $result['counts']);
        $this->assertCount(1, $result['cabinets']);
        $this->assertCount(1, $result['odfs']);
        $this->assertCount(1, $result['houses']);
        $this->assertSame(3, SurveyPoint::where('project_id', $project->id)->count());
        $this->assertDatabaseHas('cabinets', ['project_id' => $project->id, 'import_batch' => 'base-batch']);
        $this->assertDatabaseHas('odfs', ['project_id' => $project->id, 'import_batch' => 'base-batch']);
        $this->assertDatabaseHas('houses', ['project_id' => $project->id, 'import_batch' => 'base-batch']);
    }

    public function test_network_persistence_service_stores_trenches_and_appendix_items(): void
    {
        $project = Project::factory()->create();
        $points = [$this->point(1, 'manhole', 'Saht', 44.45000, 18.65000)];
        $network = [
            'trenches' => [[
                'path' => [[44.45000, 18.65000], [44.45010, 18.65010]],
                'length_m' => 13.7,
                'code' => 'Rov',
            ]],
            'ducts' => [],
        ];

        $counts = app(SurveyNetworkPersistenceService::class)->persist(
            $project,
            $points,
            $network,
            collect(),
            collect(),
            collect(),
            [],
            'network-batch',
            5.0,
            30.0,
        );

        $this->assertSame(1, $counts['trenches']);
        $this->assertSame(0, $counts['ducts']);
        $this->assertSame(1, $counts['manholes']);
        $this->assertDatabaseHas('routes', [
            'project_id' => $project->id,
            'route_type' => 'trench',
            'import_batch' => 'network-batch',
        ]);
        $this->assertDatabaseHas('project_appendix_items', [
            'project_id' => $project->id,
            'type' => 'manhole',
            'import_batch' => 'network-batch',
        ]);
    }

    private function point(int $number, string $kind, string $code, float $lat, float $lng): array
    {
        return [
            'point_no' => $number,
            'x' => 6549000.0 + $number,
            'y' => 4923000.0 + $number,
            'z' => 230.0,
            'lat' => $lat,
            'lng' => $lng,
            'code' => $code,
            'kind' => $kind,
            'prepared_sling' => false,
            'house_ref' => null,
            'house_ref_generated' => false,
        ];
    }
}
