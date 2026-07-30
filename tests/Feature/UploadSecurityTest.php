<?php

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
}
