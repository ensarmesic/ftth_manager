<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class BackupDatabase extends Command
{
    protected $signature = 'ftth:backup-database {--keep=14 : Broj dnevnih kopija koje se čuvaju}';

    protected $description = 'Napravi sigurnosnu kopiju SQLite baze i ukloni zastarjele kopije';

    public function handle(): int
    {
        if (config('database.default') !== 'sqlite') {
            $this->error('Automatski backup trenutno podržava SQLite bazu.');

            return self::FAILURE;
        }

        $source = (string) config('database.connections.sqlite.database');
        if (! File::isFile($source)) {
            $this->error("Baza nije pronađena: {$source}");

            return self::FAILURE;
        }

        $directory = storage_path('app/private/backups');
        File::ensureDirectoryExists($directory);
        $destination = $directory.'/database-'.now()->format('Y-m-d_His').'.sqlite';
        File::copy($source, $destination);

        collect(File::files($directory))
            ->filter(fn ($file): bool => str_starts_with($file->getFilename(), 'database-'))
            ->sortByDesc(fn ($file): int => $file->getMTime())
            ->slice(max(1, (int) $this->option('keep')))
            ->each(fn ($file) => File::delete($file->getPathname()));

        $this->info('Backup baze je sačuvan: '.basename($destination));

        return self::SUCCESS;
    }
}
