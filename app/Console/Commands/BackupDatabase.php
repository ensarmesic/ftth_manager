<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PDO;
use Throwable;

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

        $directory = (string) config('database.backup_directory', storage_path('app/private/backups'));
        File::ensureDirectoryExists($directory);
        $destination = $directory.'/database-'.now()->format('Y-m-d_His_u').'.sqlite';
        $partial = $destination.'.partial';

        try {
            $sourceConnection = new PDO('sqlite:'.$source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $sourceConnection->exec('PRAGMA busy_timeout = 5000');
            $quotedDestination = str_replace("'", "''", $partial);
            $sourceConnection->exec("VACUUM INTO '{$quotedDestination}'");
            $sourceConnection = null;

            $backupConnection = new PDO('sqlite:'.$partial, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $integrity = $backupConnection->query('PRAGMA quick_check')->fetchColumn();
            if ($integrity !== 'ok') {
                throw new \RuntimeException('SQLite quick_check nije prošao: '.(string) $integrity);
            }
            $backupConnection = null;

            File::move($partial, $destination);
            File::put($destination.'.sha256', hash_file('sha256', $destination).'  '.basename($destination).PHP_EOL);
        } catch (Throwable $exception) {
            File::delete([$partial, $destination, $destination.'.sha256']);
            report($exception);
            $this->error('Backup nije kreiran ili nije prošao provjeru integriteta: '.$exception->getMessage());

            return self::FAILURE;
        }

        collect(File::files($directory))
            ->filter(fn ($file): bool => str_starts_with($file->getFilename(), 'database-') && $file->getExtension() === 'sqlite')
            ->sortByDesc(fn ($file): int => $file->getMTime())
            ->slice(max(1, (int) $this->option('keep')))
            ->each(fn ($file) => File::delete([$file->getPathname(), $file->getPathname().'.sha256']));

        $this->info('Backup baze je sačuvan: '.basename($destination));

        return self::SUCCESS;
    }
}
