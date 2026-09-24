<?php

namespace App\Services\LargePlanner;

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Models\ProjectBackgroundTask;
use App\Models\User;
use App\Services\ProjectSnapshotService;
use DomainException;
use Illuminate\Support\Facades\DB;

class PlanConfirmationService
{
    public function __construct(
        private readonly FinalValidationService $validator,
        private readonly ProjectSnapshotService $snapshots,
    ) {}

    public function confirm(Project $project, ProjectBackgroundTask $task, array $preview, User $user, ?string $ipAddress = null): array
    {
        return DB::transaction(function () use ($project, $task, $preview, $user, $ipAddress): array {
            $lockedTask = ProjectBackgroundTask::query()->lockForUpdate()->findOrFail($task->id);
            if ($lockedTask->project_id !== $project->id || $lockedTask->type !== 'large_plan') {
                throw new DomainException('Preview ne pripada odabranom projektu.');
            }
            if ($lockedTask->confirmed_at !== null) {
                return $lockedTask->confirmation_summary ?? [];
            }
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            if (ProjectBackgroundTask::query()->where('project_id', $project->id)->where('type', 'large_plan')->whereNotNull('confirmed_at')->whereKeyNot($lockedTask->id)->exists()) {
                throw new DomainException('Druga varijanta ovog projekta je već potvrđena. Vrati prethodni snapshot prije nove potvrde.');
            }
            $validation = $this->validator->validate($project, $preview);
            if (! $validation['valid']) {
                throw new DomainException('Plan nije moguće potvrditi dok postoje kritične greške.');
            }

            $snapshot = $this->snapshots->create($project, "Automatski: prije potvrde velikog plana #{$task->id}");
            $batch = 'large-plan:'.$task->id;
            $odfIds = $this->createOdfs($project, $preview, $batch);
            [$cabinetIds, $branchIds, $routeCount] = $this->createSecondaryNetwork($project, $preview, $odfIds, $batch);
            $routeCount += $this->createPrimaryRoutes($project, $preview, $odfIds, $batch);
            $routeCount += $this->createDrops($project, $preview, $cabinetIds, $batch);

            $summary = ['odfs' => count($odfIds), 'odos' => count($cabinetIds), 'branches' => count($branchIds), 'routes' => $routeCount, 'houses' => collect(data_get($preview, 'odo_placement.odos', []))->sum(fn (array $odo) => count($odo['house_ids'] ?? [])), 'snapshot_id' => $snapshot->id];
            $lockedTask->update(['confirmed_at' => now(), 'confirmed_by' => $user->id, 'snapshot_id' => $snapshot->id, 'confirmation_summary' => $summary]);
            ActivityLog::create(['user_id' => $user->id, 'project_id' => $project->id, 'method' => 'CONFIRM', 'route_name' => 'projects.large-planner.preview.confirm', 'path' => "/projekti/{$project->id}/veliki-planer/preview/{$task->id}/potvrdi", 'subject_type' => ProjectBackgroundTask::class, 'subject_id' => $task->id, 'status_code' => 200, 'metadata' => $summary, 'ip_address' => $ipAddress]);

            return $summary;
        }, 3);
    }

    private function createOdfs(Project $project, array $preview, string $batch): array
    {
        $ids = [];
        foreach (data_get($preview, 'odf_placement.odfs', []) as $proposal) {
            $odf = Odf::create(['project_id' => $project->id, 'name' => $proposal['provisional_name'], 'address' => 'Automatski prijedlog velikog planera', 'fiber_capacity' => max(1, (int) $proposal['capacity']), 'port_count' => max(1, (int) $proposal['capacity']), 'latitude' => $proposal['point'][0], 'longitude' => $proposal['point'][1], 'notes' => "Potvrđeno iz preview zadatka {$batch}.", 'import_batch' => $batch]);
            $ids[$proposal['key']] = $odf->id;
        }

        return $ids;
    }

    private function createSecondaryNetwork(Project $project, array $preview, array $odfIds, string $batch): array
    {
        $cabinetIds = [];
        $branchIds = [];
        $routes = collect(data_get($preview, 'routes.secondary_routes', []))->keyBy('odo_key');
        $sized = collect(data_get($preview, 'cable_capacity.routes.secondary_routes', []))->keyBy('key');
        foreach (data_get($preview, 'odo_placement.odos', []) as $index => $odo) {
            $routePreview = $routes->get($odo['key']);
            $odfId = $routePreview['odf_id'] ?? ($odfIds[$routePreview['odf_key'] ?? ''] ?? null);
            if (! $odfId) {
                throw new DomainException("Za {$odo['provisional_name']} nije određen ODF.");
            }
            [$splitters, $ports] = $this->splitterShape((int) $odo['capacity']);
            $cabinet = Cabinet::create(['project_id' => $project->id, 'odf_id' => $odfId, 'name' => $odo['provisional_name'], 'address' => 'Automatski prijedlog velikog planera', 'splitter_count' => $splitters, 'ports_per_splitter' => $ports, 'latitude' => $odo['point'][0], 'longitude' => $odo['point'][1], 'branch_order' => $index + 1, 'import_batch' => $batch]);
            $size = $sized->get($routePreview['key']);
            $route = $this->route($project, $routePreview, ['odf_id' => $odfId, 'cabinet_id' => $cabinet->id, 'from_type' => 'odf', 'from_id' => $odfId, 'to_type' => 'cabinet', 'to_id' => $cabinet->id, 'name' => 'Sekundarni '.$odo['provisional_name'], 'route_type' => 'distribution', 'fiber_count' => $size['fiber_count'] ?? 4, 'import_batch' => $batch]);
            $branch = NetworkBranch::create(['project_id' => $project->id, 'odf_id' => $odfId, 'route_id' => $route->id, 'name' => 'Krak '.$odo['provisional_name'], 'code' => $odo['key'], 'type' => 'secondary', 'sort_order' => $index + 1]);
            $cabinet->update(['branch_id' => $branch->id]);
            $cabinetIds[$odo['key']] = $cabinet->id;
            $branchIds[$odo['key']] = $branch->id;
        }

        return [$cabinetIds, $branchIds, count($cabinetIds)];
    }

    private function createPrimaryRoutes(Project $project, array $preview, array $odfIds, string $batch): int
    {
        $sized = collect(data_get($preview, 'cable_capacity.routes.primary_routes', []))->keyBy('key');
        foreach (data_get($preview, 'odf_placement.primary_routes', []) as $routePreview) {
            $sourceId = $routePreview['from_odf_id'] ?? ($odfIds[$routePreview['from_odf_key'] ?? ''] ?? null);
            $targetId = $odfIds[$routePreview['to_odf_key']] ?? null;
            $size = $sized->get($routePreview['key']);
            $this->route($project, $routePreview, ['odf_id' => $sourceId, 'from_type' => 'odf', 'from_id' => $sourceId, 'name' => 'Primarni '.$routePreview['to_odf_key'], 'route_type' => 'backbone', 'fiber_count' => $size['fiber_count'] ?? 4, 'note' => $targetId ? "Ciljni ODF #{$targetId}" : null, 'import_batch' => $batch]);
        }

        return count(data_get($preview, 'odf_placement.primary_routes', []));
    }

    private function createDrops(Project $project, array $preview, array $cabinetIds, string $batch): int
    {
        $count = 0;
        foreach (data_get($preview, 'routes.drop_routes', []) as $routePreview) {
            $cabinetId = $cabinetIds[$routePreview['odo_key']] ?? null;
            $house = $project->houses()->findOrFail($routePreview['house_id']);
            $house->update(['cabinet_id' => $cabinetId]);
            $this->route($project, $routePreview, ['cabinet_id' => $cabinetId, 'from_type' => 'cabinet', 'from_id' => $cabinetId, 'to_type' => 'house', 'to_id' => $house->id, 'name' => 'Drop '.$house->label, 'route_type' => 'drop', 'fiber_count' => 4, 'microduct_type' => '10/8', 'import_batch' => $batch]);
            $count++;
        }

        return $count;
    }

    private function route(Project $project, array $preview, array $attributes): NetworkRoute
    {
        $length = (int) ceil($preview['length_m'] ?? 0);

        return NetworkRoute::create($attributes + ['project_id' => $project->id, 'installation_type' => 'underground', 'duct_length_m' => $length, 'fiber_length_m' => $length, 'microduct_count' => 1, 'microduct_type' => '14/10', 'status' => 'planned', 'path' => $preview['path']]);
    }

    private function splitterShape(int $capacity): array
    {
        $ports = min(max($capacity, 1), 255);

        return [(int) ceil($capacity / $ports), $ports];
    }
}
