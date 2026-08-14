<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Services\BranchSyncService;
use App\Services\DXFParserService;
use App\Services\FtthIntelligenceService;
use App\Services\GeometryService;
use App\Services\ProjectMaterialService;
use App\Services\ProjectValidationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

trait ManagesFtthData
{
    public function __construct(
        protected readonly FtthIntelligenceService $ftthIntelligence,
        protected readonly ProjectValidationService $projectValidation,
        protected readonly ProjectMaterialService $projectMaterials,
        protected readonly GeometryService $geometry,
        protected readonly BranchSyncService $branchSync,
        protected readonly DXFParserService $dxfParser,
    ) {}

    // -------------------------------------------------------------------------
    // Position
    // -------------------------------------------------------------------------

    protected function updatePosition(Request $request, Model $element)
    {
        $position = $request->validate([
            'latitude' => $this->latitudeRules(true),
            'longitude' => $this->longitudeRules(true),
        ]);

        $element->update($position);

        return response()->json([
            'message' => 'Nova pozicija je sacuvana.',
            'latitude' => (float) $element->latitude,
            'longitude' => (float) $element->longitude,
        ]);
    }

    // -------------------------------------------------------------------------
    // Branch sync — delegates to BranchSyncService
    // -------------------------------------------------------------------------

    protected function createBranchForRoute(NetworkRoute $route): ?NetworkBranch
    {
        return $this->branchSync->createBranchForRoute($route);
    }

    protected function syncBranchForRoute(NetworkRoute $route): void
    {
        $this->branchSync->syncBranchForRoute($route);
    }

    protected function deleteRouteWithBranch(NetworkRoute $route): void
    {
        $this->branchSync->deleteRouteWithBranch($route);
    }

    protected function assignCreatedHousesToDraftBranches(Collection $houses, Collection $branches): void
    {
        $this->branchSync->assignCreatedHousesToDraftBranches($houses, $branches);
    }

    // -------------------------------------------------------------------------
    // DXF parsing — delegates to DXFParserService
    // -------------------------------------------------------------------------

    protected function parseDxfPolylines(string $contents): array
    {
        return $this->dxfParser->parsePolylines($contents);
    }

    // -------------------------------------------------------------------------
    // Geometry — delegates to GeometryService
    // -------------------------------------------------------------------------

    protected function distanceToRoute(float $lat, float $lng, array $points): float
    {
        return $this->geometry->distanceToRoute($lat, $lng, $points);
    }

    protected function distanceToSegment(float $lat, float $lng, array $start, array $end): float
    {
        return $this->geometry->distanceToSegment($lat, $lng, $start, $end);
    }

    protected function polylineLength(array $points): int
    {
        return $this->geometry->polylineLength($points);
    }

    protected function pointDistance(array $a, array $b): float
    {
        return $this->geometry->distanceBetweenPoints($a, $b);
    }

    protected function projectPointToRoute(float $lat, float $lng, NetworkRoute $route): array
    {
        return $this->geometry->projectPointToRoute($lat, $lng, $route);
    }

    protected function routePathBetween(NetworkRoute $route, array $start, array $end): array
    {
        return $this->geometry->routePathBetween($route, $start, $end);
    }

    protected function compactPath(array $path): array
    {
        return $this->geometry->compactPath($path);
    }

    // -------------------------------------------------------------------------
    // Drop path routing
    // -------------------------------------------------------------------------

    protected function dropPathForHouse(Cabinet $cabinet, House $house): array
    {
        $directPath = [
            [(float) $cabinet->latitude, (float) $cabinet->longitude],
            [(float) $house->latitude, (float) $house->longitude],
        ];

        $route = NetworkRoute::query()
            ->where('project_id', $cabinet->project_id)
            ->whereNotIn('route_type', ['trench', 'drop'])
            ->whereNotNull('path')
            ->get()
            ->filter(fn (NetworkRoute $route) => count($route->path ?? []) >= 2)
            ->sortBy(fn (NetworkRoute $route) => $this->geometry->projectPointToRoute(
                (float) $cabinet->latitude,
                (float) $cabinet->longitude,
                $route
            )['distance_m'])
            ->first();

        if (! $route) {
            return $directPath;
        }

        $cabinetProjection = $this->geometry->projectPointToRoute((float) $cabinet->latitude, (float) $cabinet->longitude, $route);
        $houseProjection = $this->geometry->projectPointToRoute((float) $house->latitude, (float) $house->longitude, $route);

        if ($cabinetProjection['distance_m'] > 35 || $houseProjection['distance_m'] > 90) {
            return $directPath;
        }

        return $this->geometry->compactPath(array_merge(
            [[(float) $cabinet->latitude, (float) $cabinet->longitude], [$cabinetProjection['lat'], $cabinetProjection['lng']]],
            array_slice($this->geometry->routePathBetween($route, $cabinetProjection, $houseProjection), 1),
            [[(float) $house->latitude, (float) $house->longitude]]
        ));
    }

    // -------------------------------------------------------------------------
    // Cycle detection
    // -------------------------------------------------------------------------

    protected function cabinetWouldCreateCycle(int $cabinetId, ?int $parentCabinetId): bool
    {
        $visited = [];
        while ($parentCabinetId) {
            if ((int) $parentCabinetId === $cabinetId || isset($visited[$parentCabinetId])) {
                return true;
            }
            $visited[$parentCabinetId] = true;
            $parentCabinetId = Cabinet::query()->whereKey($parentCabinetId)->value('parent_cabinet_id');
        }

        return false;
    }

    protected function branchWouldCreateCycle(int $branchId, ?int $parentBranchId): bool
    {
        $visited = [];
        while ($parentBranchId) {
            if ((int) $parentBranchId === $branchId || isset($visited[$parentBranchId])) {
                return true;
            }
            $visited[$parentBranchId] = true;
            $parentBranchId = NetworkBranch::query()->whereKey($parentBranchId)->value('parent_branch_id');
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Validation helpers
    // -------------------------------------------------------------------------

    protected function branchData(Request $request, ?int $branchId = null, ?int $currentProjectId = null): array
    {
        $data = $request->validate([
            'project_id' => ['required', $currentProjectId ? Rule::in([$currentProjectId]) : 'exists:projects,id'], 'odf_id' => ['nullable', 'exists:odfs,id'],
            'parent_branch_id' => ['nullable', 'exists:network_branches,id'],
            'route_id' => ['nullable', 'exists:routes,id', Rule::unique('network_branches', 'route_id')->ignore($branchId)],
            'name' => ['required', 'max:255'], 'code' => ['nullable', 'max:100'], 'type' => ['required', 'in:primary,secondary,rov'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);
        if ($branchId && (int) ($data['parent_branch_id'] ?? 0) === $branchId) {
            abort(422, 'Krak ne moze biti sam sebi roditelj.');
        }
        $this->ensureBelongsToProject(Odf::class, $data['odf_id'] ?? null, $data['project_id'], 'odf_id');
        $this->ensureBelongsToProject(NetworkRoute::class, $data['route_id'] ?? null, $data['project_id'], 'route_id');
        $this->ensureBelongsToProject(NetworkBranch::class, $data['parent_branch_id'] ?? null, $data['project_id'], 'parent_branch_id');
        if ($branchId && $this->branchWouldCreateCycle($branchId, $data['parent_branch_id'] ?? null)) {
            abort(422, 'Odabrani roditeljski krak bi napravio kruznu hijerarhiju.');
        }

        return $data;
    }

    protected function ensureBelongsToProject(string $model, mixed $id, int|string $projectId, string $field): void
    {
        validator([$field => $id], [
            $field => [function (string $_attribute, mixed $value, $fail) use ($model, $projectId): void {
                if ($value && ! $model::query()->whereKey($value)->where('project_id', $projectId)->exists()) {
                    $fail('Odabrani zapis ne pripada projektu.');
                }
            }],
        ])->validate();
    }

    protected function ensureSecondaryBranch(?int $branchId): void
    {
        validator(['branch_id' => $branchId], [
            'branch_id' => [function (string $_attribute, mixed $value, $fail): void {
                if ($value && ! NetworkBranch::query()->whereKey($value)->where('type', 'secondary')->exists()) {
                    $fail('ODO ormaric se moze planirati samo na sekundarnom kraku.');
                }
            }],
        ])->validate();
    }

    protected function ensureCabinetHouseCapacity(?int $cabinetId, ?int $exceptHouseId = null): void
    {
        if (! $cabinetId) {
            return;
        }

        $cabinet = Cabinet::findOrFail($cabinetId);
        $query = $cabinet->houses();
        if ($exceptHouseId) {
            $query->whereKeyNot($exceptHouseId);
        }

        if ($query->count() >= $cabinet->capacity) {
            throw ValidationException::withMessages([
                'cabinet_id' => ["ODO ormaric ne moze imati vise od {$cabinet->capacity} kuca."],
            ]);
        }
    }

    protected function latitudeRules(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'numeric', 'between:-90,90'];
    }

    protected function longitudeRules(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'numeric', 'between:-180,180'];
    }

    // -------------------------------------------------------------------------
    // Naming helpers
    // -------------------------------------------------------------------------

    protected function nextProjectCode(?string $name): string
    {
        $base = Str::upper(Str::slug($name ?: 'projekat'));
        $base = Str::limit($base ?: 'PROJEKAT', 42, '');
        $code = $base;
        $suffix = 2;

        while (Project::where('code', $code)->exists()) {
            $code = $base.'-'.$suffix++;
        }

        return $code;
    }

    protected function nextSequentialProjectName(string $model, int $projectId, string $prefix, int $pad = 0): string
    {
        $escaped = preg_quote($prefix, '/');
        $max = $model::query()
            ->where('project_id', $projectId)
            ->pluck('name')
            ->map(fn ($name) => preg_match("/^{$escaped}(\\d+)$/i", (string) $name, $match) ? (int) $match[1] : 0)
            ->max();

        $next = (int) $max + 1;

        return $prefix.($pad > 0 ? str_pad((string) $next, $pad, '0', STR_PAD_LEFT) : $next);
    }

    protected function uniqueProjectName(string $model, int $projectId, string $base): string
    {
        $base = trim($base);
        if ($base === '') {
            $base = 'Naziv';
        }

        if (! $model::query()->where('project_id', $projectId)->where('name', $base)->exists()) {
            return $base;
        }

        for ($suffix = 2; $suffix < 1000; $suffix++) {
            $candidate = "{$base}-{$suffix}";
            if (! $model::query()->where('project_id', $projectId)->where('name', $candidate)->exists()) {
                return $candidate;
            }
        }

        abort(422, 'Nije moguce generisati jedinstven naziv.');
    }

    protected function routeNamePrefix(string $routeType): string
    {
        return match ($routeType) {
            'trench' => 'Glavni rov',
            'backbone' => 'Backbone',
            'feeder' => 'Primarni krak',
            'drop' => 'Drop trasa',
            default => 'Sekundarni krak',
        };
    }

    protected function nextRouteNameForProject(int $projectId, string $routeType): string
    {
        $prefix = $this->routeNamePrefix($routeType).' ';

        return $this->nextSequentialProjectName(NetworkRoute::class, $projectId, $prefix);
    }

    protected function nextFtthCabinetNameForProject(int $projectId, string $branchCode = '1-1'): string
    {
        $branchCode = $this->ftthCabinetCodeFromBranchCode($branchCode);
        $prefix = "FTTH {$branchCode}-";

        return $this->nextSequentialProjectName(Cabinet::class, $projectId, $prefix);
    }

    protected function ftthCabinetCodeFromBranchCode(string $branchCode): string
    {
        $parts = preg_split('/[.-]+/', trim($branchCode), -1, PREG_SPLIT_NO_EMPTY) ?: ['1', '1'];
        if (count($parts) === 1) {
            $parts[] = '1';
        }

        $root = $parts[0].'-'.$parts[1];
        $children = array_slice($parts, 2);

        return $children ? $root.'.'.implode('.', $children) : $root;
    }

    protected function normalizeRouteStart(array &$data, int $projectId): void
    {
        if (empty($data['from_type']) && ! empty($data['odf_id'])) {
            $data['from_type'] = 'odf';
            $data['from_id'] = $data['odf_id'];
        }

        if (($data['from_type'] ?? null) === 'odf') {
            $data['from_id'] = Odf::query()->where('project_id', $projectId)->findOrFail(! empty($data['from_id']) ? $data['from_id'] : ($data['odf_id'] ?? null))->id;
            $data['odf_id'] = $data['from_id'];

            return;
        }

        if (($data['from_type'] ?? null) === 'cabinet') {
            $data['from_id'] = Cabinet::query()->where('project_id', $projectId)->findOrFail(! empty($data['from_id']) ? $data['from_id'] : null)->id;
            $data['odf_id'] = $data['odf_id'] ?? null;

            return;
        }

        $data['from_type'] = null;
        $data['from_id'] = null;
    }

    protected function routeStartLabel(NetworkRoute $route): string
    {
        if ($route->from_type === 'cabinet') {
            return $route->fromCabinet?->name ?? '-';
        }

        return $route->odf?->name ?? '-';
    }
}
