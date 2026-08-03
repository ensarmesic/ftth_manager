<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_receive_baseline_security_headers(): void
    {
        $this->get('/prijava')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), payment=(), usb=()');
    }

    public function test_authenticated_html_pages_are_not_browser_cached(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/projekti')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache');
    }

    public function test_export_filename_is_sanitized_from_project_code(): void
    {
        $project = Project::factory()->create(['code' => 'FTTH " Test / 01']);

        $this->getJson(route('projects.geojson', $project))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="ftth-test-01-ftth.geojson"');
    }
}
