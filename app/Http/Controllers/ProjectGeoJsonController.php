<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;

class ProjectGeoJsonController extends Controller
{
    public function __invoke(Project $project)
    {
        $project->load(['odfs', 'cabinets', 'houses', 'routes']);
        $features = collect();

        $project->odfs->whereNotNull('latitude')->whereNotNull('longitude')->each(function (Odf $odf) use ($features): void {
            $features->push($this->point('odf', $odf->id, $odf->name, (float) $odf->latitude, (float) $odf->longitude, [
                'address' => $odf->address,
                'fiber_capacity' => $odf->fiber_capacity,
                'port_count' => $odf->port_count,
            ]));
        });

        $project->cabinets->whereNotNull('latitude')->whereNotNull('longitude')->each(function (Cabinet $cabinet) use ($features): void {
            $features->push($this->point('ftth', $cabinet->id, $cabinet->name, (float) $cabinet->latitude, (float) $cabinet->longitude, [
                'odf_id' => $cabinet->odf_id,
                'parent_cabinet_id' => $cabinet->parent_cabinet_id,
                'splitter_count' => $cabinet->splitter_count,
                'capacity' => $cabinet->capacity,
            ]));
        });

        $project->houses->whereNotNull('latitude')->whereNotNull('longitude')->each(function (House $house) use ($features): void {
            $features->push($this->point('house', $house->id, $house->label, (float) $house->latitude, (float) $house->longitude, [
                'cabinet_id' => $house->cabinet_id,
                'status' => $house->status,
                'address' => $house->address,
            ]));
        });

        $project->routes->filter(fn (NetworkRoute $route) => count($route->path ?? []) > 1)->each(function (NetworkRoute $route) use ($features): void {
            $features->push([
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => collect($route->path)->map(fn (array $point) => [(float) $point[1], (float) $point[0]])->values()->all(),
                ],
                'properties' => [
                    'element_type' => 'route',
                    'id' => $route->id,
                    'name' => $route->name,
                    'route_type' => $route->route_type,
                    'duct_length_m' => $route->duct_length_m,
                    'fiber_length_m' => $route->fiber_length_m,
                    'fiber_count' => $route->fiber_count,
                    'microduct_count' => $route->microduct_count,
                    'microduct_type' => $route->microduct_type,
                ],
            ]);
        });

        $exportCode = str($project->code ?: $project->name)->slug()->value() ?: 'projekat-'.$project->id;

        return response()->json([
            'type' => 'FeatureCollection',
            'name' => $project->code ?: $project->name,
            'features' => $features->values()->all(),
        ], 200, [
            'Content-Disposition' => 'attachment; filename="'.$exportCode.'-ftth.geojson"',
        ], JSON_UNESCAPED_UNICODE);
    }

    private function point(string $type, int $id, string $name, float $lat, float $lng, array $properties = []): array
    {
        return [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [$lng, $lat]],
            'properties' => array_merge([
                'element_type' => $type,
                'id' => $id,
                'name' => $name,
            ], $properties),
        ];
    }
}
