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
    public function projectCheck(Request $request, ProjectHealthService $health): View
    {
        $projects = Project::query()->withCount([
            'odfs', 'cabinets', 'houses',
            'routes as network_routes_count' => fn ($query) => $query->where('route_type', '!=', 'trench'),
        ])->orderBy('name')->get();
        $selected = $request->integer('project') > 0 ? $projects->firstWhere('id', $request->integer('project')) : null;

        if ($selected) {
            $result = $health->forProject($selected);

            return view('ftth.project-check', [
                'projects' => $projects,
                'selectedProject' => $selected,
                'result' => $result,
                'groups' => $this->checkGroups($result['items']),
            ]);
        }

        $all = $health->forProjects($projects);

        return view('ftth.project-check', [
            'projects' => $projects,
            'selectedProject' => null,
            'overview' => $projects->map(fn (Project $project) => [
                'project' => $project,
                'health' => $all[$project->id],
                // The two most frequent errors/warnings, to show what to fix first.
                'top' => array_slice(array_values(array_filter(
                    $this->checkGroups($all[$project->id]['items']),
                    fn (array $group) => $group['level'] !== 'info',
                )), 0, 2),
            ])->all(),
        ]);
    }

    /**
     * Findings grouped by check: the worst level first, then the most frequent.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function checkGroups(array $items): array
    {
        $rank = ['error' => 0, 'warning' => 1, 'info' => 2];

        return collect($items)
            ->groupBy(fn (array $item) => $item['rule'] ?? $item['message'])
            ->map(function ($group, string $rule) use ($rank): array {
                $level = $group->sortBy(fn (array $item) => $rank[$item['level']] ?? 3)->first()['level'];
                $recommendations = $group->pluck('recommendation')->unique();

                return [
                    'rule' => $rule,
                    'label' => $group->first()['rule_label'] ?? $group->first()['message'],
                    'level' => $level,
                    'element_type' => $group->first()['element_type'],
                    'count' => $group->count(),
                    // One shared advice is shown once on the group; otherwise per finding.
                    'recommendation' => $recommendations->count() === 1 ? $recommendations->first() : null,
                    'items' => $group->values()->all(),
                ];
            })
            ->sortBy([fn (array $a, array $b) => ($rank[$a['level']] ?? 3) <=> ($rank[$b['level']] ?? 3), fn (array $a, array $b) => $b['count'] <=> $a['count']])
            ->values()
            ->all();
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
