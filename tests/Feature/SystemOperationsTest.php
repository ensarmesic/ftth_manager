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
        $backupDirectory = storage_path('framework/testing/database-backups');
        File::deleteDirectory($backupDirectory);
        config()->set('database.backup_directory', $backupDirectory);

        $this->artisan('ftth:backup-database --keep=1')->assertSuccessful();
        $this->artisan('ftth:backup-database --keep=1')->assertSuccessful();

        // --keep=1 leaves only the newest dump.
        $copies = File::glob($backupDirectory.'/database-*.sql');
        $this->assertCount(1, $copies);
        $copy = $copies[0];
        $this->assertFileExists($copy.'.sha256');
        $this->assertStringStartsWith(hash_file('sha256', $copy), File::get($copy.'.sha256'));
        $dump = File::get($copy);
        $this->assertStringContainsString('CREATE TABLE `projects`', $dump);
        $this->assertStringContainsString('-- Dump completed', $dump);

        $this->artisan('ftth:verify-backup', ['path' => $copy])->assertSuccessful();

        File::deleteDirectory($backupDirectory);
    }

    public function test_verify_backup_rejects_an_incomplete_dump(): void
    {
        $backupDirectory = storage_path('framework/testing/broken-backups');
        File::deleteDirectory($backupDirectory);
        File::ensureDirectoryExists($backupDirectory);
        $broken = $backupDirectory.'/database-broken.sql';
        File::put($broken, '-- MySQL dump 10.13
CREATE TABLE `projects` (
');
        File::put($broken.'.sha256', hash_file('sha256', $broken).'  database-broken.sql'.PHP_EOL);

        $this->artisan('ftth:verify-backup', ['path' => $broken])->assertFailed();

        File::deleteDirectory($backupDirectory);
    }

    public function test_database_backup_download_is_a_mysql_dump(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('settings.backup'));

        $response->assertOk()->assertDownload();
        $this->assertSame('application/octet-stream', $response->headers->get('content-type'));
        $download = $response->baseResponse->getFile()->getPathname();
        $this->assertStringEndsWith('.sql', $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('-- MySQL dump', (string) File::get($download));

        File::delete($download);
    }

    public function test_responses_expose_server_timing_for_diagnostics(): void
    {
        $timing = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))->assertOk()
            ->headers->get('Server-Timing');

        $this->assertMatchesRegularExpression('/^db;dur=[\d.]+, app;dur=[\d.]+$/', (string) $timing);
    }

    public function test_scheduler_contains_backup_integrity_audit_and_cache_maintenance(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('ftth:backup-database --keep=14')
            ->expectsOutputToContain('ftth:verify-backup')
            ->expectsOutputToContain('ftth:audit-integrity')
            ->expectsOutputToContain('ftth:prune-dxf-cache --days=30')
            ->assertSuccessful();
    }

    public function test_database_backup_can_upload_an_encrypted_remote_copy(): void
    {
        if (! function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
            $this->markTestSkipped('Sodium nije dostupan.');
        }
        $localDirectory = storage_path('framework/testing/encrypted-local');
        $remoteDirectory = storage_path('framework/testing/encrypted-remote');
        File::deleteDirectory($localDirectory);
        File::deleteDirectory($remoteDirectory);
        config()->set('database.backup_directory', $localDirectory);
        config()->set('database.backup_disk', 'backup-test');
        config()->set('database.backup_remote_directory', 'copies');
        config()->set('database.backup_encryption_key', str_repeat('k', 32));
        config()->set('filesystems.disks.backup-test', ['driver' => 'local', 'root' => $remoteDirectory, 'throw' => true]);

        $this->artisan('ftth:backup-database --keep=1')->assertSuccessful();

        $encrypted = collect(File::glob($remoteDirectory.'/copies/*.enc'))->first();
        $this->assertNotNull($encrypted);
        $contents = File::get($encrypted);
        $this->assertStringStartsWith('FTTHENC1', $contents);
        $this->assertStringNotContainsString('CREATE TABLE', $contents);
        $this->assertFileExists($encrypted.'.sha256');

        File::deleteDirectory($localDirectory);
        File::deleteDirectory($remoteDirectory);
    }
}
