<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PDO;
use Throwable;

class VerifyDatabaseBackup extends Command
{
    protected $signature = 'ftth:verify-backup {path? : Putanja do SQLite kopije; zadana je najnovija} {--output= : Sačuvaj dekriptovanu SQLite kopiju na ovu putanju}';

    protected $description = 'Provjeri checksum, SQLite integritet i probno otvaranje sigurnosne kopije';

    public function handle(): int
    {
        $path = $this->argument('path');
        if (! is_string($path) || $path === '') {
            $path = collect(File::glob(config('database.backup_directory').'/database-*.sqlite'))->sortDesc()->first();
        }
        if (! is_string($path) || ! File::isFile($path)) {
            $this->error('Nije pronađena sigurnosna kopija za provjeru.');

            return self::FAILURE;
        }

        $temporary = null;
        try {
            $checksumPath = $path.'.sha256';
            if (! File::isFile($checksumPath)) {
                throw new \RuntimeException('Nedostaje checksum datoteka.');
            }
            $expected = strtok(trim(File::get($checksumPath)), " \t");
            if (! is_string($expected) || ! hash_equals($expected, hash_file('sha256', $path))) {
                throw new \RuntimeException('Checksum kopije nije ispravan.');
            }
            $databasePath = $path;
            if (str_ends_with($path, '.enc')) {
                $requestedOutput = $this->option('output');
                $databasePath = is_string($requestedOutput) && $requestedOutput !== ''
                    ? $requestedOutput
                    : storage_path('framework/cache/backup-verify-'.bin2hex(random_bytes(8)).'.sqlite');
                File::ensureDirectoryExists(dirname($databasePath));
                $this->decrypt($path, $databasePath);
                $temporary = $requestedOutput ? null : $databasePath;
            }
            $database = new PDO('sqlite:'.$databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $result = $database->query('PRAGMA quick_check')->fetchColumn();
            if ($result !== 'ok') {
                throw new \RuntimeException('SQLite quick_check: '.(string) $result);
            }
            $database->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll();
            $database = null;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Backup nije upotrebljiv: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($temporary) {
                File::delete($temporary);
            }
        }

        $this->info('Backup je ispravan i može se otvoriti: '.basename($path));
        if ($this->option('output')) {
            $this->info('Dekriptovana kopija je sačuvana: '.$this->option('output'));
        }

        return self::SUCCESS;
    }

    private function decrypt(string $source, string $destination): void
    {
        if (! function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_pull')) {
            throw new \RuntimeException('Sodium ekstenzija nije dostupna.');
        }
        $secret = (string) config('database.backup_encryption_key');
        if (strlen($secret) < 32) {
            throw new \RuntimeException('Nedostaje ispravan FTTH_BACKUP_ENCRYPTION_KEY.');
        }
        $input = fopen($source, 'rb');
        $output = fopen($destination, 'wb');
        if (fread($input, 8) !== 'FTTHENC1') {
            throw new \RuntimeException('Format enkriptovane kopije nije prepoznat.');
        }
        $header = fread($input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, hash('sha256', $secret, true));
        $final = false;
        while (! feof($input)) {
            $ciphertext = fread($input, 1024 * 1024 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
            if ($ciphertext === '' || $ciphertext === false) {
                break;
            }
            $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $ciphertext);
            if ($result === false) {
                throw new \RuntimeException('Enkriptovana kopija je oštećena ili je ključ pogrešan.');
            }
            [$plaintext, $tag] = $result;
            fwrite($output, $plaintext);
            $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
        }
        fclose($input);
        fclose($output);
        if (! $final) {
            throw new \RuntimeException('Enkriptovana kopija nema završni zapis.');
        }
    }
}
