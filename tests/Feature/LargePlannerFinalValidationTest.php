<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\Odf;
use App\Models\Project;
use App\Services\LargePlanner\FinalValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LargePlannerFinalValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_preview_passes_final_validation(): void
    {
        [$project, $preview] = $this->validPreview();

        $result = app(FinalValidationService::class)->validate($project, $preview);

        $this->assertTrue($result['valid']);
        $this->assertSame(0, $result['summary']['errors']);
        $this->assertSame(3, $result['summary']['routes']);
    }

    public function test_duplicate_house_capacity_and_missing_parent_block_confirmation(): void
    {
        [$project, $preview] = $this->validPreview();
        $houseId = $preview['odo_placement']['odos'][0]['house_ids'][0];
        $preview['odo_placement']['odos'][] = [
            'key' => 'odo-0002', 'capacity' => 0, 'occupancy' => 1, 'house_ids' => [$houseId],
        ];
        $preview['routes']['secondary_routes'] = [];

        $result = app(FinalValidationService::class)->validate($project, $preview);

        $this->assertFalse($result['valid']);
        $this->assertContains('odo_capacity_exceeded', array_column($result['errors'], 'code'));
        $this->assertContains('invalid_odo_parent', array_column($result['errors'], 'code'));
        $this->assertContains('invalid_house_assignment', array_column($result['errors'], 'code'));
    }

    public function test_critical_preview_warning_blocks_confirmation(): void
    {
        [$project, $preview] = $this->validPreview();
        $preview['warnings']['items'][] = ['severity' => 'error', 'code' => 'cable_capacity_exceeded', 'message' => 'Kapacitet je prekoračen.'];

        $result = app(FinalValidationService::class)->validate($project, $preview);

        $this->assertFalse($result['valid']);
        $this->assertSame('preview_error', collect($result['errors'])->firstWhere('details.source_code', 'cable_capacity_exceeded')['code']);
    }

    public function test_cross_project_odf_unknown_keys_and_primary_cycle_are_blocked(): void
    {
        [$project, $preview] = $this->validPreview();
        $foreignOdf = Odf::factory()->create();
        $preview['routes']['secondary_routes'][0]['odf_key'] = null;
        $preview['routes']['secondary_routes'][0]['odf_id'] = $foreignOdf->id;
        $preview['routes']['drop_routes'][0]['odo_key'] = 'odo-nepoznat';
        $preview['odf_placement']['odfs'][] = ['key' => 'odf-0002'];
        $preview['odf_placement']['primary_routes'] = [
            ['key' => 'p1', 'from_odf_key' => 'odf-0001', 'to_odf_key' => 'odf-0002', 'path' => [[1, 1], [2, 2]], 'length_m' => 1],
            ['key' => 'p2', 'from_odf_key' => 'odf-0002', 'to_odf_key' => 'odf-0001', 'path' => [[2, 2], [1, 1]], 'length_m' => 1],
        ];

        $result = app(FinalValidationService::class)->validate($project, $preview);
        $codes = array_column($result['errors'], 'code');

        $this->assertContains('invalid_odf_reference', $codes);
        $this->assertContains('invalid_drop_reference', $codes);
        $this->assertContains('odf_cycle', $codes);
    }

    public function test_route_that_does_not_touch_its_elements_is_blocked(): void
    {
        [$project, $preview] = $this->validPreview();
        $preview['routes']['drop_routes'][0]['path'] = [[44.0, 19.0], [44.1, 19.1]];

        $result = app(FinalValidationService::class)->validate($project, $preview);

        $this->assertFalse($result['valid']);
        $this->assertContains('route_endpoint_mismatch', array_column($result['errors'], 'code'));
    }

    private function validPreview(): array
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $existingOdf = Odf::factory()->create(['project_id' => $project->id, 'latitude' => 43.849, 'longitude' => 18.409]);
        $house = House::factory()->create(['project_id' => $project->id, 'latitude' => 43.852, 'longitude' => 18.412]);
        $line = [[43.85, 18.41], [43.851, 18.411]];
        $primaryLine = [[43.849, 18.409], [43.85, 18.41]];
        $dropLine = [[43.851, 18.411], [43.852, 18.412]];
        $preview = [
            'project_id' => $project->id,
            'odo_placement' => ['odos' => [[
                'key' => 'odo-0001', 'point' => [43.851, 18.411], 'capacity' => 8, 'occupancy' => 1, 'house_ids' => [$house->id],
            ]]],
            'odf_placement' => ['odfs' => [['key' => 'odf-0001', 'point' => [43.85, 18.41]]], 'primary_routes' => [[
                'key' => 'primary-1', 'from_odf_id' => $existingOdf->id, 'from_odf_key' => null, 'to_odf_key' => 'odf-0001', 'path' => $primaryLine, 'length_m' => 100,
            ]]],
            'routes' => [
                'secondary_routes' => [['key' => 'secondary-1', 'odo_key' => 'odo-0001', 'odf_id' => null, 'odf_key' => 'odf-0001', 'path' => $line, 'length_m' => 100]],
                'drop_routes' => [['key' => 'drop-1', 'odo_key' => 'odo-0001', 'house_id' => $house->id, 'path' => $dropLine, 'length_m' => 100]],
            ],
            'warnings' => ['items' => []],
        ];

        return [$project, $preview];
    }
}
