<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesFtthData;
use App\Models\Cabinet;
use App\Models\House;
use App\Models\Material;
use App\Models\NetworkRoute;
use App\Models\Project;
use App\Models\ProjectAppendixItem;
use App\Services\FiberPlanService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class ReportController extends Controller
{
    use ManagesFtthData;

    public function reports(): View
    {
        $projects = Project::withCount(['odfs', 'cabinets', 'houses', 'routes'])
            ->with([
                'materials',
                'routes',
                'appendixItems',
                'odfs.cabinets',
                'houses.cabinet',
                'cabinets' => fn ($q) => $q->withCount('houses')->with(['odf', 'houses']),
            ])
            ->orderBy('name')
            ->get();

        return view('ftth.reports', [
            'projects' => $projects,
            'projectInsights' => $projects->mapWithKeys(fn (Project $project) => [$project->id => [
                'validation' => $this->projectValidation->validateProject($project),
                'materials' => $this->projectMaterials->summary($project),
            ]]),
            'totals' => [
                'projects' => Project::count(),
                'houses' => House::count(),
                'cabinets' => Cabinet::count(),
                'free_ports' => (int) Cabinet::selectRaw('SUM(MAX((splitter_count * ports_per_splitter) - (SELECT COUNT(*) FROM houses WHERE cabinet_id = cabinets.id), 0)) as total')->value('total'),
                'duct' => NetworkRoute::where('route_type', '!=', 'trench')->sum('duct_length_m'),
                'fiber' => NetworkRoute::where('route_type', '!=', 'trench')->sum('fiber_length_m'),
                'materials_cost' => Material::query()->selectRaw('SUM(planned_quantity * unit_price) as total')->value('total') ?? 0,
            ],
        ]);
    }

    public function projectAppendix(Project $project): View
    {
        $project->load([
            'odfs',
            'cabinets.houses',
            'houses',
            'routes',
            'materials',
            'appendixItems',
        ]);

        $routes = $project->routes;
        $subscriberCount = $project->houses->count();
        $distributionRoutes = $routes->whereIn('route_type', ['distribution', 'secondary']);
        $dropRoutes = $routes->where('route_type', 'drop');
        $totalDuct = (float) $routes
            ->sum(fn (NetworkRoute $route) => $route->trenchLengthForReport());
        $totalFiber = (float) $routes->sum('fiber_length_m');
        $materialRoutes = $routes->where('route_type', '!=', 'trench');
        $microduct1410 = (float) $materialRoutes->where('microduct_type', '14/10')->sum(fn (NetworkRoute $route) => $route->duct_length_m * max((int) $route->microduct_count, 1));
        $microduct107 = (float) $materialRoutes->whereIn('microduct_type', ['10/7', '10/8'])->sum(fn (NetworkRoute $route) => $route->duct_length_m * max((int) $route->microduct_count, 1));
        $fiberByCount = fn (int $count) => (float) $routes->where('fiber_count', $count)->sum('fiber_length_m');
        $splitter14 = (int) $project->cabinets->sum('splitter_count');
        $userInstall = $subscriberCount * 50;
        $userFiber = $userInstall * 1.1;
        $appendixQuantity = fn (string $type) => (float) $project->appendixItems->where('type', $type)->sum('quantity');
        $boringFi130Length = (float) $project->appendixItems
            ->where('type', 'boring_fi_130')
            ->sum(fn (ProjectAppendixItem $item) => $item->length_m ?? $item->quantity);

        $segments = [
            ['label' => 'ODF ormari na betonskoj stopi a*b*h / 40*80*100', 'quantity' => $project->odfs->count(), 'unit' => 'KOMADA', 'strong' => true],
            ['label' => 'FTTH ORMARI na betonskoj stopi fi=30 cm i l=100 cm', 'quantity' => $project->cabinets->count(), 'unit' => 'KOMADA', 'strong' => true],
            ['label' => 'SPLITERI 1/16', 'quantity' => 0, 'unit' => 'KOMADA', 'strong' => true],
            ['label' => 'SPLITERI 1/4 (FTTH*3)', 'quantity' => $splitter14, 'unit' => 'KOMADA', 'strong' => true],
            ['label' => 'PROLAZNI SAHTOVI', 'quantity' => $appendixQuantity('manhole'), 'unit' => 'KOMADA', 'strong' => true],
            ['label' => 'BUSENJE PNEUMATSKOM RAKETOM fi 130 ispod ceste', 'quantity' => $boringFi130Length, 'unit' => 'metara', 'strong' => true],
            ['label' => 'BUSENJE PNEUMATSKOM RAKETOM FI 75 ispod ceste', 'quantity' => 0, 'unit' => 'KOMADA', 'strong' => true],
        ];

        $recap = [
            ['label' => 'GLAVNI ROV', 'quantity' => $totalDuct, 'unit' => 'metara'],
            ['label' => 'ALKATEN PEHD DN 50', 'quantity' => 0, 'unit' => 'metara'],
            ['label' => 'MIKRODUKT 4 phi 10', 'quantity' => $microduct107, 'unit' => 'metara'],
            ['label' => 'MIKRO CIJEV HDPE phi 14/10', 'quantity' => $microduct1410, 'unit' => 'metara'],
            ['label' => 'OPTIKA TOSM 03 48 niti', 'quantity' => $fiberByCount(48), 'unit' => 'metara'],
            ['label' => 'OPTIKA TOSM 03 24 niti', 'quantity' => $fiberByCount(24), 'unit' => 'metara'],
            ['label' => 'OPTIKA TOSM 03 12 niti', 'quantity' => $fiberByCount(12), 'unit' => 'metara'],
            ['label' => 'OPTIKA TOSM 03 6 niti', 'quantity' => $fiberByCount(6), 'unit' => 'metara'],
            ['label' => 'OPTIKA TOSM 03 4 niti', 'quantity' => $fiberByCount(4), 'unit' => 'metara'],
            ['label' => 'PVC CIJEV fi 110', 'quantity' => $boringFi130Length, 'unit' => 'metara'],
            ['label' => 'PVC CIJEV fi 75', 'quantity' => 0, 'unit' => 'metara'],
            ['label' => 'KORISNICKE INSTALACIJE MIKRO CIJEV HDPE phi 10/7', 'quantity' => $userInstall, 'unit' => 'metara'],
            ['label' => 'KORISNICKA OPTIKA FTTH Fiber Optic 2 niti', 'quantity' => $userFiber, 'unit' => 'metara'],
            ['label' => 'KORISNICKI ROV K.MC * 1/2', 'quantity' => $userInstall / 2, 'unit' => 'metara', 'prefix' => 'cca '],
        ];

        return view('ftth.reports.prilog-3', [
            'project' => $project,
            'segments' => $segments,
            'recap' => $recap,
            'subscriberCount' => $subscriberCount,
            'userInstall' => $userInstall,
            'userFiber' => $userFiber,
            'distributionLength' => $distributionRoutes->sum('duct_length_m'),
            'dropLength' => $dropRoutes->sum('duct_length_m'),
            'totalFiber' => $totalFiber,
        ]);
    }

    public function storeAppendixItem(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:manhole,boring_fi_130'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'max:255'],
        ]);

        $unit = $data['type'] === 'boring_fi_130' ? 'metara' : 'KOMADA';

        ProjectAppendixItem::create([
            'project_id' => $project->id,
            'type' => $data['type'],
            'quantity' => $data['quantity'],
            'unit' => $unit,
            'note' => $data['note'] ?? null,
        ]);

        return back()->with('success', 'Stavka Priloga 3 je dodana.');
    }

    public function deleteAppendixItem(ProjectAppendixItem $item): RedirectResponse
    {
        $item->delete();

        return back()->with('success', 'Stavka Priloga 3 je obrisana.');
    }

    public function splitters(): View
    {
        $cabinets = Cabinet::with(['project', 'odf'])->withCount(['houses'])->orderBy('name')->get();

        return view('ftth.splitters', ['cabinets' => $cabinets]);
    }

    public function fiberSchemaPdf(Project $project, FiberPlanService $fiberPlanService): Response
    {
        $project->load([
            'houses' => fn ($q) => $q->whereNull('cabinet_id')->orderBy('label'),
            'branches' => fn ($q) => $q->with([
                'route',
                'cabinets' => fn ($cq) => $cq->withCount(['houses'])->with([
                    'houses' => fn ($hq) => $hq->orderBy('label'),
                    'childCabinets' => fn ($ccq) => $ccq->withCount(['houses'])->with(['houses' => fn ($hq) => $hq->orderBy('label')])->orderBy('name'),
                ]),
                'childBranches' => fn ($cq) => $cq->with([
                    'route',
                    'cabinets' => fn ($ccq) => $ccq->withCount(['houses'])->with([
                        'houses' => fn ($hq) => $hq->orderBy('label'),
                        'childCabinets' => fn ($cccq) => $cccq->withCount(['houses'])->with(['houses' => fn ($hq) => $hq->orderBy('label')])->orderBy('name'),
                    ]),
                ]),
            ])->orderBy('sort_order'),
            'odfs.cabinets' => fn ($q) => $q
                ->whereNull('parent_cabinet_id')
                ->with([
                    'houses' => fn ($hq) => $hq->orderBy('label'),
                    'childCabinets' => fn ($cq) => $cq->with(['houses' => fn ($hq) => $hq->orderBy('label')])->withCount(['houses'])->orderBy('name'),
                ])
                ->withCount(['houses'])
                ->orderBy('name'),
        ]);

        $cabinets = $project->odfs->flatMap->cabinets;
        $childCabinets = $cabinets->flatMap->childCabinets;
        $allCabinets = $cabinets->merge($childCabinets);
        $totalHouses = $allCabinets->sum('houses_count');
        $totalCapacity = max($allCabinets->sum(fn ($c) => max($c->capacity, 12)), 1);
        $projectUtilization = min(100, round($totalHouses / $totalCapacity * 100));

        $neededSplitters = function ($cabinet): int {
            $houseCount = $cabinet->houses_count ?? ($cabinet->relationLoaded('houses') ? $cabinet->houses->count() : $cabinet->houses()->count());

            return (int) ceil(((int) $houseCount) / max(1, (int) $cabinet->ports_per_splitter));
        };

        $fiberAllocations = [];
        $nextFiber = 1;
        $fibersPerTube = str_ends_with($project->fiber_layout ?? '6x24', 'x12') ? 12 : 24;
        $reservePerTube = min((int) ($project->fiber_reserve_per_tube ?? 0), $fibersPerTube - 1);
        $allocateFibers = function (int $count) use (&$nextFiber, $fibersPerTube, $reservePerTube): array {
            $position = (($nextFiber - 1) % $fibersPerTube) + 1;
            $usable = $fibersPerTube - $reservePerTube;
            if ($position > $usable || $position + $count - 1 > $usable) {
                $nextFiber += $fibersPerTube - $position + 1;
            }
            $allocation = ['from' => $nextFiber, 'to' => $nextFiber + $count - 1, 'count' => $count];
            $nextFiber += $count;

            return $allocation;
        };
        $project->odfs->sortBy(fn ($odf) => (string) $odf->name)->each(function ($odf) use ($project, &$fiberAllocations, &$nextFiber, $neededSplitters, $allocateFibers) {
            $project->branches
                ->where('type', 'secondary')
                ->where('odf_id', $odf->id)
                ->sortBy(fn ($branch) => sprintf('%06d|%s', (int) ($branch->sort_order ?? 0), (string) $branch->name))
                ->each(function ($branch) use (&$fiberAllocations, &$nextFiber, $neededSplitters, $allocateFibers) {
                    $branch->cabinets
                        ->sortBy(fn ($cabinet) => sprintf('%06d|%s', (int) ($cabinet->branch_order ?? 0), (string) $cabinet->name))
                        ->each(function ($cabinet) use (&$fiberAllocations, &$nextFiber, $neededSplitters, $allocateFibers) {
                            $fiberCount = $neededSplitters($cabinet);
                            if ($fiberCount > 0) {
                                $fiberAllocations[$cabinet->id] = $allocateFibers($fiberCount);
                            }
                            $cabinet->childCabinets->whereNull('branch_id')
                                ->sortBy(fn ($child) => sprintf('%06d|%s', (int) ($child->branch_order ?? 0), (string) $child->name))
                                ->each(function ($child) use (&$fiberAllocations, &$nextFiber, $neededSplitters, $allocateFibers) {
                                    $childFiberCount = $neededSplitters($child);
                                    if ($childFiberCount > 0) {
                                        $fiberAllocations[$child->id] = $allocateFibers($childFiberCount);
                                    }
                                });
                        });
                });
        });

        $usedFiberTo = collect($fiberAllocations)->max('to') ?? 0;
        $odfCapacity = $project->odfs->max('fiber_capacity') ?? 144;
        $reserveFrom = $usedFiberTo + 1;
        $reserveTo = max($odfCapacity, $usedFiberTo + 1);
        $fiberPlan = $fiberPlanService->build($project);

        $pdf = Pdf::loadView('ftth.fiber-schema-pdf', compact(
            'project', 'allCabinets', 'totalHouses', 'totalCapacity',
            'projectUtilization', 'fiberAllocations', 'neededSplitters',
            'usedFiberTo', 'reserveFrom', 'reserveTo', 'fiberPlan',
        ))->setPaper('a4', 'landscape');

        $filename = 'fiber-shema-'.str($project->code ?: $project->name)->slug().'-'.now()->format('Ymd').'.pdf';

        return $pdf->download($filename);
    }

    public function fiberSchema(): View
    {
        $projects = Project::with([
            'houses' => fn ($query) => $query->whereNull('cabinet_id')->orderBy('label'),
            'branches' => fn ($query) => $query->with([
                'route',
                'cabinets' => fn ($q) => $q->withCount(['houses'])->with([
                    'houses' => fn ($hq) => $hq->orderBy('label'),
                    'childCabinets' => fn ($cq) => $cq->withCount(['houses'])->with(['houses' => fn ($hq) => $hq->orderBy('label')])->orderBy('name'),
                ]),
                'childBranches' => fn ($q) => $q->with([
                    'route',
                    'cabinets' => fn ($cq) => $cq->withCount(['houses'])->with([
                        'houses' => fn ($hq) => $hq->orderBy('label'),
                        'childCabinets' => fn ($ccq) => $ccq->withCount(['houses'])->with(['houses' => fn ($hq) => $hq->orderBy('label')])->orderBy('name'),
                    ]),
                ]),
            ])->orderBy('sort_order'),
            'odfs.cabinets' => fn ($query) => $query
                ->whereNull('parent_cabinet_id')
                ->with([
                    'houses' => fn ($houseQuery) => $houseQuery->orderBy('label'),
                    'childCabinets' => fn ($childQuery) => $childQuery->with(['houses' => fn ($houseQuery) => $houseQuery->orderBy('label')])->withCount(['houses'])->orderBy('name'),
                ])
                ->withCount(['houses'])
                ->orderBy('name'),
            'routes' => fn ($query) => $query->orderBy('route_type')->orderBy('name'),
        ])->orderBy('name')->get();

        return view('ftth.fiber-schema', ['projects' => $projects]);
    }
}
