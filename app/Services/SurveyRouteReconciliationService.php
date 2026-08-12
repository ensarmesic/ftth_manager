<?php

namespace App\Services;

use App\Models\NetworkRoute;
use App\Models\SurveyPoint;

class SurveyRouteReconciliationService
{
    public function __construct(private readonly GeometryService $geometry) {}

    public function projectRoutingTrenchPaths(int $projectId, string $excludeBatch, float $trenchGapMeters): array
    {
        $savedPaths = NetworkRoute::where('project_id', $projectId)
            ->whereIn('route_type', ['trench', 'backbone', 'feeder', 'distribution'])
            ->where(fn ($query) => $query->whereNull('import_batch')->orWhere('import_batch', '!=', $excludeBatch))
            ->get()
            ->pluck('path')
            ->filter(fn ($path) => is_array($path) && count($path) >= 2)
            ->values()
            ->all();
        if ($savedPaths !== []) {
            return $savedPaths;
        }

        $paths = [];
        $batches = SurveyPoint::where('project_id', $projectId)
            ->where('kind', 'trench')
            ->where('import_batch', '!=', $excludeBatch)
            ->orderBy('import_batch')
            ->orderBy('point_no')
            ->get()
            ->groupBy('import_batch');
        foreach ($batches as $batchPoints) {
            $current = [];
            foreach ($batchPoints as $point) {
                $coordinate = [(float) $point->latitude, (float) $point->longitude];
                if ($current !== [] && $this->geometry->distanceBetweenPoints(end($current), $coordinate) > $trenchGapMeters) {
                    if (count($current) >= 2) {
                        $paths[] = $this->geometry->compactPath($current);
                    }
                    $current = [];
                }
                $current[] = $coordinate;
            }
            if (count($current) >= 2) {
                $paths[] = $this->geometry->compactPath($current);
            }
        }

        return $paths;
    }

    /** @param int[] $excludeIds */
    public function findExistingDuctRoute(
        int $projectId,
        array $duct,
        string $routeType,
        ?int $houseId,
        array $excludeIds,
        float $toleranceMeters,
    ): ?NetworkRoute {
        $query = NetworkRoute::where('project_id', $projectId)
            ->where('route_type', $routeType)
            ->where('microduct_type', $duct['microduct_type'])
            ->whereNotIn('id', $excludeIds);

        if ($routeType === 'drop' && $houseId !== null) {
            return $query->where('to_type', 'house')->where('to_id', $houseId)->first();
        }

        if ($duct['zo_tag'] !== null) {
            $query->where('note', 'like', '%ZO '.$duct['zo_tag'].'%');
        } elseif ($duct['color'] !== null) {
            $query->where('name', 'like', '%'.$duct['color'].'%');
        } else {
            $query->where('name', 'like', '%'.$duct['microduct_type'].'%');
        }

        return $query->get()->first(fn (NetworkRoute $route) => $this->pathsTouchOrOverlap(
            $route->path ?? [],
            $duct['path'],
            $toleranceMeters,
        ));
    }

    /** @param int[] $excludeIds */
    public function findExistingRouteGeometry(
        int $projectId,
        string $type,
        array $path,
        array $excludeIds,
        float $toleranceMeters,
    ): ?NetworkRoute {
        return NetworkRoute::where('project_id', $projectId)
            ->where('route_type', $type)
            ->whereNotIn('id', $excludeIds)
            ->get()
            ->first(fn (NetworkRoute $route) => $this->pathsTouchOrOverlap(
                $route->path ?? [],
                $path,
                $toleranceMeters,
            ));
    }

    public function mergeTouchingPaths(array $existing, array $incoming, float $toleranceMeters): array
    {
        if (count($existing) < 2) {
            return $this->geometry->compactPath($incoming);
        }
        if (count($incoming) < 2) {
            return $this->geometry->compactPath($existing);
        }

        $variants = [
            [$existing, $incoming],
            [$existing, array_reverse($incoming)],
            [array_reverse($existing), $incoming],
            [array_reverse($existing), array_reverse($incoming)],
        ];

        foreach ($variants as [$a, $b]) {
            if ($this->geometry->distanceBetweenPoints($a[count($a) - 1], $b[0]) <= $toleranceMeters) {
                if ($this->geometry->distanceBetweenPoints($a[count($a) - 1], $b[0]) <= 0.5) {
                    array_shift($b);
                }

                return $this->geometry->compactPath(array_merge($a, $b));
            }
        }

        return $this->geometry->compactPath($existing);
    }

    public function ductImportNote(array $duct): string
    {
        return 'Geodetski snimak - mikrocijev'
            .($duct['color'] ? ' boja: '.$duct['color'] : '')
            .($duct['zo_tag'] !== null ? ' pripada: ZO '.$duct['zo_tag'] : '')
            .(($duct['prepared_sling'] ?? false) ? ' | SLINGA za kucu '.($duct['house_ref'] ?? '') : '');
    }

    public function appendImportNote(?string $existing, string $new): string
    {
        $existing = trim((string) $existing);
        if ($existing === '' || str_contains($existing, $new)) {
            return $existing === '' ? $new : $existing;
        }

        return $existing."\n".$new;
    }

    private function pathsTouchOrOverlap(array $existing, array $incoming, float $toleranceMeters): bool
    {
        if (count($existing) < 2 || count($incoming) < 2) {
            return false;
        }

        $start = $incoming[0];
        $end = $incoming[count($incoming) - 1];
        if ($this->geometry->distanceToRoute($start[0], $start[1], $existing) <= $toleranceMeters
            && $this->geometry->distanceToRoute($end[0], $end[1], $existing) <= $toleranceMeters) {
            return true;
        }

        foreach ($this->pathEndpoints($existing) as $a) {
            foreach ($this->pathEndpoints($incoming) as $b) {
                if ($this->geometry->distanceBetweenPoints($a, $b) <= $toleranceMeters) {
                    return true;
                }
            }
        }

        return false;
    }

    private function pathEndpoints(array $path): array
    {
        return [$path[0], $path[count($path) - 1]];
    }
}
