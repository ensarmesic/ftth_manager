<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FtthIntelligenceService
{
    private const BRANCH_WARNING = 'Nema definisanih krakova. Planiranje koristi samo geografsku blizinu i rezultat moze biti netacan.';

    public function __construct(private readonly GeometryService $geometry) {}

    public function previewOdoPlan(Project $project, array $parameters = []): array
    {
        $params = $this->planningParameters($parameters);
        $housesWithCoordinates = $project->houses()
            ->whereNull('cabinet_id')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('label')
            ->get();
        $housesWithoutCoordinates = $project->houses()
            ->whereNull('cabinet_id')
            ->where(fn ($query) => $query->whereNull('latitude')->orWhereNull('longitude'))
            ->orderBy('label')
            ->get();

        if ($housesWithCoordinates->isEmpty()) {
            throw new InvalidArgumentException('Nema novih nepovezanih kuca sa koordinatama za automatsko planiranje.');
        }

        $odfs = $project->odfs()->whereNotNull('latitude')->whereNotNull('longitude')->get();
        $branchRoutes = $this->branchRoutes($project);
        $assignments = $branchRoutes->isEmpty()
            ? $this->fallbackAssignments($housesWithCoordinates, $params)
            : $this->assignHousesToBranches($housesWithCoordinates, $branchRoutes, $params);

        $warnings = [];
        if ($branchRoutes->isEmpty()) {
            $warnings[] = $this->validationItem('warning', self::BRANCH_WARNING, 'project', $project->id, 'Nacrtaj sekundarne trase prije preciznog Auto ODO planiranja.');
        }
        if ($odfs->isEmpty()) {
            $warnings[] = $this->validationItem('warning', 'Projekat nema ODF, predlozeni ODO ormarici nisu povezani na ODF.', 'project', $project->id, 'Dodaj barem jedan ODF sa koordinatama prije potvrde plana.');
        }

        $cabinets = [];
        $branchSummaries = [];
        foreach ($assignments['branches'] as $branchKey => $branch) {
            [$existingCabinetPlans, $remainingHouses] = $this->fillExistingCabinets($project, $branch['houses'], $branch, $odfs, $params);
            $groups = $this->groupsForBranch($remainingHouses, $branch, $params);
            $branchCabinets = [];
            foreach ($existingCabinetPlans as $cabinet) {
                $cabinets[] = $cabinet;
                $branchCabinets[] = $cabinet;
            }
            foreach ($groups as $groupIndex => $houses) {
                $cabinet = $this->cabinetPreview($project, $houses, $branch, count($existingCabinetPlans) + $groupIndex + 1, $odfs, $params);
                $cabinets[] = $cabinet;
                $branchCabinets[] = $cabinet;
            }
            $branchSummaries[] = [
                'branch_id' => $branch['branch']?->id,
                'route_id' => $branch['route']?->id,
                'branch_index' => $branch['branch_index'],
                'name' => $branch['name'],
                'house_count' => $branch['houses']->count(),
                'odo_count' => count($branchCabinets),
                'score' => $this->branchScore(collect($branchCabinets), $assignments['unassigned']->count()),
            ];
        }

        foreach ($assignments['unassigned'] as $house) {
            $warnings[] = $this->validationItem('warning', "{$house->label} nije dodijeljena kraku.", 'house', $house->id, 'Provjeri da li je kuca vezana za sekundarni krak.');
        }

        $summary = $this->planSummary($housesWithCoordinates, $housesWithoutCoordinates, collect($cabinets), $warnings, $assignments['unassigned']);

        return [
            'project' => ['id' => $project->id, 'name' => $project->name, 'code' => $project->code],
            'parameters' => $params,
            'summary' => $summary,
            'branches' => $branchSummaries,
            'warnings' => $warnings,
            'unassigned_houses' => $assignments['unassigned']->map(fn (House $house) => $this->housePayload($house))->values()->all(),
            'cabinets' => $cabinets,
        ];
    }

    public function confirmOdoPlan(Project $project, array $plan, bool $createDropRoutes = false): array
    {
        if (empty($plan['cabinets']) || ! is_array($plan['cabinets'])) {
            throw new InvalidArgumentException('Plan nema predlozene ODO ormarice.');
        }

        $created = 0;
        $linkedHouses = 0;
        $createdRoutes = 0;
        $seenHouseIds = [];

        DB::transaction(function () use ($project, $plan, $createDropRoutes, &$created, &$linkedHouses, &$createdRoutes, &$seenHouseIds): void {
            foreach ($plan['cabinets'] as $cabinetPlan) {
                $houses = collect($cabinetPlan['houses'] ?? []);
                if ($houses->isEmpty()) {
                    throw new InvalidArgumentException('Predlozeni ODO nema kuce.');
                }
                if ($houses->count() > 12) {
                    throw new InvalidArgumentException('ODO ne moze imati vise od 12 kuca.');
                }

                $branchIds = $houses->pluck('branch_id')->unique()->values();
                if ($branchIds->count() > 1 || (int) ($branchIds->first() ?? 0) !== (int) ($cabinetPlan['branch_id'] ?? 0)) {
                    throw new InvalidArgumentException('Plan mijesa kuce razlicitih krakova.');
                }

                $houseIds = $houses->pluck('id')->filter()->values();
                if ($houseIds->duplicates()->isNotEmpty() || collect($seenHouseIds)->intersect($houseIds)->isNotEmpty()) {
                    throw new InvalidArgumentException('Kuca moze pripadati samo jednoj ODO grupi.');
                }
                $seenHouseIds = array_merge($seenHouseIds, $houseIds->all());

                $validHouseCount = House::query()->where('project_id', $project->id)->whereIn('id', $houseIds)->count();
                if ($validHouseCount !== $houseIds->count()) {
                    throw new InvalidArgumentException('Plan sadrzi kucu iz drugog projekta ili nepostojecu kucu.');
                }

                $odfId = $cabinetPlan['nearest_odf_id'] ?? null;
                if ($odfId && ! Odf::query()->whereKey($odfId)->where('project_id', $project->id)->exists()) {
                    throw new InvalidArgumentException('Plan sadrzi ODF iz drugog projekta.');
                }

                $cabinetName = $this->confirmedCabinetName((string) $cabinetPlan['name']);
                $assignedCabinetIds = House::query()->where('project_id', $project->id)->whereIn('id', $houseIds)->whereNotNull('cabinet_id')->pluck('cabinet_id')->unique();
                $cabinet = $assignedCabinetIds->count() === 1 ? Cabinet::find($assignedCabinetIds->first()) : null;
                $targetExistingCabinet = ! empty($cabinetPlan['existing_cabinet_id'])
                    ? Cabinet::query()->where('project_id', $project->id)->whereKey($cabinetPlan['existing_cabinet_id'])->first()
                    : null;
                $sameAutoCabinet = $cabinet && ! $cabinet->parent_cabinet_id && $cabinet->name === $cabinetName && str_starts_with((string) $cabinet->address, 'Auto plan - ');
                if ($assignedCabinetIds->isNotEmpty() && ! $sameAutoCabinet) {
                    throw new InvalidArgumentException('Plan sadrzi kucu koja je vec povezana na ODO. Ponovo pokreni Auto ODO.');
                }
                if ($targetExistingCabinet) {
                    if ($targetExistingCabinet->houses()->count() + $houses->count() > 12) {
                        throw new InvalidArgumentException('Postojeci ODO nema dovoljno slobodnih portova.');
                    }
                    $cabinet = $targetExistingCabinet;
                    $cabinet->update([
                        'branch_id' => ! empty($cabinetPlan['branch_id']) && (int) $cabinetPlan['branch_id'] > 0 ? (int) $cabinetPlan['branch_id'] : $cabinet->branch_id,
                        'splitter_count' => $this->splitterCount($cabinet->houses()->count() + $houses->count()),
                    ]);
                } elseif (! $sameAutoCabinet) {
                    $cabinetName = $this->uniqueCabinetName($project, $cabinetName);
                    $branchId = ! empty($cabinetPlan['branch_id']) && (int) $cabinetPlan['branch_id'] > 0 ? (int) $cabinetPlan['branch_id'] : null;
                    $cabinet = Cabinet::create([
                        'project_id' => $project->id,
                        'odf_id' => $odfId,
                        'branch_id' => $branchId,
                        'name' => $cabinetName,
                        'address' => 'Auto plan - '.$cabinetPlan['proposed_latitude'].','.$cabinetPlan['proposed_longitude'],
                        'splitter_count' => $this->splitterCount($houses->count()),
                        'ports_per_splitter' => 4,
                        'latitude' => $cabinetPlan['proposed_latitude'],
                        'longitude' => $cabinetPlan['proposed_longitude'],
                    ]);
                    $created++;
                    if ($odfId && $branchId) {
                        NetworkBranch::where('id', $branchId)->whereNull('odf_id')->update(['odf_id' => $odfId]);
                    }
                }

                $linkedHouses += House::query()
                    ->where('project_id', $project->id)
                    ->whereIn('id', $houseIds)
                    ->where('cabinet_id', '!=', $cabinet->id)
                    ->orWhere(function ($query) use ($project, $houseIds): void {
                        $query->where('project_id', $project->id)
                            ->whereIn('id', $houseIds)
                            ->whereNull('cabinet_id');
                    })
                    ->update(['cabinet_id' => $cabinet->id]);

                if ($createDropRoutes) {
                    if (empty($cabinetPlan['cable_type']) || empty($cabinetPlan['microduct_type'])) {
                        throw new InvalidArgumentException('Drop trase nisu kreirane jer cable_type ili microduct_type nisu sigurni.');
                    }
                    $dropPreviewByHouse = collect($cabinetPlan['drop_preview'] ?? [])->keyBy('house_id');
                    foreach ($houses->values() as $index => $house) {
                        $path = $dropPreviewByHouse->get($house['id'])['path'] ?? [[(float) $cabinet->latitude, (float) $cabinet->longitude], [(float) $house['latitude'], (float) $house['longitude']]];
                        $length = $this->geometry->polylineLength($path);
                        $dropName = $this->uniqueRouteName($project, "Drop {$cabinet->name}-{$house['label']}");
                        NetworkRoute::create([
                            'project_id' => $project->id,
                            'cabinet_id' => $cabinet->id,
                            'from_type' => 'cabinet',
                            'from_id' => $cabinet->id,
                            'to_type' => 'house',
                            'to_id' => $house['id'],
                            'coordinates_json' => $path,
                            'cable_type' => $cabinetPlan['cable_type'],
                            'name' => $dropName,
                            'route_type' => 'drop',
                            'installation_type' => 'underground',
                            'duct_length_m' => $length,
                            'fiber_length_m' => $length,
                            'fiber_count' => 4,
                            'microduct_count' => 1,
                            'microduct_type' => $cabinetPlan['microduct_type'],
                            'status' => 'planned',
                            'path' => $path,
                        ]);
                        $createdRoutes++;
                    }
                }
            }
        });

        return [
            'message' => "Kreirano {$created} ODO ormarica.",
            'created' => $created,
            'linked_houses' => $linkedHouses,
            'created_routes' => $createdRoutes,
        ];
    }

    public function validateProject(Project $project): array
    {
        $items = [];
        $project->loadMissing(['odfs.cabinets', 'houses.cabinet', 'cabinets.odf', 'cabinets.houses', 'routes']);
        $branchRoutes = $this->branchRoutes($project);
        $dropHouseIds = $project->routes
            ->where('route_type', 'drop')
            ->where('to_type', 'house')
            ->keyBy('to_id');

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
            if (! $dropHouseIds->has($house->id)) {
                $items[] = $this->validationItem('warning', "{$house->label} nema drop trasu.", 'house', $house->id, 'Nacrtaj ili automatski kreiraj drop trasu.');
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

    public function materialSummary(Project $project, int $reservePercent = 10): array
    {
        $routes = $project->routes;
        $cableRoutes = $routes->where('route_type', '!=', 'trench');
        $summary = [
            'odf_count' => $project->odfs->count(),
            'odo_count' => $project->cabinets->count(),
            'house_count' => $project->houses->count(),
            'splitter_count' => $project->cabinets->sum('splitter_count'),
            'route_length_m' => $cableRoutes->sum('duct_length_m'),
            'microduct_14_10_m' => $cableRoutes->where('microduct_type', '14/10')->sum(fn (NetworkRoute $route) => $route->duct_length_m * $route->microduct_count),
            'microduct_10_8_m' => $cableRoutes->where('microduct_type', '10/8')->sum(fn (NetworkRoute $route) => $route->duct_length_m * $route->microduct_count),
            'fiber_4_m' => $cableRoutes->where('fiber_count', 4)->sum('fiber_length_m'),
            'fiber_12_m' => $cableRoutes->where('fiber_count', 12)->sum('fiber_length_m'),
            'fiber_24_m' => $cableRoutes->where('fiber_count', 24)->sum('fiber_length_m'),
            'fiber_48_m' => $cableRoutes->where('fiber_count', 48)->sum('fiber_length_m'),
            'unclassified_routes' => $cableRoutes->filter(fn (NetworkRoute $route) => ! $route->hasCompleteMicroductData() || $route->hasIncompleteCableData())->count(),
        ];
        $summary['estimated_value'] = (float) $project->materials()->selectRaw('SUM(planned_quantity * unit_price) as total')->value('total') ?: 0.0;
        $summary['reserve_percent'] = $reservePercent;
        $summary['lengths_with_reserve'] = collect($summary)->filter(fn ($value, $key) => is_numeric($value) && (str_ends_with($key, '_m') || $key === 'route_length_m'))->map(fn ($value) => [
            'base' => $value, 'reserve' => (int) ceil($value * $reservePercent / 100), 'total' => (int) ceil($value * (1 + $reservePercent / 100)),
        ])->all();
        $summary['microduct_by_type'] = $cableRoutes->groupBy('microduct_type')->filter(fn ($group, $type) => filled($type))->map(fn ($group) => $group->sum(fn (NetworkRoute $route) => $route->duct_length_m * $route->microduct_count))->all();
        $summary['fiber_by_count'] = $cableRoutes->groupBy('fiber_count')->filter(fn ($group, $count) => filled($count))->map->sum('fiber_length_m')->all();

        return $summary;
    }

    public function routeOccupancy(NetworkRoute $route, array $housesPerCabinet = []): array
    {
        $capacity = (int) ($route->fiber_count ?? 0);
        $used = $route->route_type === 'drop'
            ? ($route->to_type === 'house' && $route->to_id ? 1 : 0)
            : ($route->cabinet_id
                ? ($housesPerCabinet
                    ? (int) ($housesPerCabinet[$route->cabinet_id] ?? 0)
                    : House::where('cabinet_id', $route->cabinet_id)->count())
                : 0);

        return ['fiber_capacity' => $capacity, 'used_fibers' => $used, 'free_fibers' => max($capacity - $used, 0), 'utilization_percent' => $capacity > 0 ? (int) round($used / $capacity * 100) : 0];
    }

    public function splitterCount(int $houseCount): int
    {
        return max(1, min(3, (int) ceil($houseCount / 4)));
    }

    public function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return $this->geometry->distanceMeters($lat1, $lng1, $lat2, $lng2);
    }

    private function planningParameters(array $parameters): array
    {
        return [
            'max_branch_distance_m' => max(1, (int) ($parameters['max_branch_distance_m'] ?? 120)),
            'max_houses_per_odo' => min(12, max(1, (int) ($parameters['max_houses_per_odo'] ?? 12))),
            'max_house_to_odo_m' => max(20, (int) ($parameters['max_house_to_odo_m'] ?? ($parameters['max_distance_m'] ?? 120))),
            'max_gap_m' => max(10, (int) ($parameters['max_gap_m'] ?? 100)),
            'preferred_fill_min' => min(12, max(1, (int) ($parameters['preferred_fill_min'] ?? 8))),
            'create_drop_routes' => filter_var($parameters['create_drop_routes'] ?? false, FILTER_VALIDATE_BOOL),
        ];
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
                if (! $route || count($route->path ?? []) < 2) {
                    return null;
                }
                // Trench routes are physical infrastructure, not logical branches — skip them
                if ($route->route_type === 'trench') {
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

    private function assignHousesToBranches(Collection $houses, Collection $routes, array $params): array
    {
        $branches = [];
        foreach ($routes->values() as $index => $route) {
            $branchId = (int) $route->getAttribute('planning_branch_id');
            $branches[$branchId] = [
                'branch' => NetworkBranch::find($branchId),
                'route' => $route,
                'name' => $route->getAttribute('planning_branch_name') ?: $route->name,
                'branch_index' => $this->branchIndex($route, $index + 1),
                'houses' => collect(),
            ];
        }
        $unassigned = collect();

        foreach ($houses as $house) {
            $preferredBranchId = (int) ($house->branch_id ?? 0);
            $hasPreferredBranch = $preferredBranchId && isset($branches[$preferredBranchId]);
            $candidateRoutes = $hasPreferredBranch
                ? $routes->filter(fn (NetworkRoute $route) => (int) $route->getAttribute('planning_branch_id') === $preferredBranchId)->values()
                : $routes;
            $nearest = $this->nearestBranch((float) $house->latitude, (float) $house->longitude, $candidateRoutes);
            if (! $nearest || (! $hasPreferredBranch && $nearest['distance_m'] > $params['max_branch_distance_m'])) {
                $unassigned->push($house);

                continue;
            }
            $branchId = (int) $nearest['route']->getAttribute('planning_branch_id');
            $house->setAttribute('branch_id', $branchId);
            $house->setAttribute('route_id', $nearest['route']->id);
            $house->setAttribute('branch_name', $branches[$branchId]['name']);
            $house->setAttribute('branch_index', $branches[$branchId]['branch_index']);
            $house->setAttribute('distance_to_branch_m', (int) round($nearest['distance_m']));
            $house->setAttribute('chainage_m', (int) round($nearest['chainage_m']));
            $branches[$branchId]['houses']->push($house);
        }

        return [
            'branches' => collect($branches)->filter(fn (array $branch) => $branch['houses']->isNotEmpty())->values()->all(),
            'unassigned' => $unassigned,
        ];
    }

    private function fallbackAssignments(Collection $houses, array $params): array
    {
        $groups = $this->fallbackGroups($houses->values(), $params);
        $branches = [];
        foreach ($groups as $index => $group) {
            $group->values()->each(function (House $house, int $houseIndex) use ($index): void {
                $house->setAttribute('branch_id', 0 - ($index + 1));
                $house->setAttribute('branch_name', 'Fallback krak '.($index + 1));
                $house->setAttribute('branch_index', $index + 1);
                $house->setAttribute('distance_to_branch_m', null);
                $house->setAttribute('chainage_m', $houseIndex);
            });
            $branches[] = [
                'route' => null,
                'branch' => null,
                'name' => 'Fallback krak '.($index + 1),
                'branch_index' => $index + 1,
                'houses' => $group,
            ];
        }

        return ['branches' => $branches, 'unassigned' => collect()];
    }

    private function fallbackGroups(Collection $houses, array $params): array
    {
        $houses = $houses->values();
        $clusterCount = max(1, (int) ceil($houses->count() / $params['max_houses_per_odo']));
        if ($clusterCount === 1) {
            return [$houses];
        }

        $ordered = $houses->sortBy(fn (House $house) => (float) $house->longitude)->values();
        $seeds = collect(range(0, $clusterCount - 1))
            ->map(fn (int $index) => $ordered[(int) round(($index / max(1, $clusterCount - 1)) * max(0, $ordered->count() - 1))])
            ->all();
        $groups = array_fill(0, $clusterCount, null);
        for ($i = 0; $i < $clusterCount; $i++) {
            $groups[$i] = collect();
        }

        foreach ($houses as $house) {
            $target = collect($seeds)
                ->map(fn (House $seed, int $index) => [
                    'index' => $index,
                    'distance' => $this->distanceMeters((float) $house->latitude, (float) $house->longitude, (float) $seed->latitude, (float) $seed->longitude),
                ])
                ->sortBy('distance')
                ->first(fn (array $candidate) => $groups[$candidate['index']]->count() < $params['max_houses_per_odo']);
            $groups[$target['index'] ?? 0]->push($house);
        }

        return collect($groups)->filter->isNotEmpty()->map(fn (Collection $group) => $group->values())->values()->all();
    }

    private function groupsForBranch(Collection $houses, array $branch, array $params): array
    {
        $ordered = $houses->sortBy(fn (House $house) => (float) $house->getAttribute('chainage_m'))->values();
        $groups = [];
        $current = collect();
        $previous = null;

        foreach ($ordered as $house) {
            $shouldStart = $current->count() >= $params['max_houses_per_odo'];
            if ($previous && abs((float) $house->getAttribute('chainage_m') - (float) $previous->getAttribute('chainage_m')) > $params['max_gap_m']) {
                $shouldStart = true;
            }
            if (! $shouldStart && $current->isNotEmpty()) {
                $candidate = $current->push($house);
                $odoPoint = $this->odoPointForGroup($candidate, $branch);
                $maxDistance = $candidate->max(fn (House $candidateHouse) => $this->dropPreviewForHouse($candidateHouse, $odoPoint, $branch)['length_m']);
                $current->pop();
                if ($maxDistance > $params['max_house_to_odo_m']) {
                    $shouldStart = true;
                }
            }
            if ($shouldStart && $current->isNotEmpty()) {
                $groups[] = $current->values();
                $current = collect();
            }
            $current->push($house);
            $previous = $house;
        }
        if ($current->isNotEmpty()) {
            $groups[] = $current->values();
        }

        return $groups;
    }

    private function fillExistingCabinets(Project $project, Collection $houses, array $branch, Collection $odfs, array $params): array
    {
        if (! $branch['route'] || $houses->isEmpty()) {
            return [[], $houses];
        }
        $remaining = $houses->sortBy(fn (House $house) => (float) $house->getAttribute('chainage_m'))->values();
        $plans = [];
        $cabinets = $project->cabinets()->withCount('houses')->whereNotNull('latitude')->whereNotNull('longitude')->get()
            ->filter(function (Cabinet $cabinet) use ($branch, $params) {
                if ($cabinet->houses_count >= 12) {
                    return false;
                }
                if ((int) $cabinet->branch_id !== (int) ($branch['branch']?->id ?? 0)) {
                    return false;
                }
                $nearest = $this->nearestBranch((float) $cabinet->latitude, (float) $cabinet->longitude, collect([$branch['route']]));

                return $nearest && $nearest['distance_m'] <= $params['max_branch_distance_m'];
            })
            ->sortByDesc(fn (Cabinet $cabinet) => $this->geometry->projectPointToRoute((float) $cabinet->latitude, (float) $cabinet->longitude, $branch['route'])['chainage_m']);

        foreach ($cabinets as $cabinet) {
            $free = max(12 - $cabinet->houses_count, 0);
            $selected = $remaining->filter(fn (House $house) => $this->distanceMeters((float) $house->latitude, (float) $house->longitude, (float) $cabinet->latitude, (float) $cabinet->longitude) <= $params['max_house_to_odo_m'])
                ->sortBy(fn (House $house) => $this->distanceMeters((float) $house->latitude, (float) $house->longitude, (float) $cabinet->latitude, (float) $cabinet->longitude))
                ->take($free)
                ->values();
            if ($selected->isEmpty()) {
                continue;
            }
            $odoPoint = ['lat' => (float) $cabinet->latitude, 'lng' => (float) $cabinet->longitude, 'medoid' => $selected->first()];
            $plan = $this->cabinetPreview($project, $selected, $branch, 0, $odfs, $params);
            $plan['existing_cabinet_id'] = $cabinet->id;
            $plan['name'] = $cabinet->name;
            $plan['confirmed_name'] = $cabinet->name;
            $plan['proposed_latitude'] = (float) $cabinet->latitude;
            $plan['proposed_longitude'] = (float) $cabinet->longitude;
            $plan['house_count'] = $cabinet->houses_count + $selected->count();
            $plan['splitter_count'] = $this->splitterCount($plan['house_count']);
            $plan['utilization_percent'] = round($plan['house_count'] / 12 * 100, 1);
            $plan['utilization'] = $plan['utilization_percent'];
            $plan['drop_preview'] = $selected->map(fn (House $house) => $this->dropPreviewForHouse($house, $odoPoint, $branch))->values()->all();
            $plan['houses'] = $selected->map(fn (House $house) => $this->housePayload($house, $odoPoint))->values()->all();
            $plans[] = $plan;
            $selectedIds = $selected->pluck('id');
            $remaining = $remaining->reject(fn (House $house) => $selectedIds->contains($house->id))->values();
        }

        return [$plans, $remaining];
    }

    private function cabinetPreview(Project $project, Collection $houses, array $branch, int $groupIndex, Collection $odfs, array $params): array
    {
        $odoPoint = $this->odoPointForGroup($houses, $branch);
        $nearestOdf = $this->nearestOdf($odoPoint, $odfs);
        $distances = $houses->map(fn (House $house) => $this->dropPreviewForHouse($house, $odoPoint, $branch)['length_m']);
        $houseCount = $houses->count();
        $splitterCount = $this->splitterCount($houseCount);
        $utilization = round(($houseCount / 12) * 100, 1);
        $maxDistance = (int) round($distances->max() ?? 0);
        $averageDistance = (int) round($distances->avg() ?? 0);
        $warnings = [];

        if ($maxDistance > $params['max_house_to_odo_m']) {
            $warnings[] = 'Najudaljenija kuca je dalje od '.$params['max_house_to_odo_m'].' m.';
        }
        if ($utilization < (($params['preferred_fill_min'] / 12) * 100)) {
            $warnings[] = 'Iskoristenost ODO ormarica je ispod preferiranog minimuma.';
        }
        if ($nearestOdf['odf'] === null) {
            $warnings[] = 'Nema ODF-a za automatsko povezivanje.';
        }
        if (! $params['create_drop_routes']) {
            $warnings[] = 'Drop trase su samo preview i nece biti snimljene bez create_drop_routes=true.';
        }

        $branchPrefix = $this->branchCabinetPrefix($branch);
        $name = "FTTH {$branchPrefix}-{$groupIndex}";

        return [
            'name' => $name.' PRIJEDLOG',
            'confirmed_name' => $name,
            'branch_id' => $branch['branch']?->id ?? (int) $houses->first()->getAttribute('branch_id'),
            'route_id' => $branch['route']?->id,
            'branch_index' => $branch['branch_index'],
            'branch_name' => $branch['name'],
            'group_index' => $groupIndex,
            'house_count' => $houseCount,
            'splitter_count' => $splitterCount,
            'utilization_percent' => $utilization,
            'utilization' => $utilization,
            'proposed_latitude' => $odoPoint['lat'],
            'proposed_longitude' => $odoPoint['lng'],
            'medoid_house_id' => $odoPoint['medoid']->id,
            'average_distance_m' => $averageDistance,
            'max_distance_m' => $maxDistance,
            'average_house_distance_m' => $averageDistance,
            'max_house_distance_m' => $maxDistance,
            'nearest_odf_id' => $nearestOdf['odf']?->id,
            'nearest_odf_name' => $nearestOdf['odf']?->name,
            'distance_to_odf_m' => $nearestOdf['distance_m'],
            'warnings' => $warnings,
            'score' => $this->odoScore($averageDistance, $maxDistance, $utilization, count($warnings), 0),
            'drop_preview' => $houses->map(fn (House $house) => $this->dropPreviewForHouse($house, $odoPoint, $branch))->values()->all(),
            'houses' => $houses->map(fn (House $house) => $this->housePayload($house, $odoPoint))->values()->all(),
        ];
    }

    private function dropPreviewForHouse(House $house, array $odoPoint, array $branch): array
    {
        $path = [
            [$odoPoint['lat'], $odoPoint['lng']],
            [(float) $house->latitude, (float) $house->longitude],
        ];

        if ($branch['route']) {
            $odoProjection = $this->geometry->projectPointToRoute((float) $odoPoint['lat'], (float) $odoPoint['lng'], $branch['route']);
            $houseProjection = $this->geometry->projectPointToRoute((float) $house->latitude, (float) $house->longitude, $branch['route']);
            $path = $this->geometry->routePathBetween($branch['route'], $odoProjection, $houseProjection);
            $path[] = [(float) $house->latitude, (float) $house->longitude];
            $path = $this->geometry->compactPath($path);
        }

        return [
            'from' => ['lat' => $odoPoint['lat'], 'lng' => $odoPoint['lng']],
            'to' => ['lat' => (float) $house->latitude, 'lng' => (float) $house->longitude],
            'house_id' => $house->id,
            'path' => $path,
            'length_m' => $this->geometry->polylineLength($path),
        ];
    }

    private function housePayload(House $house, ?array $odoPoint = null): array
    {
        return [
            'id' => $house->id,
            'label' => $house->label,
            'address' => $house->address,
            'latitude' => (float) $house->latitude,
            'longitude' => (float) $house->longitude,
            'branch_id' => $house->getAttribute('branch_id'),
            'route_id' => $house->getAttribute('route_id'),
            'branch_index' => $house->getAttribute('branch_index'),
            'branch_name' => $house->getAttribute('branch_name'),
            'distance_to_branch_m' => $house->getAttribute('distance_to_branch_m'),
            'chainage_m' => $house->getAttribute('chainage_m'),
            'distance_to_odo_m' => $odoPoint ? (int) round($this->distanceMeters($odoPoint['lat'], $odoPoint['lng'], (float) $house->latitude, (float) $house->longitude)) : null,
        ];
    }

    private function odoPointForGroup(Collection $houses, array $branch): array
    {
        $medoid = $this->medoid($houses)['house'];
        if (! $branch['route']) {
            return ['lat' => (float) $medoid->latitude, 'lng' => (float) $medoid->longitude, 'medoid' => $medoid];
        }
        $projection = $this->geometry->projectPointToRoute((float) $medoid->latitude, (float) $medoid->longitude, $branch['route']);

        return ['lat' => round($projection['lat'], 7), 'lng' => round($projection['lng'], 7), 'medoid' => $medoid];
    }

    private function nearestBranch(float $lat, float $lng, Collection $routes): ?array
    {
        return $routes
            ->map(fn (NetworkRoute $route) => ['route' => $route] + $this->geometry->projectPointToRoute($lat, $lng, $route))
            ->sortBy('distance_m')
            ->first();
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

    private function nearestOdf(array $point, Collection $odfs): array
    {
        if ($odfs->isEmpty()) {
            return ['odf' => null, 'distance_m' => null];
        }

        return $odfs
            ->map(fn (Odf $odf) => [
                'odf' => $odf,
                'distance_m' => (int) round($this->distanceMeters($point['lat'], $point['lng'], (float) $odf->latitude, (float) $odf->longitude)),
            ])
            ->sortBy('distance_m')
            ->first();
    }

    private function branchIndex(NetworkRoute $route, int $fallback): int
    {
        $label = trim((string) $route->getAttribute('planning_branch_code').' '.(string) $route->getAttribute('planning_branch_name').' '.$route->name);
        if (preg_match('/\d+/', $label, $match)) {
            return (int) $match[0];
        }

        return $fallback;
    }

    private function branchCabinetPrefix(array $branch): string
    {
        $label = trim((string) ($branch['branch']?->code ?? '').' '.(string) ($branch['name'] ?? '').' '.(string) ($branch['route']?->name ?? ''));
        if (preg_match('/(\d+(?:[.-]\d+)*)/', $label, $match)) {
            $code = $match[1];
            if (str_contains($code, '.') && str_contains($code, '-')) {
                $code = preg_replace('/-\d+$/', '', $code);
            }
            $parts = preg_split('/[.-]+/', $code, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($parts) >= 2) {
                $root = $parts[0].'-'.$parts[1];
                $children = array_slice($parts, 2);

                return $children ? $root.'.'.implode('.', $children) : $root;
            }

            return str_replace('.', '-', str_replace('--', '-', str_replace('_', '-', $code)));
        }

        return (string) $branch['branch_index'];
    }

    private function confirmedCabinetName(string $name): string
    {
        return trim(str_replace(' PRIJEDLOG', '', $name));
    }

    private function uniqueCabinetName(Project $project, string $base): string
    {
        if (! Cabinet::where('project_id', $project->id)->where('name', $base)->exists()) {
            return $base;
        }
        for ($suffix = 2; $suffix < 1000; $suffix++) {
            $candidate = "{$base}-{$suffix}";
            if (! Cabinet::where('project_id', $project->id)->where('name', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException('Nije moguće generisati jedinstven naziv novog ODO ormarića.');
    }

    private function uniqueRouteName(Project $project, string $base): string
    {
        $base = trim($base);
        if (! NetworkRoute::where('project_id', $project->id)->where('name', $base)->exists()) {
            return $base;
        }
        for ($suffix = 2; $suffix < 1000; $suffix++) {
            $candidate = "{$base}-{$suffix}";
            if (! NetworkRoute::where('project_id', $project->id)->where('name', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException('Nije moguce generisati jedinstven naziv nove trase.');
    }

    private function planSummary(Collection $housesWithCoordinates, Collection $housesWithoutCoordinates, Collection $cabinets, array $warnings, Collection $unassigned): array
    {
        $totalDrop = $cabinets->sum(fn (array $cabinet) => collect($cabinet['houses'])->sum('distance_to_odo_m'));
        $averageDistance = (int) round($cabinets->avg('average_house_distance_m') ?? 0);
        $maxDistance = (int) ($cabinets->max('max_house_distance_m') ?? 0);
        $averageUtilization = round((float) ($cabinets->avg('utilization_percent') ?? 0), 1);
        $cabinetWarnings = $cabinets->sum(fn (array $cabinet) => count($cabinet['warnings']));
        $warningCount = count($warnings) + $cabinetWarnings;

        return [
            'houses_with_coordinates' => $housesWithCoordinates->count(),
            'houses_without_coordinates' => $housesWithoutCoordinates->count(),
            'unassigned_house_count' => $unassigned->count(),
            'proposed_odo_count' => $cabinets->count(),
            'splitter_count' => $cabinets->sum('splitter_count'),
            'average_house_distance_m' => $averageDistance,
            'max_house_distance_m' => $maxDistance,
            'estimated_drop_length_m' => (int) round($totalDrop),
            'average_utilization' => $averageUtilization,
            'warning_count' => $warningCount,
            'score' => $this->planScore($averageDistance, $averageUtilization, $warningCount, $cabinets->count(), $housesWithCoordinates->count(), $unassigned->count()),
            'houses_without_coordinates_list' => $housesWithoutCoordinates->map(fn (House $house) => ['id' => $house->id, 'label' => $house->label])->values()->all(),
        ];
    }

    private function odoScore(int $averageDistance, int $maxDistance, float $utilization, int $warningCount, int $mixingPenalty): int
    {
        $score = 100;
        $score -= max(0, $averageDistance - 60) * 0.25;
        $score -= max(0, $maxDistance - 120) * 0.5;
        $score -= max(0, 70 - $utilization) * 0.4;
        $score -= $warningCount * 8;
        $score -= $mixingPenalty;

        return max(0, min(100, (int) round($score)));
    }

    private function branchScore(Collection $cabinets, int $unassignedCount): int
    {
        if ($cabinets->isEmpty()) {
            return 0;
        }

        return max(0, (int) round($cabinets->avg('score') - ($unassignedCount * 5)));
    }

    private function planScore(int $averageDistance, float $averageUtilization, int $warningCount, int $cabinetCount, int $houseCount, int $unassignedCount): int
    {
        $distanceScore = $averageDistance <= 60 ? 100 : ($averageDistance <= 120 ? max(40, 100 - (($averageDistance - 60) / 60) * 60) : max(0, 40 - (($averageDistance - 120) / 120) * 40));
        $utilizationScore = $averageUtilization >= 70 ? 100 : max(0, ($averageUtilization / 70) * 100);
        $warningScore = max(0, 100 - ($warningCount * 12) - ($unassignedCount * 18));
        $idealCabinets = max(1, (int) ceil(max(1, $houseCount - $unassignedCount) / 12));
        $cabinetScore = $cabinetCount <= $idealCabinets ? 100 : max(0, 100 - (($cabinetCount - $idealCabinets) * 15));

        return (int) round(($distanceScore * 0.35) + ($utilizationScore * 0.25) + ($warningScore * 0.25) + ($cabinetScore * 0.15));
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
