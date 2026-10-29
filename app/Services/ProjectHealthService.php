<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * One project check for every page (dashboard, Provjera projekta, Izvještaji, project overview).
 * Results of ProjectValidationService are cached per project and recomputed only when the
 * project or one of its network elements changes.
 */
class ProjectHealthService
{
    /** Tables whose rows the validation reads; a change in any of them invalidates the cached result. */
    private const WATCHED_TABLES = ['odfs', 'cabinets', 'houses', 'routes', 'large_planner_settings'];

    private const CACHE_VERSION = 'v1';

    public function __construct(private readonly ProjectValidationService $validation) {}

    /**
     * @param  iterable<Project>  $projects
     * @return array<int, array{errors: int, warnings: int, infos: int, issues: int, items: list<array<string, mixed>>}>
     */
    public function forProjects(iterable $projects): array
    {
        $projects = collect($projects);
        $fingerprints = $this->fingerprints($projects);
        $results = [];
        foreach ($projects as $project) {
            $results[$project->id] = $this->cached($project, $fingerprints[$project->id]);
        }

        return $results;
    }

    /** @return array{errors: int, warnings: int, infos: int, issues: int, items: list<array<string, mixed>>} */
    public function forProject(Project $project): array
    {
        return $this->forProjects([$project])[$project->id];
    }

    /** @return array{errors: int, warnings: int, infos: int, issues: int, items: list<array<string, mixed>>} */
    private function cached(Project $project, string $fingerprint): array
    {
        $compute = function () use ($project): array {
            // A fresh model: callers may pass projects with partially loaded relations.
            $items = collect($this->validation->validateProject(Project::query()->findOrFail($project->id)))
                ->reject(fn (array $item) => $item['level'] === 'ok')
                // Errors first, then warnings, then notes.
                ->sortBy(fn (array $item) => ['error' => 0, 'warning' => 1][$item['level']] ?? 2)
                ->values();
            $count = fn (string $level) => $items->where('level', $level)->count();

            return [
                'errors' => $count('error'),
                'warnings' => $count('warning'),
                'infos' => $count('info'),
                'issues' => $count('error') + $count('warning'),
                'items' => $items->all(),
            ];
        };

        return Cache::remember('project-health:'.self::CACHE_VERSION.':'.$project->id.':'.$fingerprint, now()->addDay(), $compute);
    }

    /**
     * Row count and newest change per project in every watched table, in one query per table.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<int, string>
     */
    private function fingerprints(Collection $projects): array
    {
        $ids = $projects->pluck('id')->all();
        $parts = collect($ids)->mapWithKeys(fn (int $id) => [$id => []])->all();
        if ($ids === []) {
            return [];
        }
        // One UNION query instead of one query per table.
        $query = DB::table('projects')->whereIn('id', $ids)
            ->selectRaw("'projects' AS source, id AS project_id, 1 AS total, MAX(updated_at) AS changed")->groupBy('id');
        foreach (self::WATCHED_TABLES as $table) {
            $query->unionAll(DB::table($table)->whereIn('project_id', $ids)->groupBy('project_id')
                ->selectRaw('? AS source, project_id, COUNT(*) AS total, MAX(updated_at) AS changed', [$table]));
        }
        foreach ($query->get() as $row) {
            $parts[$row->project_id][] = $row->source.$row->total.'@'.$row->changed;
        }

        // UNION rows come in no fixed order.
        return array_map(function (array $part): string {
            sort($part);

            return md5(implode('|', $part));
        }, $parts);
    }
}
