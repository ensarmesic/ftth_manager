<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PDO;
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
            ->assertJsonStructure(['database_backup' => ['status', 'age_hours', 'checksum_valid'], 'scheduler' => ['status', 'last_task', 'last_completed_at', 'age_hours']])
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
        $backupDirectory = storage_path('framework/testing/database-backups');
        File::ensureDirectoryExists(dirname($source));
        File::delete($source);
        File::deleteDirectory($backupDirectory);
        $database = new PDO('sqlite:'.$source);
        $database->exec('CREATE TABLE restore_probe (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $database->exec("INSERT INTO restore_probe (value) VALUES ('ftth-test-database')");
        $database = null;
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', $source);
        config()->set('database.backup_directory', $backupDirectory);

        $this->artisan('ftth:backup-database --keep=1')->assertSuccessful();

        $copies = File::glob($backupDirectory.'/database-*.sqlite');
        $this->assertNotEmpty($copies);
        $copy = collect($copies)->sortDesc()->first();
        $this->assertFileExists($copy.'.sha256');
        $this->assertStringStartsWith(hash_file('sha256', $copy), File::get($copy.'.sha256'));

        // Probno vraćanje ne koristi aktivnu konekciju: kopija se otvara kao
        // zasebna baza i iz nje se čita zapis koji postoji samo u izvoru.
        $restored = new PDO('sqlite:'.$copy);
        $this->assertSame('ok', $restored->query('PRAGMA quick_check')->fetchColumn());
        $this->assertSame('ftth-test-database', $restored->query('SELECT value FROM restore_probe')->fetchColumn());
        $restored = null;

        File::delete($source);
        File::deleteDirectory($backupDirectory);
    }

    public function test_responses_expose_server_timing_for_diagnostics(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertHeader('Server-Timing');
    }

    public function test_scheduler_contains_backup_integrity_audit_and_cache_maintenance(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('ftth:backup-database --keep=14')
            ->expectsOutputToContain('ftth:audit-integrity')
            ->expectsOutputToContain('ftth:prune-dxf-cache --days=30')
            ->assertSuccessful();
    }
}
