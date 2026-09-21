<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
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

    public function settings(Request $request, ActivityLogController $activityLogs): View
    {
        $databasePath = $this->sqliteDatabasePath();

        return view('ftth.settings', [
            'activityLogs' => $activityLogs->filtered($request)->with(['user:id,name', 'project:id,name'])->latest()->paginate(25)->withQueryString(),
            'projects' => Project::query()->orderBy('name')->get(['id', 'name']),
            'users' => User::query()->orderByDesc('is_active')->orderBy('name')->get(),
            'sessions' => DB::table(config('session.table', 'sessions'))
                ->leftJoin('users', 'sessions.user_id', '=', 'users.id')
                ->select('sessions.id', 'sessions.user_id', 'sessions.ip_address', 'sessions.user_agent', 'sessions.last_activity', 'users.name as user_name')
                ->latest('sessions.last_activity')->get(),
            'currentSessionId' => $request->session()->getId(),
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
