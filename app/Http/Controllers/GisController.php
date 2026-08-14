<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesFtthData;
use App\Models\GisRestrictedArea;
use App\Models\GisSegment;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GisController extends Controller
{
    use ManagesFtthData;

    public function import(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'geojson' => ['required', 'file', 'max:'.config('uploads.gis_geojson_kb'), 'extensions:json,geojson'],
            'segment_type' => ['nullable', 'in:road,corridor,sidewalk,restricted'],
            'replace_existing' => ['nullable', 'boolean'],
        ]);

        $contents = $data['geojson']->get();
        $geojson = json_decode($contents, true);
        if (! is_array($geojson)) {
            return back()->withErrors(['geojson' => 'GeoJSON nije validan JSON fajl.']);
        }

        $features = $this->features($geojson);
        if (! count($features)) {
            return back()->withErrors(['geojson' => 'GeoJSON ne sadrzi podrzane geometrije.']);
        }

        $projectId = (int) $data['project_id'];
        $type = $data['segment_type'] ?? 'road';
        $created = 0;

        DB::transaction(function () use ($features, $projectId, $type, $data, &$created): void {
            if (! empty($data['replace_existing'])) {
                if ($type === 'restricted') {
                    GisRestrictedArea::where('project_id', $projectId)->where('area_type', 'restricted')->delete();
                    GisSegment::where('project_id', $projectId)->where('segment_type', 'restricted')->delete();
                } else {
                    GisSegment::where('project_id', $projectId)->where('segment_type', $type)->delete();
                }
            }

            foreach ($features as $feature) {
                if ($type === 'restricted') {
                    foreach ($this->polygonsFromFeature($feature) as $polygon) {
                        if (count($polygon) < 4) {
                            continue;
                        }

                        GisRestrictedArea::create([
                            'project_id' => $projectId,
                            'name' => $feature['properties']['name'] ?? $feature['properties']['NAME'] ?? null,
                            'source' => 'geojson',
                            'area_type' => 'restricted',
                            'polygon' => $polygon,
                            'properties' => $feature['properties'] ?? [],
                        ]);
                        $created++;
                    }
                }

                foreach ($this->pathsFromFeature($feature) as $path) {
                    if (count($path) < 2) {
                        continue;
                    }

                    GisSegment::create([
                        'project_id' => $projectId,
                        'name' => $feature['properties']['name'] ?? $feature['properties']['NAME'] ?? null,
                        'source' => 'geojson',
                        'segment_type' => $type,
                        'is_allowed' => $type !== 'restricted',
                        'length_m' => $this->polylineLength($path),
                        'path' => $path,
                        'properties' => $feature['properties'] ?? [],
                    ]);
                    $created++;
                }
            }
        });

        $label = $type === 'restricted' ? 'zona' : 'segmenata';

        return back()->with('success', "GIS import zavrsen: {$created} {$label}.");
    }

    public function layers(Request $request, Project $project): JsonResponse
    {
        $segments = GisSegment::where('project_id', $project->id)
            ->selectRaw('segment_type, count(*) as count, sum(length_m) as length_m')
            ->groupBy('segment_type')
            ->get()
            ->map(fn ($row) => [
                'type' => $row->segment_type,
                'count' => (int) $row->count,
                'length_m' => (int) $row->length_m,
            ]);

        $restrictedAreas = GisRestrictedArea::where('project_id', $project->id)
            ->where('area_type', 'restricted')
            ->count();

        if ($restrictedAreas > 0) {
            $segments->push([
                'type' => 'restricted_areas',
                'count' => $restrictedAreas,
                'length_m' => null,
            ]);
        }

        return response()->json(['layers' => $segments->values()]);
    }

    public function destroyLayer(Request $request, Project $project, string $type): JsonResponse
    {
        if (! in_array($type, ['road', 'corridor', 'sidewalk', 'restricted', 'restricted_areas'], true)) {
            return response()->json(['message' => 'Nepoznat tip sloja.'], 422);
        }

        $deleted = 0;

        DB::transaction(function () use ($project, $type, &$deleted): void {
            if ($type === 'restricted' || $type === 'restricted_areas') {
                $deleted += GisSegment::where('project_id', $project->id)->where('segment_type', 'restricted')->delete();
                $deleted += GisRestrictedArea::where('project_id', $project->id)->where('area_type', 'restricted')->delete();
            } else {
                $deleted += GisSegment::where('project_id', $project->id)->where('segment_type', $type)->delete();
            }
        });

        return response()->json(['deleted' => $deleted]);
    }

    private function features(array $geojson): array
    {
        if (($geojson['type'] ?? null) === 'FeatureCollection') {
            return array_values($geojson['features'] ?? []);
        }

        if (($geojson['type'] ?? null) === 'Feature') {
            return [$geojson];
        }

        return [['type' => 'Feature', 'geometry' => $geojson, 'properties' => []]];
    }

    private function pathsFromFeature(array $feature): array
    {
        $geometry = $feature['geometry'] ?? null;
        if (! is_array($geometry)) {
            return [];
        }

        return match ($geometry['type'] ?? null) {
            'LineString' => [$this->lineStringPath($geometry['coordinates'] ?? [])],
            'MultiLineString' => array_map(fn (array $line) => $this->lineStringPath($line), $geometry['coordinates'] ?? []),
            default => [],
        };
    }

    private function polygonsFromFeature(array $feature): array
    {
        $geometry = $feature['geometry'] ?? null;
        if (! is_array($geometry)) {
            return [];
        }

        return match ($geometry['type'] ?? null) {
            'Polygon' => [$this->polygonRing($geometry['coordinates'][0] ?? [])],
            'MultiPolygon' => array_map(fn (array $polygon) => $this->polygonRing($polygon[0] ?? []), $geometry['coordinates'] ?? []),
            default => [],
        };
    }

    private function polygonRing(array $coordinates): array
    {
        $ring = $this->lineStringPath($coordinates);
        if (count($ring) >= 3 && $ring[0] !== $ring[count($ring) - 1]) {
            $ring[] = $ring[0];
        }

        return $ring;
    }

    private function lineStringPath(array $coordinates): array
    {
        return collect($coordinates)
            ->filter(fn ($point) => is_array($point) && count($point) >= 2)
            ->map(fn (array $point) => [round((float) $point[1], 7), round((float) $point[0], 7)])
            ->filter(fn (array $point) => $point[0] >= -90 && $point[0] <= 90 && $point[1] >= -180 && $point[1] <= 180)
            ->values()
            ->all();
    }
}
