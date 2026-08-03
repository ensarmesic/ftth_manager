<?php

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_map_layer_rejects_unapproved_file_extensions(): void
    {
        $this->postJson(route('map.dxf-layer.upload'), [
            'file' => UploadedFile::fake()->create('podloga.php', 10, 'text/plain'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_gis_import_rejects_unapproved_file_extensions(): void
    {
        $project = Project::factory()->create();

        $this->post(route('gis.import'), [
            'project_id' => $project->id,
            'geojson' => UploadedFile::fake()->create('sloj.txt', 10, 'text/plain'),
        ])->assertSessionHasErrors('geojson');
    }

    public function test_route_import_rejects_unapproved_file_extensions(): void
    {
        $project = Project::factory()->create();

        $this->post(route('routes.dxf.import'), [
            'project_id' => $project->id,
            'dxf' => UploadedFile::fake()->create('trase.txt', 10, 'text/plain'),
        ])->assertSessionHasErrors('dxf');
    }

    public function test_valid_dxf_map_layer_is_parsed_and_cached_for_export(): void
    {
        Storage::fake('local');
        $dxf = implode("\n", [
            '0', 'SECTION', '2', 'ENTITIES',
            '0', 'LINE', '8', 'TEST_LAYER',
            '10', '18.6490', '20', '44.4490',
            '11', '18.6500', '21', '44.4500',
            '0', 'ENDSEC', '0', 'EOF',
        ]);

        $response = $this->postJson(route('map.dxf-layer.upload'), [
            'file' => UploadedFile::fake()->createWithContent('podloga.dxf', $dxf),
        ])->assertOk()
            ->assertJsonPath('type', 'FeatureCollection')
            ->assertJsonPath('features.0.geometry.type', 'LineString')
            ->assertJsonPath('features.0.properties.layer', 'TEST_LAYER');

        $cacheKey = $response->json('_cache_key');
        $this->assertNotEmpty($cacheKey);
        Storage::disk('local')->assertExists("dxf_layers/{$cacheKey}.json");
    }

    public function test_dwg_map_layer_returns_actionable_conversion_message(): void
    {
        $this->postJson(route('map.dxf-layer.upload'), [
            'file' => UploadedFile::fake()->create('podloga.dwg', 10, 'application/octet-stream'),
        ])->assertUnprocessable()
            ->assertJsonPath('error', 'DWG nije podržan. Sačuvaj fajl kao DXF (Save As → DXF) iz AutoCAD-a/FreeCAD-a i pokušaj ponovo.');
    }
}
