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

        return response()->json([
            'status' => 'ok',
            'application' => config('app.name'),
            'version' => config('app.version'),
            'environment' => app()->environment(),
            'database' => 'ok',
            'last_database_backup' => $latestBackup ? date(DATE_ATOM, File::lastModified($latestBackup)) : null,
            'deployed_at' => config('app.deployed_at'),
            'checked_at' => now()->toIso8601String(),
        ]);
    }
}
