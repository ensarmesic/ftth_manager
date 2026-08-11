<?php

namespace App\Http\Controllers;

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
        return view('ftth.settings');
    }

    public function backup()
    {
        $dbPath = config('database.connections.sqlite.database');
        if (! file_exists($dbPath)) {
            abort(404, 'Baza podataka nije pronađena.');
        }

        DB::statement('PRAGMA wal_checkpoint(FULL)');
        $filename = 'ftth-backup-'.now()->format('Y-m-d-His').'.sqlite';

        return response()->download($dbPath, $filename, [
            'Content-Type' => 'application/octet-stream',
        ]);
    }
}
