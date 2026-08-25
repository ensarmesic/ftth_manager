<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ProjectSettingsController extends Controller
{
    public function projectCheck(): View
    {
        $projects = Project::with([
            'odfs',
            'cabinets' => fn ($query) => $query->withCount('houses'),
            'houses',
            'routes',
        ])->withCount(['odfs', 'cabinets', 'houses', 'routes'])->orderBy('name')->get();

        return view('ftth.project-check', ['projects' => $projects]);
    }

    public function settings(): View
    {
        $databasePath = $this->sqliteDatabasePath();

        return view('ftth.settings', [
            'activityLogs' => ActivityLog::with('user')->latest()->limit(50)->get(),
            'projects' => Project::query()->orderBy('name')->get(['id', 'name']),
            'databaseInfo' => [
                'exists' => is_string($databasePath) && is_file($databasePath),
                'size' => is_string($databasePath) && is_file($databasePath) ? filesize($databasePath) : null,
                'modifiedAt' => is_string($databasePath) && is_file($databasePath) ? filemtime($databasePath) : null,
            ],
        ]);
    }

    public function backup()
    {
        $dbPath = $this->sqliteDatabasePath();
        if (! $dbPath || ! is_file($dbPath)) {
            abort(404, 'Baza podataka nije pronađena.');
        }

        if (! app()->runningUnitTests()) {
            DB::statement('PRAGMA wal_checkpoint(FULL)');
        }
        $filename = 'ftth-backup-'.now()->format('Y-m-d-His').'.sqlite';

        return response()->download($dbPath, $filename, [
            'Content-Type' => 'application/octet-stream',
        ]);
    }

    private function sqliteDatabasePath(): ?string
    {
        $path = config('database.connections.sqlite.database');
        if (! is_string($path) || $path === '' || $path === ':memory:') {
            return null;
        }

        $isAbsolute = preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/]{2}|/)~', $path) === 1;

        return $isAbsolute ? $path : base_path($path);
    }
}
