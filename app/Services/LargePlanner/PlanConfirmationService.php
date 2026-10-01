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
use App\Services\FiberPlanService;
use App\Services\ProjectMaterialService;
use App\Services\ProjectSnapshotService;
use DomainException;
use Illuminate\Support\Facades\DB;

class PlanConfirmationService
{
    public function __construct(
        private readonly FinalValidationService $validator,
        private readonly ProjectSnapshotService $snapshots,
        private readonly FiberPlanService $fiberPlans,
        private readonly ProjectMaterialService $materials,
    ) {}

    public function confirm(Project $project, ProjectBackgroundTask $task, array $preview, User $user, ?string $ipAddress = null, bool $warningsAcknowledged = false): array
    {
        return DB::transaction(function () use ($project, $task, $preview, $user, $ipAddress, $warningsAcknowledged): array {
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
            $warnings = collect(data_get($preview, 'warnings.items', []))->reject(fn (array $warning) => ($warning['severity'] ?? 'warning') === 'error');
            if ($warnings->isNotEmpty() && ! $warningsAcknowledged) {
                throw new DomainException('Pregledaj upozorenja i potvrdi da ih prihvataš prije konačnog upisa.');
            }

            $snapshot = $this->snapshots->create($project, "Automatski: prije potvrde velikog plana #{$task->id}");
            $batch = 'large-plan:'.$task->id;
            $this->normalizeExistingOdfNames($project);
            $odfIds = $this->createOdfs($project, $preview, $batch);
            [$cabinetIds, $branchIds, $routeCount] = $this->createSecondaryNetwork($project, $preview, $odfIds, $batch);
            $routeCount += $this->createPrimaryRoutes($project, $preview, $odfIds, $batch);
            $routeCount += $this->createDrops($project, $preview, $cabinetIds, $branchIds, $batch);

            $fiberPlan = $this->fiberPlans->build($project->fresh());
            $fiberErrors = collect($fiberPlan['issues'] ?? [])->where('level', 'error')->values();
            if (($fiberPlan['assumptionsConfirmed'] ?? false) && $fiberErrors->isNotEmpty()) {
                throw new DomainException('Potvrđeni plan ne prolazi optičku/PON provjeru: '.data_get($fiberErrors->first(), 'message'));
            }

            $materialSummary = $this->materials->summary(
                $project->fresh()->load(['odfs', 'cabinets', 'houses', 'routes', 'materials']),
                (int) round((float) ($project->largePlannerSetting?->fiber_reserve_percent ?? 10)),
            );
            $summary = ['odfs' => count($odfIds), 'odos' => count($cabinetIds), 'branches' => count($branchIds), 'routes' => $routeCount, 'houses' => collect(data_get($preview, 'odo_placement.odos', []))->sum(fn (array $odo) => count($odo['house_ids'] ?? [])), 'snapshot_id' => $snapshot->id, 'warnings_acknowledged' => $warningsAcknowledged, 'fiber_plan_signature' => $fiberPlan['signature'] ?? null, 'fiber_health' => $fiberPlan['health'] ?? null, 'fiber_errors' => $fiberErrors->count(), 'materials' => $materialSummary];
            $lockedTask->update(['confirmed_at' => now(), 'confirmed_by' => $user->id, 'snapshot_id' => $snapshot->id, 'confirmation_summary' => $summary]);
            ActivityLog::create(['user_id' => $user->id, 'project_id' => $project->id, 'method' => 'CONFIRM', 'route_name' => 'projects.large-planner.preview.confirm', 'path' => "/projekti/{$project->id}/veliki-planer/preview/{$task->id}/potvrdi", 'subject_type' => ProjectBackgroundTask::class, 'subject_id' => $task->id, 'status_code' => 200, 'metadata' => $summary, 'ip_address' => $ipAddress]);

            return $summary;
        }, 3);
    }

    private function createOdfs(Project $project, array $preview, string $batch): array
    {
        $ids = [];
        foreach (data_get($preview, 'odf_placement.odfs', []) as $proposal) {
            $fiberCapacity = max(1, (int) ($proposal['fiber_capacity'] ?? 144));
            $odf = Odf::create(['project_id' => $project->id, 'name' => $proposal['provisional_name'], 'address' => 'Automatski prijedlog velikog planera', 'fiber_capacity' => $fiberCapacity, 'port_count' => $fiberCapacity, 'latitude' => $proposal['point'][0], 'longitude' => $proposal['point'][1], 'notes' => "Potvrđeno iz preview zadatka {$batch}.", 'import_batch' => $batch]);
            $ids[$proposal['key']] = $odf->id;
        }

        return $ids;
    }

    private function normalizeExistingOdfNames(Project $project): void
    {
        $project->odfs()->whereNull('import_batch')->orderBy('id')->get()
            ->each(fn (Odf $odf, int $index) => $odf->update(['name' => 'ODF '.($index + 1)]));
    }

    private function createSecondaryNetwork(Project $project, array $preview, array $odfIds, string $batch): array
    {
        $cabinetIds = [];
        $branchIds = [];
        $routes = collect(data_get($preview, 'routes.secondary_routes', []))
            ->sortBy(fn (array $route) => ($route['is_lateral'] ?? false) ? 1 : 0)
            ->values();
        $sized = collect(data_get($preview, 'cable_capacity.routes.secondary_routes', []))->keyBy('key');
        $odos = collect(data_get($preview, 'odo_placement.odos', []))->keyBy('key');
        $branchIdsByKey = [];
        foreach ($routes as $index => $routePreview) {
            $odfId = $routePreview['odf_id'] ?? ($odfIds[$routePreview['odf_key'] ?? ''] ?? null);
            if (! $odfId) {
                throw new DomainException('Za sekundarni krak #'.($index + 1).' nije određen ODF.');
            }
            $size = $sized->get($routePreview['key']);
            $lateral = (bool) ($routePreview['is_lateral'] ?? false);
            $sourceCabinetId = $lateral ? ($cabinetIds[$routePreview['from_odo_key'] ?? ''] ?? null) : null;
            if ($lateral && ! $sourceCabinetId) {
                throw new DomainException('Sporedni sekundarni krak nema poÄetni ZO.');
            }
            $branchName = $routePreview['name'] ?? 'Sekundarni krak '.($index + 1);
            $route = $this->route($project, $routePreview, [
                'odf_id' => $odfId,
                'from_type' => $lateral ? 'cabinet' : 'odf',
                'from_id' => $lateral ? $sourceCabinetId : $odfId,
                'name' => $branchName,
                'route_type' => 'distribution',
                'fiber_count' => $size['fiber_count'] ?? 4,
                'note' => $lateral ? 'Sporedni krak izveden mikrocijevi iz najbliÅ¾eg ZO-a.' : null,
                'import_batch' => $batch,
            ]);
            $branch = NetworkBranch::create([
                'project_id' => $project->id,
                'odf_id' => $odfId,
                'parent_branch_id' => $lateral ? ($branchIdsByKey[$routePreview['parent_branch_key'] ?? ''] ?? null) : null,
                'route_id' => $route->id,
                'name' => $branchName,
                'code' => $routePreview['key'],
                'type' => 'secondary',
                'sort_order' => $index + 1,
            ]);
            $branchIdsByKey[$routePreview['key']] = $branch->id;
            $routeOdoKeys = $routePreview['odo_keys'] ?? [$routePreview['odo_key']];
            foreach ($routeOdoKeys as $order => $odoKey) {
                $odo = $odos->get($odoKey);
                [$splitters, $ports] = $this->splitterShape((int) $odo['capacity']);
                $cabinet = Cabinet::create(['project_id' => $project->id, 'odf_id' => $odfId, 'parent_cabinet_id' => $lateral ? $sourceCabinetId : null, 'branch_id' => $branch->id, 'name' => $odo['provisional_name'], 'address' => 'Automatski prijedlog velikog planera', 'splitter_count' => $splitters, 'ports_per_splitter' => $ports, 'latitude' => $odo['point'][0], 'longitude' => $odo['point'][1], 'branch_order' => $order + 1, 'import_batch' => $batch]);
                $cabinetIds[$odoKey] = $cabinet->id;
                $branchIds[$odoKey] = $branch->id;
                if ($odoKey === ($routePreview['terminal_odo_key'] ?? $routePreview['odo_key'])) {
                    $route->update(['cabinet_id' => $cabinet->id, 'to_type' => 'cabinet', 'to_id' => $cabinet->id]);
                }
            }
        }

        return [$cabinetIds, $branchIds, $routes->count()];
    }

    private function createPrimaryRoutes(Project $project, array $preview, array $odfIds, string $batch): int
    {
        $sized = collect(data_get($preview, 'cable_capacity.routes.primary_routes', []))->keyBy('key');
        foreach (data_get($preview, 'odf_placement.primary_routes', []) as $index => $routePreview) {
            $sourceId = $routePreview['from_odf_id'] ?? ($odfIds[$routePreview['from_odf_key'] ?? ''] ?? null);
            $targetId = $routePreview['to_odf_id'] ?? ($odfIds[$routePreview['to_odf_key'] ?? ''] ?? null);
            if (! $sourceId || ! $targetId) {
                throw new DomainException('Primarni krak nema oba pripadajuća ODF-a.');
            }
            $size = $sized->get($routePreview['key']);
            $this->route($project, $routePreview, ['odf_id' => $sourceId, 'from_type' => 'odf', 'from_id' => $sourceId, 'to_type' => 'odf', 'to_id' => $targetId, 'name' => 'Primarni krak '.($index + 1), 'route_type' => 'backbone', 'fiber_count' => $size['fiber_count'] ?? 4, 'note' => "Veza ODF #{$sourceId} – ODF #{$targetId}", 'import_batch' => $batch]);
        }

        return count(data_get($preview, 'odf_placement.primary_routes', []));
    }

    private function createDrops(Project $project, array $preview, array $cabinetIds, array $branchIds, string $batch): int
    {
        $count = 0;
        foreach (data_get($preview, 'routes.drop_routes', []) as $routePreview) {
            $cabinetId = $cabinetIds[$routePreview['odo_key']] ?? null;
            $house = $project->houses()->findOrFail($routePreview['house_id']);
            $house->update(['cabinet_id' => $cabinetId, 'branch_id' => $branchIds[$routePreview['odo_key']] ?? null]);
            $this->route($project, $routePreview, ['cabinet_id' => $cabinetId, 'from_type' => 'cabinet', 'from_id' => $cabinetId, 'to_type' => 'house', 'to_id' => $house->id, 'name' => 'Drop '.$house->label, 'route_type' => 'drop', 'fiber_count' => 4, 'microduct_type' => '10/8', 'import_batch' => $batch]);
            $count++;
        }

        return $count;
    }

    private function route(Project $project, array $preview, array $attributes): NetworkRoute
    {
        // I kod poklopljenih koordinata postoji završni servisni spoj unutar objekta/ormara.
        // Minimalni obračunski metar sprečava nevažeću trasu bez kabla ili mikrocijevi.
        $length = max(1, (int) ceil($preview['length_m'] ?? 0));

        return NetworkRoute::create($attributes + ['project_id' => $project->id, 'installation_type' => 'underground', 'duct_length_m' => $length, 'fiber_length_m' => $length, 'microduct_count' => 1, 'microduct_type' => '14/10', 'status' => 'planned', 'path' => $preview['path']]);
    }

    private function splitterShape(int $capacity): array
    {
        $ports = 4;

        return [(int) ceil($capacity / $ports), $ports];
    }
}
