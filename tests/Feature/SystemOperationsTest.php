<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SystemOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_administrator_can_read_health_status(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson(route('system.health'))
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('database', 'ok')
            ->assertJsonPath('version', config('app.version'));
    }

    public function test_health_status_is_not_public(): void
    {
        auth()->logout();
        $this->get(route('system.health'))->assertRedirect(route('login'));
    }

    public function test_database_backup_command_creates_a_rotatable_copy(): void
    {
        $source = storage_path('framework/testing/backup-source.sqlite');
        File::ensureDirectoryExists(dirname($source));
        File::put($source, 'ftth-test-database');
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', $source);

        $this->artisan('ftth:backup-database --keep=1')->assertSuccessful();

        $copies = File::glob(storage_path('app/private/backups/database-*.sqlite'));
        $this->assertNotEmpty($copies);
        $this->assertSame('ftth-test-database', File::get(collect($copies)->sortDesc()->first()));

        File::delete($source);
        collect($copies)->each(fn (string $copy) => File::delete($copy));
    }

    public function test_responses_expose_server_timing_for_diagnostics(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertHeader('Server-Timing');
    }
}
