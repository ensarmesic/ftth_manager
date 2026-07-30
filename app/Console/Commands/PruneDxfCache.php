<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneDxfCache extends Command
{
    protected $signature = 'ftth:prune-dxf-cache {--days=30 : Minimalna starost datoteke} {--dry-run : Samo prikaži šta bi bilo obrisano}';

    protected $description = 'Delete stale server-side DXF layer cache files';

    public function handle(): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        if ($days === false || $days < 1) {
            $this->error('Opcija --days mora biti cijeli broj veći od nule.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days)->timestamp;
        $files = collect(Storage::files('dxf_layers'))
            ->filter(fn (string $file): bool => Storage::lastModified($file) < $cutoff);
        $bytes = $files->sum(fn (string $file): int => Storage::size($file));

        if (! $this->option('dry-run') && $files->isNotEmpty()) {
            Storage::delete($files->all());
        }

        $verb = $this->option('dry-run') ? 'Pronađeno' : 'Obrisano';
        $this->info(sprintf('%s %d DXF cache datoteka (%.2f MB).', $verb, $files->count(), $bytes / 1048576));

        return self::SUCCESS;
    }
}
