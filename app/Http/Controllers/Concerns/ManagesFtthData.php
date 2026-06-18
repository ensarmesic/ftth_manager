<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Services\FtthIntelligenceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

trait ManagesFtthData
{
    public function __construct(protected readonly FtthIntelligenceService $ftthIntelligence)
    {
    }
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

    protected function branchData(Request $request, ?int $branchId = null): array
    {
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'], 'odf_id' => ['nullable', 'exists:odfs,id'],
            'parent_branch_id' => ['nullable', 'exists:network_branches,id'],
            'route_id' => ['nullable', 'exists:routes,id', Rule::unique('network_branches', 'route_id')->ignore($branchId)],
            'name' => ['required', 'max:255'], 'code' => ['nullable', 'max:100'], 'type' => ['required', 'in:primary,secondary'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);
        if ($branchId && (int) ($data['parent_branch_id'] ?? 0) === $branchId) abort(422, 'Krak ne moze biti sam sebi roditelj.');
        $this->ensureBelongsToProject(Odf::class, $data['odf_id'] ?? null, $data['project_id'], 'odf_id');
        $this->ensureBelongsToProject(NetworkRoute::class, $data['route_id'] ?? null, $data['project_id'], 'route_id');
        $this->ensureBelongsToProject(NetworkBranch::class, $data['parent_branch_id'] ?? null, $data['project_id'], 'parent_branch_id');
        if ($branchId && $this->branchWouldCreateCycle($branchId, $data['parent_branch_id'] ?? null)) {
            abort(422, 'Odabrani roditeljski krak bi napravio kruznu hijerarhiju.');
        }
        return $data;
    }

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
            ->sortBy(fn (NetworkRoute $route) => $this->projectPointToRoute((float) $cabinet->latitude, (float) $cabinet->longitude, $route)['distance_m'])
            ->first();

        if (! $route) {
            return $directPath;
        }

        $cabinetProjection = $this->projectPointToRoute((float) $cabinet->latitude, (float) $cabinet->longitude, $route);
        $houseProjection = $this->projectPointToRoute((float) $house->latitude, (float) $house->longitude, $route);
        if ($cabinetProjection['distance_m'] > 35 || $houseProjection['distance_m'] > 90) {
            return $directPath;
        }

        return $this->compactPath(array_merge(
            [[(float) $cabinet->latitude, (float) $cabinet->longitude], [$cabinetProjection['lat'], $cabinetProjection['lng']]],
            array_slice($this->routePathBetween($route, $cabinetProjection, $houseProjection), 1),
            [[(float) $house->latitude, (float) $house->longitude]]
        ));
    }

    protected function deleteRouteWithBranch(NetworkRoute $route): void
    {
        DB::transaction(function () use ($route): void {
            $branch = NetworkBranch::query()->where('route_id', $route->id)->first();
            if ($branch) {
                $branch->cabinets()->update(['branch_id' => null, 'branch_order' => 0]);
                $branch->childBranches()->update(['parent_branch_id' => null]);
                $branch->delete();
            }
            $route->delete();
        });
    }

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

    protected function createBranchForRoute(NetworkRoute $route): ?NetworkBranch
    {
        if (in_array($route->route_type, ['drop', 'trench'], true) || NetworkBranch::query()->where('route_id', $route->id)->exists()) {
            return null;
        }

        $sourceCabinet = $route->from_type === 'cabinet' && $route->from_id
            ? Cabinet::query()->where('project_id', $route->project_id)->find($route->from_id)
            : null;

        $odfId = $route->odf_id ?? $sourceCabinet?->odf_id;
        if (! $odfId) {
            $projectOdfs = \App\Models\Odf::query()->where('project_id', $route->project_id)->pluck('id');
            if ($projectOdfs->count() === 1) {
                $odfId = $projectOdfs->first();
            }
        }

        $branch = NetworkBranch::create([
            'project_id' => $route->project_id,
            'odf_id' => $odfId,
            'parent_branch_id' => $sourceCabinet?->branch_id,
            'route_id' => $route->id,
            'name' => $route->name,
            'code' => preg_match('/(\d+(?:[.-]\d+)*)/', $route->name, $match) ? str_replace('-', '.', $match[1]) : null,
            'type' => in_array($route->route_type, ['backbone', 'feeder'], true) ? 'primary' : 'secondary',
            'sort_order' => (int) NetworkBranch::query()->where('project_id', $route->project_id)->max('sort_order') + 1,
        ]);

        if ($sourceCabinet && $route->cabinet_id) {
            Cabinet::query()
                ->where('project_id', $route->project_id)
                ->whereKey($route->cabinet_id)
                ->update([
                    'branch_id' => $branch->id,
                    'parent_cabinet_id' => $sourceCabinet->id,
                    'odf_id' => $route->odf_id ?? $sourceCabinet->odf_id,
                ]);
        }

        return $branch;
    }

    protected function assignCreatedHousesToDraftBranches(Collection $houses, Collection $branches): void
    {
        if ($houses->isEmpty() || $branches->isEmpty()) {
            return;
        }

        foreach ($houses as $house) {
            $nearest = $branches
                ->map(fn (array $item) => [
                    'branch' => $item['branch'],
                    'distance_m' => $this->distanceToRoute((float) $house->latitude, (float) $house->longitude, $item['route']->path ?? []),
                ])
                ->sortBy('distance_m')
                ->first();

            if ($nearest && is_finite($nearest['distance_m'])) {
                $house->update(['branch_id' => $nearest['branch']->id]);
            }
        }
    }

    protected function distanceToRoute(float $lat, float $lng, array $points): float
    {
        $best = INF;
        for ($i = 1; $i < count($points); $i++) {
            $best = min($best, $this->distanceToSegment($lat, $lng, $points[$i - 1], $points[$i]));
        }

        return $best;
    }

    protected function distanceToSegment(float $lat, float $lng, array $start, array $end): float
    {
        [$aLat, $aLng] = $start;
        [$bLat, $bLng] = $end;
        $originLat = $lat;
        $originLng = $lng;
        $a = $this->toLocalMeters((float) $aLat, (float) $aLng, $originLat, $originLng);
        $b = $this->toLocalMeters((float) $bLat, (float) $bLng, $originLat, $originLng);
        $p = $this->toLocalMeters($lat, $lng, $originLat, $originLng);
        $abx = $b['x'] - $a['x'];
        $aby = $b['y'] - $a['y'];
        $ab2 = max(0.000001, ($abx ** 2) + ($aby ** 2));
        $t = max(0, min(1, ((($p['x'] - $a['x']) * $abx) + (($p['y'] - $a['y']) * $aby)) / $ab2));
        $x = $a['x'] + ($abx * $t);
        $y = $a['y'] + ($aby * $t);

        return sqrt((($p['x'] - $x) ** 2) + (($p['y'] - $y) ** 2));
    }

    protected function toLocalMeters(float $lat, float $lng, float $originLat, float $originLng): array
    {
        return [
            'x' => ($lng - $originLng) * 111320 * cos(deg2rad($originLat)),
            'y' => ($lat - $originLat) * 111320,
        ];
    }

    protected function syncBranchForRoute(NetworkRoute $route): void
    {
        $branch = NetworkBranch::query()->where('route_id', $route->id)->first();
        if (in_array($route->route_type, ['drop', 'trench'], true)) {
            if ($branch) {
                $branch->cabinets()->update(['branch_id' => null, 'branch_order' => 0]);
                $branch->childBranches()->update(['parent_branch_id' => null]);
                $branch->delete();
            }
            return;
        }

        if (! $branch) {
            $this->createBranchForRoute($route);
            return;
        }

        $syncOdfId = $route->odf_id;
        if (! $syncOdfId && ! $branch->odf_id) {
            $projectOdfs = \App\Models\Odf::query()->where('project_id', $route->project_id)->pluck('id');
            if ($projectOdfs->count() === 1) {
                $syncOdfId = $projectOdfs->first();
            }
        }

        $branch->update([
            'odf_id' => $syncOdfId ?? $branch->odf_id,
            'name' => $route->name,
            'code' => preg_match('/(\d+(?:[.-]\d+)*)/', $route->name, $match) ? str_replace('-', '.', $match[1]) : null,
            'type' => in_array($route->route_type, ['backbone', 'feeder'], true) ? 'primary' : 'secondary',
        ]);
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
            throw \Illuminate\Validation\ValidationException::withMessages([
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

    protected function parseDxfPolylines(string $contents): array
    {
        $lines = preg_split('/\R/', $contents);
        $pairs = [];
        for ($i = 0; $i + 1 < count($lines); $i += 2) {
            $pairs[] = [trim($lines[$i]), trim($lines[$i + 1])];
        }

        $entities = [];
        $currentType = null;
        $current = [];
        $point = [];

        $flushPoint = function () use (&$current, &$point): void {
            if (isset($point['x'], $point['y'])) {
                $current[] = [(float) $point['y'], (float) $point['x']];
            }
            $point = [];
        };
        $flushEntity = function () use (&$entities, &$current, &$point): void {
            if (isset($point['x'], $point['y'])) {
                $current[] = [(float) $point['y'], (float) $point['x']];
            }
            $point = [];
            if (count($current) >= 2) {
                $entities[] = $current;
            }
            $current = [];
        };

        foreach ($pairs as [$code, $value]) {
            if ($code === '0') {
                if (in_array($value, ['LINE', 'LWPOLYLINE', 'POLYLINE'], true)) {
                    $flushEntity();
                    $currentType = $value;

                    continue;
                }
                if ($value === 'VERTEX' && $currentType === 'POLYLINE') {
                    $flushPoint();

                    continue;
                }
                if ($currentType === 'LINE' || $currentType === 'LWPOLYLINE' || ($currentType === 'POLYLINE' && $value === 'SEQEND')) {
                    $flushEntity();
                    $currentType = null;
                }

                continue;
            }

            if (! $currentType) {
                continue;
            }
            if ($code === '10') {
                if (isset($point['x'])) {
                    $flushPoint();
                }
                $point['x'] = $value;
            }
            if ($code === '20') {
                $point['y'] = $value;
            }
            if ($currentType === 'LINE' && $code === '11') {
                $flushPoint();
                $point['x'] = $value;
            }
            if ($currentType === 'LINE' && $code === '21') {
                $point['y'] = $value;
            }
        }
        $flushEntity();

        return $entities;
    }

    protected function polylineLength(array $points): int
    {
        $distance = 0.0;
        for ($i = 1; $i < count($points); $i++) {
            [$lat1, $lng1] = $points[$i - 1];
            [$lat2, $lng2] = $points[$i];
            $earth = 6371000;
            $latDelta = deg2rad($lat2 - $lat1);
            $lngDelta = deg2rad($lng2 - $lng1);
            $a = sin($latDelta / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lngDelta / 2) ** 2;
            $distance += $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
        }

        return (int) round($distance);
    }

    protected function pointDistance(array $a, array $b): float
    {
        return $this->polylineLength([$a, $b]);
    }

    protected function projectPointToRoute(float $lat, float $lng, NetworkRoute $route): array
    {
        $points = $route->path ?? [];
        $originLat = $lat;
        $originLng = $lng;
        $best = null;
        $chainage = 0.0;

        for ($i = 1; $i < count($points); $i++) {
            [$aLat, $aLng] = $points[$i - 1];
            [$bLat, $bLng] = $points[$i];
            $a = $this->latLngToMeters((float) $aLat, (float) $aLng, $originLat, $originLng);
            $b = $this->latLngToMeters((float) $bLat, (float) $bLng, $originLat, $originLng);
            $p = $this->latLngToMeters($lat, $lng, $originLat, $originLng);
            $abx = $b['x'] - $a['x'];
            $aby = $b['y'] - $a['y'];
            $ab2 = max(0.000001, ($abx ** 2) + ($aby ** 2));
            $t = max(0, min(1, ((($p['x'] - $a['x']) * $abx) + (($p['y'] - $a['y']) * $aby)) / $ab2));
            $x = $a['x'] + ($abx * $t);
            $y = $a['y'] + ($aby * $t);
            $distance = sqrt((($p['x'] - $x) ** 2) + (($p['y'] - $y) ** 2));
            $segmentLength = sqrt($ab2);

            if (! $best || $distance < $best['distance_m']) {
                $best = [
                    'lat' => $originLat + ($y / 111320),
                    'lng' => $originLng + ($x / (111320 * cos(deg2rad($originLat)))),
                    'distance_m' => $distance,
                    'chainage_m' => $chainage + ($segmentLength * $t),
                    'segment_index' => $i,
                ];
            }
            $chainage += $segmentLength;
        }

        return $best ?? ['lat' => $lat, 'lng' => $lng, 'distance_m' => INF, 'chainage_m' => 0, 'segment_index' => 0];
    }

    protected function routePathBetween(NetworkRoute $route, array $start, array $end): array
    {
        $points = array_values($route->path ?? []);
        if (count($points) < 2) {
            return [[$start['lat'], $start['lng']], [$end['lat'], $end['lng']]];
        }

        $reverse = $start['chainage_m'] > $end['chainage_m'];
        $from = $reverse ? $end : $start;
        $to = $reverse ? $start : $end;

        $path = [[$from['lat'], $from['lng']]];
        for ($i = max(1, (int) $from['segment_index']); $i < count($points); $i++) {
            $vertexChainage = $this->routeVertexChainage($points, $i);
            if ($vertexChainage <= $from['chainage_m'] + 0.01) {
                continue;
            }
            if ($vertexChainage >= $to['chainage_m'] - 0.01) {
                break;
            }
            $path[] = [(float) $points[$i][0], (float) $points[$i][1]];
        }
        $path[] = [$to['lat'], $to['lng']];

        return $reverse ? array_reverse($this->compactPath($path)) : $this->compactPath($path);
    }

    protected function compactPath(array $path): array
    {
        $compact = [];
        foreach ($path as $point) {
            $normalized = [round((float) $point[0], 7), round((float) $point[1], 7)];
            $last = $compact[array_key_last($compact)] ?? null;
            if (! $last || abs($last[0] - $normalized[0]) > 0.0000001 || abs($last[1] - $normalized[1]) > 0.0000001) {
                $compact[] = $normalized;
            }
        }

        return $compact;
    }

    private function latLngToMeters(float $lat, float $lng, float $originLat, float $originLng): array
    {
        return [
            'x' => ($lng - $originLng) * 111320 * cos(deg2rad($originLat)),
            'y' => ($lat - $originLat) * 111320,
        ];
    }

    private function routeVertexChainage(array $points, int $vertexIndex): float
    {
        $chainage = 0.0;
        for ($i = 1; $i <= $vertexIndex && $i < count($points); $i++) {
            $chainage += $this->pointDistance($points[$i - 1], $points[$i]);
        }

        return $chainage;
    }
}

