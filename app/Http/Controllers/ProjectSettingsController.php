<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Services\DatabaseDumper;
use App\Services\ProjectHealthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProjectSettingsController extends Controller
{
    public function projectCheck(ProjectHealthService $health): View
    {
        $projects = Project::query()->withCount([
            'odfs', 'cabinets', 'houses',
            'routes as network_routes_count' => fn ($query) => $query->where('route_type', '!=', 'trench'),
        ])->orderBy('name')->get();

        return view('ftth.project-check', ['projects' => $projects, 'health' => $health->forProjects($projects)]);
    }

    public function settings(Request $request, ActivityLogController $activityLogs, DatabaseDumper $dumper): View
    {
        return view('ftth.settings', [
            'activityLogs' => $activityLogs->filtered($request)->with(['user:id,name', 'project:id,name'])->latest()->paginate(25)->withQueryString(),
            'projects' => Project::query()->orderBy('name')->get(['id', 'name']),
            'users' => User::query()->orderByDesc('is_active')->orderBy('name')->get(),
            'sessions' => DB::table(config('session.table', 'sessions'))
                ->leftJoin('users', 'sessions.user_id', '=', 'users.id')
                ->select('sessions.id', 'sessions.user_id', 'sessions.ip_address', 'sessions.user_agent', 'sessions.last_activity', 'users.name as user_name')
                ->latest('sessions.last_activity')->get(),
            'currentSessionId' => $request->session()->getId(),
            'databaseInfo' => $dumper->info(),
        ]);
    }

    public function backup(DatabaseDumper $dumper): BinaryFileResponse
    {
        if (! $dumper->isSupported()) {
            abort(404, 'Baza podataka nije pronađena.');
        }

        $extension = DatabaseDumper::EXTENSION;
        $directory = storage_path('framework/cache');
        File::ensureDirectoryExists($directory);
        $temporary = $directory.'/download-backup-'.bin2hex(random_bytes(8)).'.'.$extension;
        try {
            $dumper->dump($temporary);
        } catch (\Throwable $exception) {
            File::delete($temporary);
            report($exception);
            abort(500, 'Backup nije moguće napraviti: '.$exception->getMessage());
        }

        return response()->download($temporary, 'ftth-backup-'.now()->format('Y-m-d-His').'.'.$extension, [
            'Content-Type' => 'application/octet-stream',
        ])->deleteFileAfterSend();
    }
}
