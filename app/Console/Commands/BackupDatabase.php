<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
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
            $this->uploadEncryptedCopy($destination);
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

    private function uploadEncryptedCopy(string $source): void
    {
        $disk = config('database.backup_disk');
        if (! is_string($disk) || $disk === '') {
            return;
        }
        if (! function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
            throw new \RuntimeException('Sodium ekstenzija je potrebna za enkriptovani udaljeni backup.');
        }
        $secret = (string) config('database.backup_encryption_key');
        if (strlen($secret) < 32) {
            throw new \RuntimeException('FTTH_BACKUP_ENCRYPTION_KEY mora imati najmanje 32 znaka.');
        }

        $encrypted = $source.'.enc.partial';
        $input = fopen($source, 'rb');
        $output = fopen($encrypted, 'wb');
        $key = hash('sha256', $secret, true);
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        fwrite($output, 'FTTHENC1'.$header);
        while (! feof($input)) {
            $chunk = fread($input, 1024 * 1024);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $tag = feof($input) ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : 0;
            fwrite($output, sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag));
        }
        fclose($input);
        fclose($output);

        $directory = trim((string) config('database.backup_remote_directory', 'ftth-backups'), '/');
        $remoteName = $directory.'/'.basename($source).'.enc';
        $stream = fopen($encrypted, 'rb');
        try {
            if (! Storage::disk($disk)->writeStream($remoteName, $stream)) {
                throw new \RuntimeException('Udaljena pohrana je odbila backup datoteku.');
            }
            Storage::disk($disk)->put($remoteName.'.sha256', hash_file('sha256', $encrypted).'  '.basename($remoteName).PHP_EOL);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            File::delete($encrypted);
        }
        $this->line("Enkriptovana udaljena kopija: {$disk}:{$remoteName}");
    }
}
