<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrityAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_integrity_audit_passes_for_valid_network(): void
    {
        $project = Project::factory()->create();
        $odf = Odf::factory()->for($project)->create();
        Cabinet::factory()->for($project)->create(['odf_id' => $odf->id]);
        NetworkRoute::factory()->for($project)->create(['odf_id' => $odf->id]);

        $this->artisan('ftth:audit-integrity', ['--project' => $project->id])
            ->expectsOutputToContain('nema pronađenih problema')
            ->assertSuccessful();
    }

    public function test_integrity_audit_fails_for_cross_project_link_and_bad_geometry(): void
    {
        $first = Project::factory()->create();
        $second = Project::factory()->create();
        $foreignOdf = Odf::factory()->for($second)->create();
        Cabinet::factory()->for($first)->create(['odf_id' => $foreignOdf->id]);
        NetworkRoute::factory()->for($first)->create(['path' => [[999, 999]]]);

        $this->artisan('ftth:audit-integrity', ['--project' => $first->id])
            ->expectsOutputToContain('pronađeno 2 problema')
            ->assertFailed();
    }
}
