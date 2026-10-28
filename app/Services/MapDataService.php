<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\GisRestrictedArea;
use App\Models\GisSegment;
use App\Models\House;
use App\Models\LargePlannerConstraint;
use App\Models\LargePlannerZone;
use App\Models\MapDraft;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\PoleBranch;
use App\Models\PoleBranchLink;
use App\Models\ProjectAppendixItem;
use App\Models\SurveyPoint;
use App\Support\NearbyPointIndex;
use App\Support\SurveyCategories;
use Illuminate\Support\Collection;

class MapDataService
{
    public function __construct(private readonly ProjectMaterialService $materials) {}

    public function selectors(?int $projectId): array
    {
        return [
            'odfs' => Odf::with('project')->when($projectId, fn ($query) => $query->where('project_id', $projectId))->orderBy('name')->get(),
            'cabinets' => Cabinet::with('project')->when($projectId, fn ($query) => $query->where('project_id', $projectId))->orderBy('name')->get(),
        ];
    }

    public function build(?int $projectId, ?array $bbox = null): array
    {
        $scope = filled($projectId);
        $allOdfs = Odf::with('project')->when($scope, fn ($query) => $query->where('project_id', $projectId))->get();
        $odfs = $allOdfs->filter(fn (Odf $odf) => $odf->latitude !== null && $odf->longitude !== null && $this->pointInBbox((float) $odf->latitude, (float) $odf->longitude, $bbox))->values();

        $allCabinets = Cabinet::with(['project', 'odf', 'parentCabinet', 'branch'])->withCount('houses')
            ->when($scope, fn ($query) => $query->where('project_id', $projectId))->get();
        $cabinets = $allCabinets->filter(fn (Cabinet $cabinet) => $cabinet->latitude !== null && $cabinet->longitude !== null && $this->pointInBbox((float) $cabinet->latitude, (float) $cabinet->longitude, $bbox))->values();

        $routes = NetworkRoute::with(['project', 'odf', 'cabinet', 'fromCabinet'])
            ->when($scope, fn ($query) => $query->where('project_id', $projectId))
            ->where(fn ($query) => $query
                ->where('route_type', '!=', 'drop')
                ->orWhereHas('project', fn ($project) => $project->where('planning_mode', '!=', 'large_auto')))
            ->where(fn ($query) => $query->whereNotNull('path')->orWhere(fn ($linked) => $linked
                ->whereHas('odf', fn ($odf) => $odf->whereNotNull('latitude')->whereNotNull('longitude'))
                ->whereHas('cabinet', fn ($cabinet) => $cabinet->whereNotNull('latitude')->whereNotNull('longitude'))))
            ->get();
        if ($bbox) {
            $routes = $routes->filter(fn (NetworkRoute $route) => $this->pathIntersectsBbox($route->path ?: [], $bbox))->values();
        }

        $housesPerCabinet = House::whereNotNull('cabinet_id')->when($scope, fn ($query) => $query->where('project_id', $projectId))
            ->selectRaw('cabinet_id, count(*) as cnt')->groupBy('cabinet_id')->pluck('cnt', 'cabinet_id')->all();
        $houses = House::with(['project', 'cabinet'])->when($scope, fn ($query) => $query->where('project_id', $projectId))
            ->whereNotNull('latitude')->whereNotNull('longitude')->get();
        if ($bbox) {
            $houses = $houses->filter(fn (House $house) => $this->pointInBbox((float) $house->latitude, (float) $house->longitude, $bbox))->values();
        }
        $appendixItems = ProjectAppendixItem::with('project')->when($scope, fn ($query) => $query->where('project_id', $projectId))
            ->whereNotNull('latitude')->whereNotNull('longitude')->get();
        if ($bbox) {
            $appendixItems = $appendixItems->filter(fn (ProjectAppendixItem $item) => $this->pointInBbox((float) $item->latitude, (float) $item->longitude, $bbox))->values();
        }
        // Survey points can number in the thousands, so the bbox is applied in SQL.
        $surveyIndex = new NearbyPointIndex(SurveyCategories::duplicateDistance());
        $surveyObjects = SurveyPoint::with('project:id,name')->when($scope, fn ($query) => $query->where('project_id', $projectId))
            ->whereNotNull('survey_category')->whereNotNull('latitude')->whereNotNull('longitude')
            ->when($bbox, fn ($query) => $query->whereBetween('latitude', [$bbox[0], $bbox[2]])->whereBetween('longitude', [$bbox[1], $bbox[3]]))
            ->orderBy('id')->get()
            ->filter(fn (SurveyPoint $point) => $surveyIndex->addIfFree((float) $point->x, (float) $point->y, $point->source_crs ?: 'unknown'))
            ->values();
        $poleBranches = $this->poleBranches($scope ? $projectId : null, $bbox);
        $branchCodes = $poleBranches->pluck('code', 'id');
        $gisSegments = GisSegment::with('project')->when($scope, fn ($query) => $query->where('project_id', $projectId))
            ->where('is_allowed', true)->whereIn('segment_type', ['road', 'corridor', 'sidewalk'])->get();
        if ($bbox) {
            $gisSegments = $gisSegments->filter(fn (GisSegment $segment) => $this->pathIntersectsBbox($segment->path ?: [], $bbox))->values();
        }
        $restrictedAreas = GisRestrictedArea::with('project')->when($scope, fn ($query) => $query->where('project_id', $projectId))
            ->where('area_type', 'restricted')->get();
        if ($bbox) {
            $restrictedAreas = $restrictedAreas->filter(fn (GisRestrictedArea $area) => $this->pathIntersectsBbox($area->polygon ?: [], $bbox))->values();
        }
        $largePlannerConstraints = LargePlannerConstraint::query()
            ->when($scope, fn ($query) => $query->where('project_id', $projectId))
            ->get();
        $largePlannerZones = LargePlannerZone::query()
            ->when($scope, fn ($query) => $query->where('project_id', $projectId))
            ->withCount('houses')
            ->get();

        return [
            'odfs_for_select' => $allOdfs->sortBy('name')->values(),
            'cabinets_for_select' => $allCabinets->sortBy('name')->values(),
            'data' => [
                'drafts' => MapDraft::with('project')->when($scope, fn ($query) => $query->where('project_id', $projectId))->latest()->get()->map(fn (MapDraft $draft) => [
                    'project_id' => $draft->project_id, 'project' => $draft->project->name, 'payload' => $draft->payload,
                    'updated_at' => $draft->updated_at?->format('Y-m-d H:i'),
                ]),
                'odfs' => $odfs->map(fn (Odf $odf) => [
                    'id' => $odf->id, 'project_id' => $odf->project_id, 'name' => $odf->name, 'project' => $odf->project->name,
                    'address' => $odf->address, 'fiber_capacity' => $odf->fiber_capacity, 'port_count' => $odf->port_count,
                    'lat' => (float) $odf->latitude, 'lng' => (float) $odf->longitude,
                ]),
                'cabinets' => $cabinets->map(fn (Cabinet $cabinet) => [
                    'id' => $cabinet->id, 'project_id' => $cabinet->project_id, 'name' => $cabinet->name, 'project' => $cabinet->project->name,
                    'odf_id' => $cabinet->odf_id, 'parent_cabinet_id' => $cabinet->parent_cabinet_id, 'branch_id' => $cabinet->branch_id,
                    'branch_name' => $cabinet->branch?->name, 'branch_code' => $cabinet->branch?->code,
                    'odf' => $cabinet->odf->name ?? 'Nije povezano', 'parent_cabinet' => $cabinet->parentCabinet?->name,
                    'fed_from' => $cabinet->parentCabinet?->name ?? ($cabinet->odf->name ?? 'Nije povezano'), 'address' => $cabinet->address,
                    'splitter_count' => $cabinet->splitter_count, 'ports_per_splitter' => $cabinet->ports_per_splitter,
                    'capacity' => $cabinet->capacity, 'used_ports' => $cabinet->houses_count,
                    'free_ports' => max($cabinet->capacity - $cabinet->houses_count, 0),
                    'utilization' => (int) round($cabinet->houses_count / max($cabinet->capacity, 1) * 100),
                    'lat' => (float) $cabinet->latitude, 'lng' => (float) $cabinet->longitude,
                ]),
                'houses' => $houses->map(fn (House $house) => [
                    'id' => $house->id, 'project_id' => $house->project_id, 'label' => $house->label, 'project' => $house->project->name,
                    'cabinet' => $house->cabinet->name ?? 'Nije dodijeljeno', 'cabinet_id' => $house->cabinet_id,
                    'address' => $house->address, 'status' => $house->status,
                    'large_planner_zone_id' => $house->large_planner_zone_id,
                    'is_sling' => (bool) preg_match('/\b[sš]linga?\b/iu', (string) $house->address),
                    'lat' => (float) $house->latitude, 'lng' => (float) $house->longitude,
                ]),
                'routes' => $routes->map(fn (NetworkRoute $route) => [
                    'id' => $route->id, 'name' => $route->name, 'project' => $route->project->name, 'type' => $route->route_type,
                    'installation_type' => $route->installation_type, 'trench_group' => $route->trench_group,
                    'counts_as_trench' => (bool) $route->counts_as_trench, 'trench_length_m' => $route->trench_length_m,
                    'microduct_type' => $route->microduct_type, 'fiber_count' => $route->fiber_count,
                    'duct_length_m' => $route->duct_length_m, 'fiber_length_m' => $route->fiber_length_m,
                    'from' => $route->from_type === 'cabinet' ? ($route->fromCabinet?->name ?? '-') : ($route->odf?->name ?? '-'),
                    'to' => $route->cabinet?->name ?? '-', 'length' => $route->duct_length_m, 'microduct' => $route->microduct_type,
                    'fibers' => $route->fiber_count, 'odf_id' => $route->odf_id, 'cabinet_id' => $route->cabinet_id,
                    'from_type' => $route->from_type, 'from_id' => $route->from_id, 'to_type' => $route->to_type, 'to_id' => $route->to_id,
                    'project_id' => $route->project_id, 'microduct_count' => $route->microduct_count ?? 0,
                    'occupancy' => $this->materials->routeOccupancy($route, $housesPerCabinet), 'status' => $route->status, 'note' => $route->note,
                    'path' => $route->path ?: ($route->odf && $route->cabinet ? [
                        [(float) $route->odf->latitude, (float) $route->odf->longitude],
                        [(float) $route->cabinet->latitude, (float) $route->cabinet->longitude],
                    ] : []),
                ]),
                'gis_segments' => $gisSegments->map(fn (GisSegment $segment) => [
                    'id' => $segment->id, 'project_id' => $segment->project_id, 'project' => $segment->project->name,
                    'name' => $segment->name, 'source' => $segment->source, 'segment_type' => $segment->segment_type,
                    'planning_corridor_type' => $segment->planning_corridor_type,
                    'length_m' => $segment->length_m, 'path' => $segment->path,
                ]),
                'gis_restricted_areas' => $restrictedAreas->map(fn (GisRestrictedArea $area) => [
                    'id' => $area->id, 'project_id' => $area->project_id, 'project' => $area->project->name,
                    'name' => $area->name, 'source' => $area->source, 'area_type' => $area->area_type, 'polygon' => $area->polygon,
                ]),
                'large_planner_constraints' => $largePlannerConstraints->map(fn (LargePlannerConstraint $constraint) => [
                    'id' => $constraint->id, 'project_id' => $constraint->project_id, 'type' => $constraint->type,
                    'name' => $constraint->name, 'geometry' => $constraint->geometry,
                ]),
                'large_planner_zones' => $largePlannerZones->map(fn (LargePlannerZone $zone) => [
                    'id' => $zone->id, 'project_id' => $zone->project_id, 'name' => $zone->name,
                    'status' => $zone->status, 'geometry' => $zone->geometry, 'houses_count' => $zone->houses_count,
                ]),
                'appendix_items' => $appendixItems->map(fn (ProjectAppendixItem $item) => [
                    'id' => $item->id, 'project_id' => $item->project_id, 'project' => $item->project->name, 'type' => $item->type,
                    'label' => match ($item->type) {
                        'manhole' => 'Saht', 'boring_fi_130' => 'FI 130', 'splice' => 'Spojnica', 'loop' => 'Rezerva', 'pole' => 'Stub', default => $item->type
                    },
                    'quantity' => (float) $item->quantity, 'unit' => $item->unit, 'note' => $item->note,
                    'lat' => (float) $item->latitude, 'lng' => (float) $item->longitude,
                    'length_m' => $item->length_m === null ? null : (float) $item->length_m,
                    'angle_deg' => $item->angle_deg === null ? null : (float) $item->angle_deg,
                    'width_m' => $item->width_m === null ? null : (float) $item->width_m,
                ]),
                'survey_objects' => $surveyObjects->map(fn (SurveyPoint $point) => [
                    'id' => $point->id,
                    'project_id' => $point->project_id,
                    'project' => $point->project->name,
                    'point_no' => $point->point_no,
                    'pole_no' => $point->pole_no,
                    'x' => (float) $point->x,
                    'y' => (float) $point->y,
                    'z' => $point->z === null ? null : (float) $point->z,
                    'lat' => (float) $point->latitude,
                    'lng' => (float) $point->longitude,
                    'original_label' => $point->original_label ?: $point->code,
                    'category' => $point->survey_category,
                    'cad_label' => SurveyCategories::cadLabel($point->survey_category, (int) ($point->pole_no ?: $point->point_no)),
                    ...SurveyCategories::presentation($point->survey_category),
                    'branch_code' => $branchCodes[$point->pole_branch_id] ?? null,
                    'branch_position' => $point->branch_position,
                    'source_file' => $point->source_file,
                    'source_crs' => $point->source_crs,
                    'imported_at' => $point->imported_at?->toISOString(),
                ]),
                'pole_branches' => $poleBranches->values(),
                'pole_branch_links' => PoleBranchLink::with(['fromPoint', 'toPoint'])
                    ->when($scope, fn ($query) => $query->where('project_id', $projectId))->get()
                    ->filter(fn (PoleBranchLink $link) => $link->fromPoint && $link->toPoint)
                    ->map(fn (PoleBranchLink $link) => [
                        'id' => $link->id,
                        'project_id' => $link->project_id,
                        'type' => $link->type,
                        'from' => $this->poleSummary($link->fromPoint),
                        'to' => $this->poleSummary($link->toPoint),
                    ])->values(),
            ],
        ];
    }

    /** Pole branches with their line: the pole they hang from, then their own poles in order. */
    private function poleBranches(?int $projectId, ?array $bbox): Collection
    {
        $branches = PoleBranch::with(['fromPoint', 'poles', 'parent:id,code', 'odf:id,name'])
            ->when($projectId, fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('project_id')->orderBy('sort_order')->get();
        $describe = fn (SurveyPoint $pole) => $this->poleSummary($pole);

        return $branches->map(function (PoleBranch $branch) use ($describe): array {
            // A re-measured pole shares its position with the original; draw it once.
            $poles = $branch->poles->unique('branch_position')->map($describe)->values();
            $from = $branch->fromPoint ? $describe($branch->fromPoint) : null;
            $longSpanTargets = collect($branch->long_spans ?? [])->pluck('length_m', 'to_id');

            return [
                'id' => $branch->id,
                'project_id' => $branch->project_id,
                'code' => $branch->code,
                'parent_code' => $branch->parent?->code,
                'depth' => $branch->depth,
                'pole_count' => $branch->pole_count,
                'length_m' => $branch->length_m,
                'max_span_m' => $branch->max_span_m,
                'from' => $from,
                'poles' => $poles,
                'path' => collect($from ? [$from, ...$poles] : $poles)->map(fn (array $pole) => [$pole['lat'], $pole['lng']])->all(),
                'long_spans' => $longSpanTargets->map(fn ($length, $toId) => ['to_id' => (int) $toId, 'length_m' => (float) $length])->values(),
                'odf_name' => $branch->odf?->name,
                // Where the cable leaves the underground line and climbs the first pole.
                'entry' => $branch->entry_lat === null ? null : ['lat' => $branch->entry_lat, 'lng' => $branch->entry_lng, 'length_m' => $branch->entry_length_m],
            ];
        })->filter(fn (array $branch) => ! $bbox || $this->pathIntersectsBbox($branch['path'], $bbox))
            ->values();
    }

    /** @return array{id: int, label: string, lat: float, lng: float} */
    private function poleSummary(SurveyPoint $pole): array
    {
        return [
            'id' => $pole->id,
            'label' => SurveyCategories::cadLabel($pole->survey_category, (int) ($pole->pole_no ?: $pole->point_no)),
            'lat' => (float) $pole->latitude,
            'lng' => (float) $pole->longitude,
        ];
    }

    private function pointInBbox(float $lat, float $lng, ?array $bbox): bool
    {
        return ! $bbox || ($lat >= $bbox[0] && $lat <= $bbox[2] && $lng >= $bbox[1] && $lng <= $bbox[3]);
    }

    private function pathIntersectsBbox(array $path, array $bbox): bool
    {
        $found = [];
        $walk = function (array $node) use (&$walk, &$found): void {
            if (count($node) >= 2 && is_numeric($node[0] ?? null) && is_numeric($node[1] ?? null)) {
                $found[] = $node;

                return;
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($path);
        $points = collect($found);
        if ($points->isEmpty()) {
            return false;
        }
        $lats = $points->map(fn ($point) => (float) $point[0]);
        $lngs = $points->map(fn ($point) => (float) $point[1]);

        return $lats->max() >= $bbox[0] && $lats->min() <= $bbox[2] && $lngs->max() >= $bbox[1] && $lngs->min() <= $bbox[3];
    }
}
