<?php

namespace App\Services;

use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use App\Models\Project;
use Illuminate\Support\Collection;

class ProjectValidationService
{
    public function __construct(
        private readonly GeometryService $geometry,
        private readonly ProjectMaterialService $materials,
    ) {}

    public function validateProject(Project $project): array
    {
        $items = [];
        $project->loadMissing(['odfs.cabinets', 'houses.cabinet', 'cabinets.odf', 'cabinets.houses', 'routes']);
        $branchRoutes = $this->branchRoutes($project);

        if ($project->odfs->isEmpty()) {
            $items[] = $this->validationItem('warning', 'Projekat nema ODF.', 'project', $project->id, 'Dodaj ODF prije potvrde mreznog plana.');
        }
        if ($project->cabinets->isEmpty()) {
            $items[] = $this->validationItem('info', 'Projekat nema ODO ormarice.', 'project', $project->id, 'Pokreni automatsko planiranje ODO ormarica.');
        }
        if ($project->houses->isEmpty()) {
            $items[] = $this->validationItem('info', 'Projekat nema kuce.', 'project', $project->id, 'Dodaj kuce iz mape ili liste.');
        }
        foreach ($project->odfs as $odf) {
            if ($odf->latitude === null || $odf->longitude === null) {
                $items[] = $this->validationItem('error', "{$odf->name} nema koordinate.", 'odf', $odf->id, 'Postavi ODF na mapi.');
            }
            if ($odf->cabinets->isEmpty()) {
                $items[] = $this->validationItem('warning', "{$odf->name} nema povezan ODO.", 'odf', $odf->id, 'Poveži najmanje jedan ODO na ODF.');
            }

            if ($odf->fiber_capacity > 0) {
                $usedFibers = $odf->cabinets->filter(fn ($c) => $c->parent_cabinet_id === null)->sum('splitter_count');
                if ($usedFibers > $odf->fiber_capacity) {
                    $items[] = $this->validationItem('error', "{$odf->name}: prekoračen kapacitet ({$usedFibers}/{$odf->fiber_capacity} vlakana).", 'odf', $odf->id, 'Smanji broj ODO ormarića ili povećaj kapacitet ODF-a.');
                } elseif ($usedFibers > 0 && $usedFibers > $odf->fiber_capacity * 0.85) {
                    $items[] = $this->validationItem('warning', "{$odf->name}: kapacitet skoro popunjen ({$usedFibers}/{$odf->fiber_capacity} vlakana).", 'odf', $odf->id, 'Razmotri dodavanje novog ODF-a.');
                }
            }
        }

        foreach ($project->houses as $house) {
            if ($house->latitude === null || $house->longitude === null) {
                $items[] = $this->validationItem('error', "{$house->label} nema koordinate.", 'house', $house->id, 'Postavi kuću na mapi.');
            }
            if (! $house->cabinet_id) {
                $items[] = $this->validationItem('warning', "{$house->label} nema povezan ODO.", 'house', $house->id, 'Dodijeli kucu ODO ormaricu.');
            }
            if ($house->cabinet && $house->latitude && $house->longitude && $house->cabinet->latitude && $house->cabinet->longitude) {
                $distance = $this->distanceMeters((float) $house->latitude, (float) $house->longitude, (float) $house->cabinet->latitude, (float) $house->cabinet->longitude);
                if ($distance > 120) {
                    $items[] = $this->validationItem('warning', "{$house->label} je predaleko od ODO.", 'house', $house->id, 'Razmotri novi ODO ili drugacije grupisanje.');
                }
            }
            if ($branchRoutes->isNotEmpty() && $house->latitude && $house->longitude) {
                $nearest = $this->nearestBranch((float) $house->latitude, (float) $house->longitude, $branchRoutes);
                if ($nearest && $nearest['distance_m'] > 60) {
                    $items[] = $this->validationItem('warning', "{$house->label} je predaleko od kraka.", 'house', $house->id, 'Pomjeri kucu ili dodaj krak blize objektu.');
                }
                if ($house->cabinet && $nearest) {
                    $cabinetNearest = $this->nearestBranch((float) $house->cabinet->latitude, (float) $house->cabinet->longitude, $branchRoutes);
                    if ($cabinetNearest && $cabinetNearest['route']->id !== $nearest['route']->id) {
                        $items[] = $this->validationItem('error', "{$house->label} je povezana na ODO drugog kraka.", 'house', $house->id, 'Ponovi Auto ODO ili rucno ispravi vezu.');
                    }
                }
            }
        }

        foreach ($project->cabinets as $cabinet) {
            $houseCount = $cabinet->houses->count();
            $neededSplitters = $this->splitterCount($houseCount);
            if (! $cabinet->odf_id && ! $cabinet->parent_cabinet_id) {
                $items[] = $this->validationItem('warning', "{$cabinet->name} nema povezan ODF.", 'cabinet', $cabinet->id, 'Povezi ODO sa najblizim ODF-om.');
            }
            if ($cabinet->latitude === null || $cabinet->longitude === null) {
                $items[] = $this->validationItem('error', "{$cabinet->name} nema koordinate.", 'cabinet', $cabinet->id, 'Postavi ODO na mapi.');
            }
            if ($cabinet->splitter_count > 3 || $cabinet->ports_per_splitter > 4) {
                $items[] = $this->validationItem('error', "{$cabinet->name} ima neispravnu splitter konfiguraciju.", 'cabinet', $cabinet->id, 'Koristi najviše 3 splittera sa po 4 porta.');
            }
            if ($houseCount === 0) {
                $items[] = $this->validationItem('info', "{$cabinet->name} nema povezanih kuća.", 'cabinet', $cabinet->id, 'Poveži kuće na ODO.');
            }
            if ($houseCount > 12) {
                $items[] = $this->validationItem('error', "{$cabinet->name} ima vise od 12 kuca.", 'cabinet', $cabinet->id, 'Rastereti ODO ili kreiraj dodatni ODO.');
            }
            if ($cabinet->splitter_count < $neededSplitters) {
                $items[] = $this->validationItem('error', "{$cabinet->name} nema dovoljno splittera.", 'cabinet', $cabinet->id, "Postavi {$neededSplitters} splittera.");
            }
            if ($branchRoutes->isNotEmpty() && $cabinet->latitude && $cabinet->longitude) {
                $nearest = $this->nearestBranch((float) $cabinet->latitude, (float) $cabinet->longitude, $branchRoutes);
                if ($nearest && $nearest['distance_m'] > 10) {
                    $items[] = $this->validationItem('warning', "{$cabinet->name} nije na trasi/kraku.", 'cabinet', $cabinet->id, 'Pomjeri ODO na najblizu tacku trase.');
                }
            }
        }

        $housesPerCabinet = $project->cabinets->mapWithKeys(fn ($cab) => [$cab->id => $cab->houses->count()])->all();

        foreach ($project->routes as $route) {
            if ($route->route_type === 'trench') {
                if ($route->duct_length_m <= 0) {
                    $items[] = $this->validationItem('error', "{$route->name} nema ispravnu dužinu.", 'route', $route->id, 'Uredi geometriju trase.');
                }

                continue;
            }
            if (! $route->hasCompleteMicroductData()) {
                $items[] = $this->validationItem('warning', "{$route->name} nema kompletne podatke o mikrocijevi.", 'route', $route->id, 'Unesi tip, broj i dužinu mikrocijevi.');
            }
            if ($route->hasIncompleteCableData()) {
                $items[] = $this->validationItem('warning', "{$route->name} ima broj vlakana, ali nema dužinu kabla.", 'route', $route->id, 'Unesi dužinu kabla ili ukloni broj vlakana.');
            }
            if (! $route->installation_type) {
                $items[] = $this->validationItem('warning', "{$route->name} nema tip polaganja.", 'route', $route->id, 'Odaberi podzemno ili zračno polaganje.');
            }
            if ($route->duct_length_m <= 0) {
                $items[] = $this->validationItem('error', "{$route->name} nema ispravnu dužinu.", 'route', $route->id, 'Uredi geometriju trase.');
            }
            if ($route->route_type === 'drop' && ($route->to_type !== 'house' || ! $route->to_id)) {
                $items[] = $this->validationItem('error', "{$route->name} drop trasa nema ciljnu kuću.", 'route', $route->id, 'Postavi to_type house i to_id kuće.');
            }
            if (in_array($route->route_type, ['backbone', 'distribution'], true) && (! $route->from_type || ! $route->from_id || ! $route->to_type || ! $route->to_id)) {
                $items[] = $this->validationItem('warning', "{$route->name} nema kompletne from/to veze.", 'route', $route->id, 'Poveži oba kraja trase.');
            }
            $occupancy = $this->routeOccupancy($route, $housesPerCabinet);
            if ($occupancy['used_fibers'] > $occupancy['fiber_capacity']) {
                $items[] = $this->validationItem('error', "{$route->name} ima više zauzetih vlakana od kapaciteta.", 'route', $route->id, 'Povećaj kapacitet kabla.');
            } elseif ($occupancy['utilization_percent'] > 80) {
                $items[] = $this->validationItem('warning', "{$route->name} ima preko 80% zauzeća vlakana.", 'route', $route->id, 'Planiraj rezervni kapacitet.');
            }
            if (! $route->path) {
                $items[] = $this->validationItem('warning', "{$route->name} nema geometriju.", 'route', $route->id, 'Uredi geometriju trase na mapi.');
            } elseif (count($route->path) < 2) {
                $items[] = $this->validationItem('error', "{$route->name} ima manje od dvije tacke.", 'route', $route->id, 'Dodaj najmanje dvije tacke trase.');
            } else {
                $path = array_values($route->path);
                $actualLength = $this->geometry->polylineLength($path);
                $lengthTolerance = max(5, $actualLength * 0.05);
                if (abs((float) $route->duct_length_m - $actualLength) > $lengthTolerance) {
                    $items[] = $this->validationItem('warning', "{$route->name}: spremljena dužina ne odgovara geometriji ({$route->duct_length_m} m / {$actualLength} m).", 'route', $route->id, 'Ponovo sačuvaj geometriju trase da se dužina preračuna.');
                }

                for ($index = 1; $index < count($path); $index++) {
                    if ($this->geometry->distanceBetweenPoints($path[$index - 1], $path[$index]) < 0.02) {
                        $items[] = $this->validationItem('warning', "{$route->name} ima uzastopne duple tačke.", 'route', $route->id, 'Očisti duple tačke geometrije.');

                        break;
                    }
                }

                if ($route->route_type === 'drop') {
                    $house = $route->to_type === 'house' ? $project->houses->firstWhere('id', $route->to_id) : null;
                    $cabinet = $route->cabinet_id ? $project->cabinets->firstWhere('id', $route->cabinet_id) : null;
                    $endpoints = [$path[0], $path[count($path) - 1]];
                    if ($house && $house->latitude !== null && $house->longitude !== null && ! $this->endpointTouches($endpoints, (float) $house->latitude, (float) $house->longitude)) {
                        $items[] = $this->validationItem('error', "{$route->name} ne završava na povezanoj kući {$house->label}.", 'route', $route->id, 'Ponovo rutiraj drop od tačne koordinate kuće.');
                    }
                    if ($cabinet && $cabinet->latitude !== null && $cabinet->longitude !== null && ! $this->endpointTouches($endpoints, (float) $cabinet->latitude, (float) $cabinet->longitude)) {
                        $items[] = $this->validationItem('error', "{$route->name} ne završava na dodijeljenom ODO {$cabinet->name}.", 'route', $route->id, 'Ponovo rutiraj drop do tačne koordinate ODO-a.');
                    }
                }
            }
        }

        return $items ?: [$this->validationItem('ok', 'Projekat nema otvorenih FTTH upozorenja.', 'project', $project->id, 'Nastavi sa projektovanjem.')];
    }

    private function endpointTouches(array $endpoints, float $lat, float $lng, float $toleranceMeters = 1.5): bool
    {
        return collect($endpoints)->contains(fn (array $point) => $this->geometry->distanceMeters(
            (float) $point[0],
            (float) $point[1],
            $lat,
            $lng,
        ) <= $toleranceMeters);
    }

    private function branchRoutes(Project $project): Collection
    {
        return NetworkBranch::query()
            ->with('route')
            ->where('project_id', $project->id)
            ->where('type', 'secondary')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (NetworkBranch $branch): ?NetworkRoute {
                $route = $branch->route;
                if (! $route || count($route->path ?? []) < 2 || $route->route_type === 'trench') {
                    return null;
                }

                $route->setAttribute('planning_branch_id', $branch->id);
                $route->setAttribute('planning_branch_name', $branch->name);
                $route->setAttribute('planning_branch_code', $branch->code);
                $route->setAttribute('planning_branch_sort_order', $branch->sort_order);

                return $route;
            })
            ->filter()
            ->values();
    }

    private function nearestBranch(float $lat, float $lng, Collection $routes): ?array
    {
        return $routes
            ->map(fn (NetworkRoute $route) => ['route' => $route] + $this->geometry->projectPointToRoute($lat, $lng, $route))
            ->sortBy('distance_m')
            ->first();
    }

    private function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return $this->geometry->distanceMeters($lat1, $lng1, $lat2, $lng2);
    }

    private function splitterCount(int $houseCount): int
    {
        return $this->materials->splitterCount($houseCount);
    }

    private function routeOccupancy(NetworkRoute $route, array $housesPerCabinet = []): array
    {
        return $this->materials->routeOccupancy($route, $housesPerCabinet);
    }

    private function validationItem(string $level, string $message, string $type, int $id, string $recommendation): array
    {
        return [
            'level' => $level,
            'message' => $message,
            'element_type' => $type,
            'element_id' => $id,
            'recommendation' => $recommendation,
        ];
    }
}
