<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\GisRestrictedArea;
use App\Models\GisSegment;
use App\Models\House;
use App\Models\MapDraft;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\ProjectAppendixItem;

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

    public function build(?int $projectId): array
    {
        $scope = filled($projectId);
        $allOdfs = Odf::with('project')->when($scope, fn ($query) => $query->where('project_id', $projectId))->get();
        $odfs = $allOdfs->filter(fn (Odf $odf) => $odf->latitude !== null && $odf->longitude !== null)->values();

        $allCabinets = Cabinet::with(['project', 'odf', 'parentCabinet', 'branch'])->withCount('houses')
            ->when($scope, fn ($query) => $query->where('project_id', $projectId))->get();
        $cabinets = $allCabinets->filter(fn (Cabinet $cabinet) => $cabinet->latitude !== null && $cabinet->longitude !== null)->values();

        $routes = NetworkRoute::with(['project', 'odf', 'cabinet', 'fromCabinet'])
            ->when($scope, fn ($query) => $query->where('project_id', $projectId))
            ->where(fn ($query) => $query->whereNotNull('path')->orWhere(fn ($linked) => $linked
                ->whereHas('odf', fn ($odf) => $odf->whereNotNull('latitude')->whereNotNull('longitude'))
                ->whereHas('cabinet', fn ($cabinet) => $cabinet->whereNotNull('latitude')->whereNotNull('longitude'))))
            ->get();

        $housesPerCabinet = House::whereNotNull('cabinet_id')->when($scope, fn ($query) => $query->where('project_id', $projectId))
            ->selectRaw('cabinet_id, count(*) as cnt')->groupBy('cabinet_id')->pluck('cnt', 'cabinet_id')->all();
        $houses = House::with(['project', 'cabinet'])->when($scope, fn ($query) => $query->where('project_id', $projectId))
            ->whereNotNull('latitude')->whereNotNull('longitude')->get();
        $appendixItems = ProjectAppendixItem::with('project')->when($scope, fn ($query) => $query->where('project_id', $projectId))
            ->whereNotNull('latitude')->whereNotNull('longitude')->get();
        $gisSegments = GisSegment::with('project')->when($scope, fn ($query) => $query->where('project_id', $projectId))
            ->where('is_allowed', true)->whereIn('segment_type', ['road', 'corridor', 'sidewalk'])->get();
        $restrictedAreas = GisRestrictedArea::with('project')->when($scope, fn ($query) => $query->where('project_id', $projectId))
            ->where('area_type', 'restricted')->get();

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
                    'length_m' => $segment->length_m, 'path' => $segment->path,
                ]),
                'gis_restricted_areas' => $restrictedAreas->map(fn (GisRestrictedArea $area) => [
                    'id' => $area->id, 'project_id' => $area->project_id, 'project' => $area->project->name,
                    'name' => $area->name, 'source' => $area->source, 'area_type' => $area->area_type, 'polygon' => $area->polygon,
                ]),
                'appendix_items' => $appendixItems->map(fn (ProjectAppendixItem $item) => [
                    'id' => $item->id, 'project_id' => $item->project_id, 'project' => $item->project->name, 'type' => $item->type,
                    'label' => match ($item->type) {
                        'manhole' => 'Saht', 'boring_fi_130' => 'FI 130', 'splice' => 'Spojnica', 'loop' => 'Rezerva', default => $item->type
                    },
                    'quantity' => (float) $item->quantity, 'unit' => $item->unit, 'note' => $item->note,
                    'lat' => (float) $item->latitude, 'lng' => (float) $item->longitude,
                    'length_m' => $item->length_m === null ? null : (float) $item->length_m,
                    'angle_deg' => $item->angle_deg === null ? null : (float) $item->angle_deg,
                    'width_m' => $item->width_m === null ? null : (float) $item->width_m,
                ]),
            ],
        ];
    }
}
