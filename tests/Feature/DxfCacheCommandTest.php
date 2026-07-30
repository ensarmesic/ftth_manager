<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DxfCacheCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_command_only_deletes_stale_dxf_cache_files(): void
    {
        Storage::fake();
        Storage::put('dxf_layers/old.json', '{}');
        Storage::put('dxf_layers/current.json', '{}');
        touch(Storage::path('dxf_layers/old.json'), now()->subDays(31)->timestamp);

        $this->artisan('ftth:prune-dxf-cache --days=30')->assertSuccessful();

        Storage::assertMissing('dxf_layers/old.json');
        Storage::assertExists('dxf_layers/current.json');
    }

    public function test_prune_dry_run_does_not_delete_files(): void
    {
        Storage::fake();
        Storage::put('dxf_layers/old.json', '{}');
        touch(Storage::path('dxf_layers/old.json'), now()->subDays(31)->timestamp);

        $this->artisan('ftth:prune-dxf-cache --days=30 --dry-run')->assertSuccessful();

        Storage::assertExists('dxf_layers/old.json');
    }
}
