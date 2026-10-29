<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/** Full MySQL database backups made with mysqldump. */
class DatabaseDumper
{
    private const DONE_MARKER = '-- Dump completed';

    public const EXTENSION = 'sql';

    public function isSupported(): bool
    {
        return in_array($this->connection()['driver'] ?? null, ['mysql', 'mariadb'], true);
    }

    /** Writes a checked dump to $destination; throws when it could not be made or is incomplete. */
    public function dump(string $destination): void
    {
        if (! $this->isSupported()) {
            throw new RuntimeException('Backup podržava samo MySQL bazu.');
        }
        $connection = $this->connection();
        $env = ['MYSQL_PWD' => (string) ($connection['password'] ?? '')];
        if (PHP_OS_FAMILY === 'Windows') {
            // `artisan serve` strips the environment; without SystemRoot Winsock cannot open a socket (error 2004/10106).
            $env['SystemRoot'] = getenv('SystemRoot') ?: (getenv('windir') ?: 'C:\\Windows');
        }
        $result = Process::env($env)
            ->timeout(600)
            ->run([
                $this->mysqldumpBinary(),
                '--host='.($connection['host'] ?? '127.0.0.1'),
                '--port='.($connection['port'] ?? 3306),
                '--user='.($connection['username'] ?? 'root'),
                '--single-transaction',
                '--quick',
                '--routines',
                '--triggers',
                '--no-tablespaces',
                ...($connection['driver'] === 'mysql' ? ['--set-gtid-purged=OFF'] : []),
                '--default-character-set=utf8mb4',
                '--result-file='.$destination,
                (string) $connection['database'],
            ]);
        if (! $result->successful()) {
            throw new RuntimeException('mysqldump nije uspio: '.trim($result->errorOutput() ?: $result->output()));
        }
        $this->verify($destination);
    }

    /** A dump is restorable when it has the mysqldump header and its closing marker. */
    public function verify(string $path): void
    {
        $size = filesize($path) ?: 0;
        $handle = fopen($path, 'rb');
        $head = (string) fread($handle, 4096);
        fseek($handle, max(0, $size - 512));
        $tail = (string) fread($handle, 512);
        fclose($handle);
        if (! str_contains($head, 'MySQL dump') || ! str_contains($tail, self::DONE_MARKER)) {
            throw new RuntimeException('SQL dump nije potpun.');
        }
    }

    /** @return array{label: string, engine: string, exists: bool, size: int|null, modifiedAt: int|null} */
    public function info(): array
    {
        $database = (string) ($this->connection()['database'] ?? '');
        $stats = DB::selectOne('SELECT COALESCE(SUM(data_length + index_length), 0) AS size, MAX(COALESCE(update_time, create_time)) AS modified FROM information_schema.tables WHERE table_schema = ?', [$database]);

        return [
            'label' => 'MySQL · '.$database,
            'engine' => trim(DB::connection()->getDriverTitle().' '.DB::connection()->getServerVersion()),
            'exists' => true,
            'size' => (int) $stats->size,
            'modifiedAt' => $stats->modified ? strtotime((string) $stats->modified) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function connection(): array
    {
        return (array) config('database.connections.'.config('database.default'));
    }

    private function mysqldumpBinary(): string
    {
        $configured = config('database.mysqldump_path');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        // Windows installs MySQL outside PATH.
        $installed = File::glob('C:/Program Files/MySQL/MySQL Server */bin/mysqldump.exe');

        return $installed ? end($installed) : 'mysqldump';
    }
}
