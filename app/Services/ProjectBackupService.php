<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProjectBackupService
{
    private const SIMPLE_TABLES = [
        'project_appendix_items',
        'gis_segments',
        'gis_restricted_areas',
        'survey_points',
        'map_drafts',
    ];

    /**
     * Create a backup of a project as JSON.
     * Includes all related data: ODF-s, cabinets, houses, routes, etc.
     */
    public function backup(Project $project): array
    {
        $backup = [
            'format' => 'ftth-manager-project-backup',
            'version' => 1,
            'created_at' => now()->toIso8601String(),
            'project' => [
                'name' => $project->name,
                'code' => $project->code,
                'location' => $project->location,
                'investor' => $project->investor,
                'status' => $project->status,
                'start_date' => $project->start_date,
                'deadline' => $project->deadline,
                'description' => $project->description,
                'fiber_layout' => $project->fiber_layout,
                'fiber_color_standard' => $project->fiber_color_standard,
                'fiber_reserve_per_tube' => $project->fiber_reserve_per_tube,
            ],
            'data' => [
                'odfs' => $project->odfs->map(fn ($odf) => [
                    'id' => $odf->id,
                    'name' => $odf->name,
                    'address' => $odf->address,
                    'latitude' => $odf->latitude,
                    'longitude' => $odf->longitude,
                    'fiber_capacity' => $odf->fiber_capacity,
                    'port_count' => $odf->port_count,
                    'notes' => $odf->notes,
                    'import_batch' => $odf->import_batch,
                ])->toArray(),
                'branches' => $project->branches->map(fn ($branch) => [
                    'id' => $branch->id,
                    'odf_id' => $branch->odf_id,
                    'parent_branch_id' => $branch->parent_branch_id,
                    'route_id' => $branch->route_id,
                    'name' => $branch->name,
                    'code' => $branch->code,
                    'type' => $branch->type,
                    'sort_order' => $branch->sort_order,
                ])->toArray(),
                'cabinets' => $project->cabinets->map(fn ($cabinet) => [
                    'id' => $cabinet->id,
                    'name' => $cabinet->name,
                    'address' => $cabinet->address,
                    'latitude' => $cabinet->latitude,
                    'longitude' => $cabinet->longitude,
                    'splitter_count' => $cabinet->splitter_count,
                    'ports_per_splitter' => $cabinet->ports_per_splitter,
                    'odf_id' => $cabinet->odf_id,
                    'parent_cabinet_id' => $cabinet->parent_cabinet_id,
                    'branch_id' => $cabinet->branch_id,
                    'branch_order' => $cabinet->branch_order,
                    'import_batch' => $cabinet->import_batch,
                ])->toArray(),
                'houses' => $project->houses->map(fn ($house) => [
                    'id' => $house->id,
                    'label' => $house->label,
                    'address' => $house->address,
                    'latitude' => $house->latitude,
                    'longitude' => $house->longitude,
                    'status' => $house->status,
                    'cabinet_id' => $house->cabinet_id,
                    'branch_id' => $house->branch_id,
                    'import_batch' => $house->import_batch,
                ])->toArray(),
                'routes' => $project->routes->map(fn ($route) => [
                    'id' => $route->id,
                    'name' => $route->name,
                    'route_type' => $route->route_type,
                    'installation_type' => $route->installation_type,
                    'trench_group' => $route->trench_group,
                    'counts_as_trench' => $route->counts_as_trench,
                    'trench_length_m' => $route->trench_length_m,
                    'duct_length_m' => $route->duct_length_m,
                    'fiber_length_m' => $route->fiber_length_m,
                    'fiber_count' => $route->fiber_count,
                    'microduct_count' => $route->microduct_count,
                    'microduct_type' => $route->microduct_type,
                    'cable_type' => $route->cable_type,
                    'path' => $route->path,
                    'coordinates_json' => $route->coordinates_json,
                    'odf_id' => $route->odf_id,
                    'cabinet_id' => $route->cabinet_id,
                    'from_type' => $route->from_type,
                    'from_id' => $route->from_id,
                    'to_type' => $route->to_type,
                    'to_id' => $route->to_id,
                    'status' => $route->status,
                    'note' => $route->note,
                    'import_batch' => $route->import_batch,
                ])->toArray(),
                'materials' => $project->materials->map(fn ($material) => [
                    'name' => $material->name,
                    'unit' => $material->unit,
                    'planned_quantity' => $material->planned_quantity,
                    'used_quantity' => $material->used_quantity,
                    'unit_price' => $material->unit_price,
                ])->toArray(),
            ] + collect(self::SIMPLE_TABLES)->mapWithKeys(fn (string $table) => [
                $table => $this->projectRows($table, $project->id),
            ])->all(),
        ];

        $backup['summary'] = collect($backup['data'])->map(fn (array $rows): int => count($rows))->all();
        $backup['excluded_files'] = ['survey_point_photos'];

        return $backup;
    }

    /**
     * Restore a project from backup JSON.
     * Creates new project with all related data.
     */
    public function restore(array $backup, ?string $newProjectName = null): Project
    {
        if (! isset($backup['format']) || $backup['format'] !== 'ftth-manager-project-backup') {
            throw new \InvalidArgumentException('Invalid backup format');
        }

        if (! isset($backup['version']) || $backup['version'] !== 1) {
            throw new \InvalidArgumentException('Unsupported backup version');
        }

        if (! isset($backup['project']['name'], $backup['project']['location']) || ! is_array($backup['data'] ?? null)) {
            throw new \InvalidArgumentException('Backup does not contain the required project data');
        }

        foreach ($backup['summary'] ?? [] as $table => $expectedCount) {
            if (! isset($backup['data'][$table]) || ! is_array($backup['data'][$table]) || count($backup['data'][$table]) !== $expectedCount) {
                throw new \InvalidArgumentException('Backup content does not match its summary');
            }
        }

        return DB::transaction(function () use ($backup, $newProjectName) {
            // Create project
            $projectData = $backup['project'];
            $projectData['name'] = $newProjectName ?? ($projectData['name'].' (Restored)');
            $projectData['code'] = $this->uniqueProjectCode((string) ($projectData['code'] ?? $projectData['name']));

            $project = Project::create($projectData);

            // Restore ODF-s
            $odfMap = [];
            foreach ($backup['data']['odfs'] ?? [] as $odfData) {
                $odfId = $odfData['id'];
                unset($odfData['id']);
                $odfData['project_id'] = $project->id;
                $newOdf = DB::table('odfs')->insertGetId($odfData);
                $odfMap[$odfId] = $newOdf;
            }

            // Routes are created before branches because a branch may reference its route.
            $routeMap = [];
            $routeLinks = [];
            foreach ($backup['data']['routes'] ?? [] as $routeData) {
                $routeId = $routeData['id'];
                unset($routeData['id']);
                $routeLinks[$routeId] = [
                    'cabinet_id' => $routeData['cabinet_id'] ?? null,
                    'from_type' => $routeData['from_type'] ?? null,
                    'from_id' => $routeData['from_id'] ?? null,
                    'to_type' => $routeData['to_type'] ?? null,
                    'to_id' => $routeData['to_id'] ?? null,
                ];
                $routeData['project_id'] = $project->id;
                $routeData['odf_id'] = $this->mappedId($routeData['odf_id'] ?? null, $odfMap);
                $routeData['cabinet_id'] = null;
                foreach (['path', 'coordinates_json'] as $jsonColumn) {
                    if (isset($routeData[$jsonColumn]) && is_array($routeData[$jsonColumn])) {
                        $routeData[$jsonColumn] = json_encode($routeData[$jsonColumn], JSON_THROW_ON_ERROR);
                    }
                }
                $routeMap[$routeId] = DB::table('routes')->insertGetId($routeData);
            }

            // Restore branches, then reconnect their self-referencing parents.
            $branchMap = [];
            $branchParents = [];
            foreach ($backup['data']['branches'] ?? [] as $branchData) {
                $branchId = $branchData['id'];
                $branchParents[$branchId] = $branchData['parent_branch_id'] ?? null;
                unset($branchData['id']);
                $branchData['project_id'] = $project->id;
                $branchData['odf_id'] = $this->mappedId($branchData['odf_id'] ?? null, $odfMap);
                $branchData['route_id'] = $this->mappedId($branchData['route_id'] ?? null, $routeMap);
                $branchData['parent_branch_id'] = null;
                $branchMap[$branchId] = DB::table('network_branches')->insertGetId($branchData);
            }
            foreach ($branchParents as $oldId => $oldParentId) {
                DB::table('network_branches')->where('id', $branchMap[$oldId])->update([
                    'parent_branch_id' => $this->mappedId($oldParentId, $branchMap),
                ]);
            }

            // Restore cabinets with updated ODF/branch links, then reconnect parent cabinets.
            $cabinetMap = [];
            $cabinetParents = [];
            foreach ($backup['data']['cabinets'] ?? [] as $cabinetData) {
                $cabinetId = $cabinetData['id'];
                $cabinetParents[$cabinetId] = $cabinetData['parent_cabinet_id'] ?? null;
                unset($cabinetData['id']);
                $cabinetData['project_id'] = $project->id;
                $cabinetData['odf_id'] = $this->mappedId($cabinetData['odf_id'] ?? null, $odfMap);
                $cabinetData['branch_id'] = $this->mappedId($cabinetData['branch_id'] ?? null, $branchMap);
                $cabinetData['parent_cabinet_id'] = null;
                $newCabinet = DB::table('cabinets')->insertGetId($cabinetData);
                $cabinetMap[$cabinetId] = $newCabinet;
            }
            foreach ($cabinetParents as $oldId => $oldParentId) {
                DB::table('cabinets')->where('id', $cabinetMap[$oldId])->update([
                    'parent_cabinet_id' => $this->mappedId($oldParentId, $cabinetMap),
                ]);
            }

            // Restore houses with updated cabinet_id
            $houseMap = [];
            foreach ($backup['data']['houses'] ?? [] as $houseData) {
                $houseId = $houseData['id'];
                unset($houseData['id']);
                $houseData['project_id'] = $project->id;
                $houseData['cabinet_id'] = $this->mappedId($houseData['cabinet_id'] ?? null, $cabinetMap);
                $houseData['branch_id'] = $this->mappedId($houseData['branch_id'] ?? null, $branchMap);
                $houseMap[$houseId] = DB::table('houses')->insertGetId($houseData);
            }

            // Reconnect route endpoints after cabinets have their new IDs.
            foreach ($routeLinks as $oldId => $links) {
                DB::table('routes')->where('id', $routeMap[$oldId])->update([
                    'cabinet_id' => $this->mappedId($links['cabinet_id'], $cabinetMap),
                    'from_id' => $this->mappedEndpoint($links['from_type'], $links['from_id'], $odfMap, $cabinetMap, $houseMap),
                    'to_id' => $this->mappedEndpoint($links['to_type'], $links['to_id'], $odfMap, $cabinetMap, $houseMap),
                ]);
            }

            foreach ($backup['data']['materials'] ?? [] as $materialData) {
                $materialData['project_id'] = $project->id;
                DB::table('materials')->insert($materialData);
            }

            foreach (self::SIMPLE_TABLES as $table) {
                foreach ($backup['data'][$table] ?? [] as $row) {
                    $row['project_id'] = $project->id;
                    if ($table === 'survey_points') {
                        // The JSON backup does not contain photo binaries.
                        $row['photo_path'] = null;
                    }
                    DB::table($table)->insert($row);
                }
            }

            return $project->fresh();
        });
    }

    private function uniqueProjectCode(string $source): string
    {
        $base = Str::limit(Str::upper(Str::slug($source, '-')) ?: 'RESTORED', 50, '');
        $candidate = $base;
        $suffix = 1;

        while (Project::where('code', $candidate)->exists()) {
            $ending = '-RESTORE-'.$suffix++;
            $candidate = Str::limit($base, 50 - strlen($ending), '').$ending;
        }

        return $candidate;
    }

    private function mappedId(mixed $oldId, array $map): ?int
    {
        return $oldId !== null && isset($map[$oldId]) ? $map[$oldId] : null;
    }

    private function projectRows(string $table, int $projectId): array
    {
        return DB::table($table)->where('project_id', $projectId)->get()->map(function ($row): array {
            $data = (array) $row;
            unset($data['id'], $data['project_id'], $data['created_at'], $data['updated_at']);

            return $data;
        })->all();
    }

    private function mappedEndpoint(?string $type, mixed $oldId, array $odfMap, array $cabinetMap, array $houseMap): ?int
    {
        return match ($type) {
            'odf' => $this->mappedId($oldId, $odfMap),
            'cabinet' => $this->mappedId($oldId, $cabinetMap),
            'house' => $this->mappedId($oldId, $houseMap),
            default => null,
        };
    }
}
