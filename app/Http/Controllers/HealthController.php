<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        DB::select('select 1');
        $latestBackup = collect(File::glob(storage_path('app/private/backups/database-*.sqlite')))
            ->sortByDesc(fn (string $file): int => File::lastModified($file))
            ->first();
        $backupAgeHours = $latestBackup ? round((time() - File::lastModified($latestBackup)) / 3600, 1) : null;
        $checksumFile = $latestBackup ? $latestBackup.'.sha256' : null;
        $expectedChecksum = $checksumFile && File::isFile($checksumFile)
            ? strtok(trim(File::get($checksumFile)), " \t")
            : null;
        $backupChecksumValid = $latestBackup && $expectedChecksum
            ? hash_equals($expectedChecksum, hash_file('sha256', $latestBackup))
            : false;
        $backupStatus = ! $latestBackup ? 'missing' : (! $backupChecksumValid ? 'invalid' : ($backupAgeHours > 26 ? 'stale' : 'ok'));

        $heartbeatFile = storage_path('app/private/health/scheduler-heartbeat.json');
        $schedulerHeartbeat = File::isFile($heartbeatFile) ? json_decode(File::get($heartbeatFile), true) : null;
        $schedulerAgeHours = File::isFile($heartbeatFile) ? round((time() - File::lastModified($heartbeatFile)) / 3600, 1) : null;

        return response()->json([
            'status' => 'ok',
            'application' => config('app.name'),
            'version' => config('app.version'),
            'environment' => app()->environment(),
            'database' => 'ok',
            'last_database_backup' => $latestBackup ? date(DATE_ATOM, File::lastModified($latestBackup)) : null,
            'database_backup' => [
                'status' => $backupStatus,
                'age_hours' => $backupAgeHours,
                'checksum_valid' => $backupChecksumValid,
            ],
            'scheduler' => [
                'status' => $schedulerAgeHours === null ? 'unknown' : ($schedulerAgeHours > 26 ? 'stale' : 'ok'),
                'last_task' => $schedulerHeartbeat['task'] ?? null,
                'last_completed_at' => $schedulerHeartbeat['completed_at'] ?? null,
                'age_hours' => $schedulerAgeHours,
            ],
            'deployed_at' => config('app.deployed_at'),
            'checked_at' => now()->toIso8601String(),
        ]);
    }
}
