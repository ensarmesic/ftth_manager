<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Vite;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_receive_baseline_security_headers(): void
    {
        $response = $this->get('/prijava')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), payment=(), usb=()')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('X-Permitted-Cross-Domain-Policies', 'none')
            ->assertHeader('Content-Security-Policy');

        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        preg_match('/script-src ([^;]+)/', $policy, $scriptDirective);
        $this->assertStringNotContainsString("'unsafe-inline'", $scriptDirective[1]);
        $this->assertMatchesRegularExpression("/'nonce-[A-Za-z0-9+\/=]+' /", $scriptDirective[1].' ');
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

    #[DataProvider('vitePolicyScenarios')]
    public function test_vite_sources_are_allowed_only_during_local_development(string $environment, bool $runningHot, bool $allowed): void
    {
        $originalEnvironment = app()->environment();
        $originalHotFile = Vite::hotFile();
        $hotFile = tempnam(sys_get_temp_dir(), 'ftth-vite-');

        try {
            if ($runningHot) {
                file_put_contents($hotFile, "http://localhost:5173/\n");
            } else {
                unlink($hotFile);
            }
            Vite::useHotFile($hotFile);
            app()->instance('env', $environment);

            $response = $this->get('/prijava');
            $policy = (string) $response->headers->get('Content-Security-Policy');
            foreach (['script-src', 'style-src', 'font-src', 'connect-src'] as $directive) {
                preg_match('/'.preg_quote($directive, '/').' ([^;]+)/', $policy, $matches);
                $this->assertArrayHasKey(1, $matches);
                if ($allowed) {
                    $this->assertStringContainsString('http://localhost:5173', $matches[1]);
                } else {
                    $this->assertStringNotContainsString('http://localhost:5173', $matches[1]);
                }
            }
        } finally {
            Vite::useHotFile($originalHotFile);
            app()->instance('env', $originalEnvironment);
            if (is_file($hotFile)) {
                unlink($hotFile);
            }
        }
    }

    public static function vitePolicyScenarios(): array
    {
        return [
            'local with Vite' => ['local', true, true],
            'local without Vite' => ['local', false, false],
            'production with hot file' => ['production', true, false],
            'testing with hot file' => ['testing', true, false],
        ];
    }

    public function test_export_filename_is_sanitized_from_project_code(): void
    {
        $project = Project::factory()->create(['code' => 'FTTH " Test / 01']);

        $this->getJson(route('projects.geojson', $project))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="ftth-test-01-ftth.geojson"');
    }
}
