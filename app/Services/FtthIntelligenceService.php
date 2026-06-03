<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FtthIntelligenceService
{
    public function previewOdoPlan(Project $project, array $parameters = []): array
    {
        $params = $this->planningParameters($parameters);
        $housesWithCoordinates = $project->houses()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('latitude')
            ->orderBy('longitude')
            ->get();
        $housesWithoutCoordinates = $project->houses()
            ->where(fn ($query) => $query->whereNull('latitude')->orWhereNull('longitude'))
            ->orderBy('label')
            ->get();

        if ($housesWithCoordinates->isEmpty()) {
            throw new InvalidArgumentException('Nema kuća sa koordinatama za automatsko planiranje.');
        }

        $odfs = $project->odfs()->whereNotNull('latitude')->whereNotNull('longitude')->get();
        $groups = $this->groupHouses($housesWithCoordinates, $params);
        $cabinets = [];
        $warnings = [];

        if ($odfs->isEmpty()) {
            $warnings[] = [
                'level' => 'warning',
                'message' => 'Projekat nema ODF, predloženi ODO ormarići nisu povezani na ODF.',
                'element_type' => 'project',
                'element_id' => $project->id,
                'recommendation' => 'Dodaj barem jedan ODF sa koordinatama prije potvrde plana.',
            ];
        }

        foreach ($groups as $index => $group) {
            $medoid = $this->medoid($group);
            $centroid = $this->centroid($group);
            $nearestOdf = $this->nearestOdf($medoid, $odfs);
            $distances = $group->map(fn (House $house) => $this->distanceMeters($medoid['lat'], $medoid['lng'], (float) $house->latitude, (float) $house->longitude));
            $houseCount = $group->count();
            $splitterCount = $this->splitterCount($houseCount);
            $utilization = round(($houseCount / 12) * 100, 1);
            $cabinetWarnings = [];
            $maxDistance = (int) round($distances->max() ?? 0);

            if ($maxDistance > $params['max_distance_m']) {
                $cabinetWarnings[] = 'Najudaljenija kuća je dalje od '.$params['max_distance_m'].' m.';
            }
            if ($utilization < 50) {
                $cabinetWarnings[] = 'Iskorištenost ODO ormarića je ispod 50%.';
            }

            $cabinets[] = [
                'name' => $this->plannedCabinetName($project, $index + 1),
                'house_count' => $houseCount,
                'splitter_count' => $splitterCount,
                'utilization' => $utilization,
                'proposed_latitude' => $medoid['lat'],
                'proposed_longitude' => $medoid['lng'],
                'medoid_house_id' => $medoid['house']->id,
                'centroid_lat' => $centroid['lat'],
                'centroid_lng' => $centroid['lng'],
                'average_house_distance_m' => (int) round($distances->avg() ?? 0),
                'max_house_distance_m' => $maxDistance,
                'nearest_odf_id' => $nearestOdf['odf']?->id,
                'nearest_odf_name' => $nearestOdf['odf']?->name,
                'distance_to_odf_m' => $nearestOdf['distance_m'],
                'warnings' => $cabinetWarnings,
                'houses' => $group->map(fn (House $house) => [
                    'id' => $house->id,
                    'label' => $house->label,
                    'address' => $house->address,
                    'latitude' => (float) $house->latitude,
                    'longitude' => (float) $house->longitude,
                    'distance_to_odo_m' => (int) round($this->distanceMeters($medoid['lat'], $medoid['lng'], (float) $house->latitude, (float) $house->longitude)),
                ])->values()->all(),
            ];
        }

        $summary = $this->planSummary($housesWithCoordinates, $housesWithoutCoordinates, collect($cabinets), $warnings);

        return [
            'project' => ['id' => $project->id, 'name' => $project->name, 'code' => $project->code],
            'parameters' => $params,
            'summary' => $summary,
            'warnings' => $warnings,
            'cabinets' => $cabinets,
        ];
    }

    public function confirmOdoPlan(Project $project, array $plan, bool $createDropRoutes = false): array
    {
        if (empty($plan['cabinets']) || ! is_array($plan['cabinets'])) {
            throw new InvalidArgumentException('Plan nema predložene ODO ormariće.');
        }

        $created = 0;
        $linkedHouses = 0;
        $createdRoutes = 0;

        DB::transaction(function () use ($project, $plan, $createDropRoutes, &$created, &$linkedHouses, &$createdRoutes): void {
            foreach ($plan['cabinets'] as $cabinetPlan) {
                $houses = collect($cabinetPlan['houses'] ?? []);
                if ($houses->isEmpty()) {
                    throw new InvalidArgumentException('Predloženi ODO nema kuće.');
                }
                if ($houses->count() > 12) {
                    throw new InvalidArgumentException('ODO ne može imati više od 12 kuća.');
                }

                $houseIds = $houses->pluck('id')->filter()->values();
                $validHouseCount = House::query()
                    ->where('project_id', $project->id)
                    ->whereIn('id', $houseIds)
                    ->count();
                if ($validHouseCount !== $houseIds->count()) {
                    throw new InvalidArgumentException('Plan sadrži kuću iz drugog projekta ili nepostojeću kuću.');
                }

                $odfId = $cabinetPlan['nearest_odf_id'] ?? null;
                if ($odfId && ! Odf::query()->whereKey($odfId)->where('project_id', $project->id)->exists()) {
                    throw new InvalidArgumentException('Plan sadrži ODF iz drugog projekta.');
                }

                $cabinet = Cabinet::create([
                    'project_id' => $project->id,
                    'odf_id' => $odfId,
                    'name' => $cabinetPlan['name'],
                    'address' => 'Auto plan - '.$cabinetPlan['proposed_latitude'].','.$cabinetPlan['proposed_longitude'],
                    'splitter_count' => $this->splitterCount($houses->count()),
                    'ports_per_splitter' => 4,
                    'latitude' => $cabinetPlan['proposed_latitude'],
                    'longitude' => $cabinetPlan['proposed_longitude'],
                ]);
                $created++;

                House::query()->where('project_id', $project->id)->whereIn('id', $houseIds)->update(['cabinet_id' => $cabinet->id]);
                $linkedHouses += $houseIds->count();

                if ($createDropRoutes) {
                    foreach ($houses->values() as $index => $house) {
                        $path = [[(float) $cabinet->latitude, (float) $cabinet->longitude], [(float) $house['latitude'], (float) $house['longitude']]];
                        $length = $this->polylineLength($path);
                        NetworkRoute::create([
                            'project_id' => $project->id,
                            'cabinet_id' => $cabinet->id,
                            'name' => "Drop {$cabinet->name}-".($index + 1),
                            'route_type' => 'drop',
                            'installation_type' => 'underground',
                            'duct_length_m' => $length,
                            'fiber_length_m' => $length,
                            'fiber_count' => 4,
                            'microduct_count' => 1,
                            'microduct_type' => '10/8',
                            'status' => 'planned',
                            'path' => $path,
                        ]);
                        $createdRoutes++;
                    }
                }
            }
        });

        return [
            'message' => "Kreirano {$created} ODO ormarića.",
            'created' => $created,
            'linked_houses' => $linkedHouses,
            'created_routes' => $createdRoutes,
        ];
    }

    public function validateProject(Project $project): array
    {
        $items = [];
        $project->loadMissing([
            'odfs',
            'houses.cabinet',
            'cabinets.odf',
            'cabinets.houses',
            'routes',
        ]);

        if ($project->odfs->isEmpty()) {
            $items[] = $this->validationItem('warning', 'Projekat nema ODF.', 'project', $project->id, 'Dodaj ODF prije potvrde mrežnog plana.');
        }
        if ($project->cabinets->isEmpty()) {
            $items[] = $this->validationItem('info', 'Projekat nema ODO ormariće.', 'project', $project->id, 'Pokreni automatsko planiranje ODO ormarića.');
        }
        if ($project->houses->isEmpty()) {
            $items[] = $this->validationItem('info', 'Projekat nema kuće.', 'project', $project->id, 'Dodaj kuće iz mape ili liste.');
        }

        foreach ($project->houses as $house) {
            if (! $house->cabinet_id) {
                $items[] = $this->validationItem('warning', "{$house->label} nema povezan ODO.", 'house', $house->id, 'Dodijeli kuću ODO ormariću.');
            }
            if ($house->cabinet && $house->latitude && $house->longitude && $house->cabinet->latitude && $house->cabinet->longitude) {
                $distance = $this->distanceMeters((float) $house->latitude, (float) $house->longitude, (float) $house->cabinet->latitude, (float) $house->cabinet->longitude);
                if ($distance > 120) {
                    $items[] = $this->validationItem('warning', "{$house->label} je udaljena više od 120 m od ODO.", 'house', $house->id, 'Razmotri novi ODO ili drugačije grupisanje.');
                }
            }
        }

        foreach ($project->cabinets as $cabinet) {
            $houseCount = $cabinet->houses->count();
            $neededSplitters = $this->splitterCount($houseCount);
            $utilization = $houseCount / 12;
            if (! $cabinet->odf_id) {
                $items[] = $this->validationItem('warning', "{$cabinet->name} nema povezan ODF.", 'cabinet', $cabinet->id, 'Poveži ODO sa najbližim ODF-om.');
            }
            if ($houseCount > 12) {
                $items[] = $this->validationItem('error', "{$cabinet->name} ima više od 12 kuća.", 'cabinet', $cabinet->id, 'Rastereti ODO ili kreiraj dodatni ODO.');
            }
            if ($cabinet->splitter_count < $neededSplitters) {
                $items[] = $this->validationItem('error', "{$cabinet->name} nema dovoljno splittera.", 'cabinet', $cabinet->id, "Postavi {$neededSplitters} splittera.");
            }
            if ($cabinet->splitter_count > 3) {
                $items[] = $this->validationItem('error', "{$cabinet->name} ima više od 3 splittera.", 'cabinet', $cabinet->id, 'Maksimalan broj splittera je 3.');
            }
            if ($utilization > 0.9) {
                $items[] = $this->validationItem('warning', "{$cabinet->name} je popunjen preko 90%.", 'cabinet', $cabinet->id, 'Planiraj rezervni kapacitet.');
            }
            if ($houseCount > 0 && $utilization < 0.5) {
                $items[] = $this->validationItem('info', "{$cabinet->name} je iskorišten ispod 50%.", 'cabinet', $cabinet->id, 'Provjeri da li se može spojiti sa susjednom grupom.');
            }
        }

        foreach ($project->routes as $route) {
            if (! $route->microduct_type) {
                $items[] = $this->validationItem('warning', "{$route->name} nema mikrocijev.", 'route', $route->id, 'Unesi profil mikrocijevi.');
            }
            if (! $route->fiber_count) {
                $items[] = $this->validationItem('warning', "{$route->name} nema kabal.", 'route', $route->id, 'Unesi broj niti kabla.');
            }
            if (! $route->path) {
                $items[] = $this->validationItem('warning', "{$route->name} nema geometriju.", 'route', $route->id, 'Uredi geometriju trase na mapi.');
            } elseif (count($route->path) < 2) {
                $items[] = $this->validationItem('error', "{$route->name} ima manje od dvije tačke.", 'route', $route->id, 'Dodaj najmanje dvije tačke trase.');
            }
        }

        if (! $items) {
            $items[] = $this->validationItem('ok', 'Projekat nema otvorenih FTTH upozorenja.', 'project', $project->id, 'Nastavi sa projektovanjem.');
        }

        return $items;
    }

    public function materialSummary(Project $project): array
    {
        $routes = $project->routes;
        $summary = [
            'odf_count' => $project->odfs()->count(),
            'odo_count' => $project->cabinets()->count(),
            'house_count' => $project->houses()->count(),
            'subscriber_count' => $project->subscribers()->count(),
            'splitter_count' => $project->cabinets()->sum('splitter_count'),
            'route_length_m' => $routes->sum('duct_length_m'),
            'microduct_14_10_m' => $routes->where('microduct_type', '14/10')->sum(fn (NetworkRoute $route) => $route->duct_length_m * $route->microduct_count),
            'microduct_10_8_m' => $routes->where('microduct_type', '10/8')->sum(fn (NetworkRoute $route) => $route->duct_length_m * $route->microduct_count),
            'fiber_4_m' => $routes->where('fiber_count', 4)->sum('fiber_length_m'),
            'fiber_12_m' => $routes->where('fiber_count', 12)->sum('fiber_length_m'),
            'fiber_24_m' => $routes->where('fiber_count', 24)->sum('fiber_length_m'),
            'fiber_48_m' => $routes->where('fiber_count', 48)->sum('fiber_length_m'),
            'unclassified_routes' => $routes->filter(fn (NetworkRoute $route) => ! $route->microduct_type || ! $route->fiber_count)->count(),
        ];
        $summary['estimated_value'] = (float) $project->materials()->selectRaw('SUM(planned_quantity * unit_price) as total')->value('total') ?: 0.0;

        return $summary;
    }

    public function splitterCount(int $houseCount): int
    {
        return max(1, min(3, (int) ceil($houseCount / 4)));
    }

    public function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371000;
        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);
        $a = sin($latDelta / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lngDelta / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function planningParameters(array $parameters): array
    {
        return [
            'max_houses_per_odo' => min(12, max(1, (int) ($parameters['max_houses_per_odo'] ?? 12))),
            'max_distance_m' => max(20, (int) ($parameters['max_distance_m'] ?? 120)),
            'preferred_fill_min' => min(12, max(1, (int) ($parameters['preferred_fill_min'] ?? 8))),
            'create_drop_routes' => filter_var($parameters['create_drop_routes'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }

    private function groupHouses(Collection $houses, array $params): array
    {
        $unassigned = $houses->values();
        $groups = [];

        while ($unassigned->isNotEmpty()) {
            $group = collect([$unassigned->shift()]);

            while ($group->count() < $params['max_houses_per_odo'] && $unassigned->isNotEmpty()) {
                $medoid = $this->medoid($group);
                $nearest = $unassigned
                    ->map(fn (House $house, int $index) => [
                        'index' => $index,
                        'house' => $house,
                        'distance' => $this->distanceMeters($medoid['lat'], $medoid['lng'], (float) $house->latitude, (float) $house->longitude),
                    ])
                    ->sortBy('distance')
                    ->first();

                if (! $nearest || $nearest['distance'] > $params['max_distance_m']) {
                    break;
                }

                $group->push($nearest['house']);
                $unassigned->forget($nearest['index']);
                $unassigned = $unassigned->values();
            }

            $groups[] = $group;
        }

        return $groups;
    }

    private function medoid(Collection $houses): array
    {
        $best = null;
        foreach ($houses as $candidate) {
            $total = $houses->sum(fn (House $house) => $this->distanceMeters((float) $candidate->latitude, (float) $candidate->longitude, (float) $house->latitude, (float) $house->longitude));
            if (! $best || $total < $best['total']) {
                $best = ['house' => $candidate, 'lat' => (float) $candidate->latitude, 'lng' => (float) $candidate->longitude, 'total' => $total];
            }
        }

        return $best;
    }

    private function centroid(Collection $houses): array
    {
        return [
            'lat' => round((float) $houses->avg(fn (House $house) => (float) $house->latitude), 7),
            'lng' => round((float) $houses->avg(fn (House $house) => (float) $house->longitude), 7),
        ];
    }

    private function nearestOdf(array $medoid, Collection $odfs): array
    {
        if ($odfs->isEmpty()) {
            return ['odf' => null, 'distance_m' => null];
        }

        $nearest = $odfs
            ->map(fn (Odf $odf) => [
                'odf' => $odf,
                'distance_m' => (int) round($this->distanceMeters($medoid['lat'], $medoid['lng'], (float) $odf->latitude, (float) $odf->longitude)),
            ])
            ->sortBy('distance_m')
            ->first();

        return $nearest;
    }

    private function planSummary(Collection $housesWithCoordinates, Collection $housesWithoutCoordinates, Collection $cabinets, array $warnings): array
    {
        $totalDrop = $cabinets->sum(fn (array $cabinet) => collect($cabinet['houses'])->sum('distance_to_odo_m'));
        $averageDistance = (int) round($cabinets->avg('average_house_distance_m') ?? 0);
        $maxDistance = (int) ($cabinets->max('max_house_distance_m') ?? 0);
        $averageUtilization = round((float) ($cabinets->avg('utilization') ?? 0), 1);
        $cabinetWarnings = $cabinets->sum(fn (array $cabinet) => count($cabinet['warnings']));
        $warningCount = count($warnings) + $cabinetWarnings;

        return [
            'houses_with_coordinates' => $housesWithCoordinates->count(),
            'houses_without_coordinates' => $housesWithoutCoordinates->count(),
            'proposed_odo_count' => $cabinets->count(),
            'splitter_count' => $cabinets->sum('splitter_count'),
            'average_house_distance_m' => $averageDistance,
            'max_house_distance_m' => $maxDistance,
            'estimated_drop_length_m' => (int) round($totalDrop),
            'average_utilization' => $averageUtilization,
            'warning_count' => $warningCount,
            'score' => $this->planScore($averageDistance, $averageUtilization, $warningCount, $cabinets->count(), $housesWithCoordinates->count()),
            'houses_without_coordinates_list' => $housesWithoutCoordinates->map(fn (House $house) => ['id' => $house->id, 'label' => $house->label])->values()->all(),
        ];
    }

    private function planScore(int $averageDistance, float $averageUtilization, int $warningCount, int $cabinetCount, int $houseCount): int
    {
        $distanceScore = $averageDistance <= 60 ? 100 : ($averageDistance <= 120 ? max(40, 100 - (($averageDistance - 60) / 60) * 60) : max(0, 40 - (($averageDistance - 120) / 120) * 40));
        $utilizationScore = $averageUtilization >= 70 ? 100 : max(0, ($averageUtilization / 70) * 100);
        $warningScore = max(0, 100 - ($warningCount * 15));
        $idealCabinets = max(1, (int) ceil($houseCount / 12));
        $cabinetScore = $cabinetCount <= $idealCabinets ? 100 : max(0, 100 - (($cabinetCount - $idealCabinets) * 20));

        return (int) round(($distanceScore * 0.4) + ($utilizationScore * 0.25) + ($warningScore * 0.2) + ($cabinetScore * 0.15));
    }

    private function plannedCabinetName(Project $project, int $index): string
    {
        $code = Str::upper(Str::slug($project->code ?: $project->name, ''));
        $code = $code ?: 'PR';

        return 'ODO-'.$code.'-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT);
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

    private function polylineLength(array $points): int
    {
        $distance = 0.0;
        for ($i = 1; $i < count($points); $i++) {
            [$lat1, $lng1] = $points[$i - 1];
            [$lat2, $lng2] = $points[$i];
            $distance += $this->distanceMeters((float) $lat1, (float) $lng1, (float) $lat2, (float) $lng2);
        }

        return (int) round($distance);
    }
}
