<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProjectSnapshotService
{
    private const TABLES = [
        'odfs', 'routes', 'cabinets', 'houses', 'network_branches', 'materials',
        'project_appendix_items', 'gis_segments', 'gis_restricted_areas',
        'survey_points', 'map_drafts', 'fiber_splices',
    ];

    public function create(Project $project, string $label): ProjectSnapshot
    {
        $payload = collect(self::TABLES)->mapWithKeys(fn (string $table) => [
            $table => DB::table($table)->where('project_id', $project->id)->get()->map(fn ($row) => (array) $row)->all(),
        ])->all();

        $snapshot = ProjectSnapshot::create([
            'project_id' => $project->id,
            'label' => $label,
            'payload' => $payload,
        ]);

        $expiredIds = ProjectSnapshot::query()->where('project_id', $project->id)
            ->latest()->pluck('id')->slice(10)->all();
        if ($expiredIds) {
            ProjectSnapshot::query()->whereKey($expiredIds)->delete();
        }

        return $snapshot;
    }

    public function restore(Project $project, ProjectSnapshot $snapshot): void
    {
        abort_unless($snapshot->project_id === $project->id, 404);
        $payload = $snapshot->payload;
        $this->create($project, 'Automatski: prije vraćanja verzije "'.$snapshot->label.'"');

        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function () use ($project, $payload): void {
                foreach (array_reverse(self::TABLES) as $table) {
                    DB::table($table)->where('project_id', $project->id)->delete();
                }

                $insert = function (string $table, array $rows): void {
                    foreach (array_chunk($rows, 250) as $chunk) {
                        if ($chunk !== []) {
                            DB::table($table)->insert($chunk);
                        }
                    }
                };

                $insert('odfs', $payload['odfs'] ?? []);

                $routeLinks = [];
                $routes = collect($payload['routes'] ?? [])->map(function (array $route) use (&$routeLinks): array {
                    $routeLinks[$route['id']] = collect($route)->only(['cabinet_id', 'from_id', 'to_id'])->all();
                    $route['cabinet_id'] = null;
                    $route['from_id'] = null;
                    $route['to_id'] = null;

                    return $route;
                })->all();
                $insert('routes', $routes);

                $branchParents = [];
                $branches = collect($payload['network_branches'] ?? [])->map(function (array $branch) use (&$branchParents): array {
                    $branchParents[$branch['id']] = $branch['parent_branch_id'] ?? null;
                    $branch['parent_branch_id'] = null;

                    return $branch;
                })->all();
                $insert('network_branches', $branches);

                $cabinetParents = [];
                $cabinets = collect($payload['cabinets'] ?? [])->map(function (array $cabinet) use (&$cabinetParents): array {
                    $cabinetParents[$cabinet['id']] = $cabinet['parent_cabinet_id'] ?? null;
                    $cabinet['parent_cabinet_id'] = null;

                    return $cabinet;
                })->all();
                $insert('cabinets', $cabinets);
                $insert('houses', $payload['houses'] ?? []);

                foreach ($routeLinks as $id => $links) {
                    DB::table('routes')->where('id', $id)->update($links);
                }
                foreach ($branchParents as $id => $parentId) {
                    DB::table('network_branches')->where('id', $id)->update(['parent_branch_id' => $parentId]);
                }
                foreach ($cabinetParents as $id => $parentId) {
                    DB::table('cabinets')->where('id', $id)->update(['parent_cabinet_id' => $parentId]);
                }

                foreach (array_diff(self::TABLES, ['odfs', 'routes', 'network_branches', 'cabinets', 'houses']) as $table) {
                    $insert($table, $payload[$table] ?? []);
                }
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
}
