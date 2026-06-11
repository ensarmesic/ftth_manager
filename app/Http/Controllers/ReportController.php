<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\MapDraft;
use App\Models\Material;
use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Models\ProjectAppendixItem;
use App\Models\Subscriber;
use App\Services\FtthIntelligenceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use App\Http\Controllers\Concerns\ManagesFtthData;

class ReportController extends Controller
{
    use ManagesFtthData;

    public function reports(): View
    {
        $projects = Project::withCount(['odfs', 'cabinets', 'subscribers', 'houses', 'routes'])
            ->with(['materials', 'routes', 'cabinets' => fn ($query) => $query->withCount('subscribers')])
            ->with('appendixItems')
            ->orderBy('name')
            ->get();

        return view('ftth.reports', [
            'projects' => $projects,
            'projectInsights' => $projects->mapWithKeys(fn (Project $project) => [$project->id => [
                'validation' => $this->ftthIntelligence->validateProject($project),
                'materials' => $this->ftthIntelligence->materialSummary($project),
            ]]),
            'totals' => [
                'projects' => Project::count(),
                'subscribers' => Subscriber::count(),
                'houses' => House::count(),
                'cabinets' => Cabinet::count(),
                'free_ports' => Cabinet::withCount('subscribers')->get()->sum(fn (Cabinet $cabinet) => max($cabinet->capacity - $cabinet->subscribers_count, 0)),
                'duct' => NetworkRoute::sum('duct_length_m'),
                'fiber' => NetworkRoute::sum('fiber_length_m'),
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
            'subscribers',
            'routes',
            'materials',
            'appendixItems',
        ]);

        $routes = $project->routes;
        $subscriberCount = max($project->subscribers->count(), $project->houses->count());
        $distributionRoutes = $routes->whereIn('route_type', ['distribution', 'secondary']);
        $dropRoutes = $routes->where('route_type', 'drop');
        $totalDuct = (float) $routes->sum('duct_length_m');
        $totalFiber = (float) $routes->sum('fiber_length_m');
        $microduct1410 = (float) $routes->where('microduct_type', '14/10')->sum(fn (NetworkRoute $route) => $route->duct_length_m * max((int) $route->microduct_count, 1));
        $microduct107 = (float) $routes->whereIn('microduct_type', ['10/7', '10/8'])->sum(fn (NetworkRoute $route) => $route->duct_length_m * max((int) $route->microduct_count, 1));
        $fiberByCount = fn (int $count) => (float) $routes->where('fiber_count', $count)->sum('fiber_length_m');
        $splitter14 = (int) $project->cabinets->sum('splitter_count');
        $userInstall = $subscriberCount * 50;
        $userFiber = $userInstall * 1.1;
        $appendixQuantity = fn (string $type) => (float) $project->appendixItems->where('type', $type)->sum('quantity');

        $segments = [
            ['label' => 'ODF ormari na betonskoj stopi a*b*h / 40*80*100', 'quantity' => $project->odfs->count(), 'unit' => 'KOMADA', 'strong' => true],
            ['label' => 'FTTH ORMARI na betonskoj stopi fi=30 cm i l=100 cm', 'quantity' => $project->cabinets->count(), 'unit' => 'KOMADA', 'strong' => true],
            ['label' => 'SPLITERI 1/16', 'quantity' => 0, 'unit' => 'KOMADA', 'strong' => true],
            ['label' => 'SPLITERI 1/4 (FTTH*3)', 'quantity' => $splitter14, 'unit' => 'KOMADA', 'strong' => true],
            ['label' => 'PROLAZNI SAHTOVI', 'quantity' => $appendixQuantity('manhole'), 'unit' => 'KOMADA', 'strong' => true],
            ['label' => 'BUSENJE PNEUMATSKOM RAKETOM fi 130 ispod ceste', 'quantity' => $appendixQuantity('boring_fi_130'), 'unit' => 'KOMADA', 'strong' => true],
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
            ['label' => 'PVC CIJEV fi 110', 'quantity' => 0, 'unit' => 'metara'],
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

        $unit = $data['type'] === 'boring_fi_130' ? 'KOMADA' : 'KOMADA';

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
        $cabinets = Cabinet::with(['project', 'odf'])->withCount(['houses', 'subscribers'])->orderBy('name')->get();

        return view('ftth.splitters', ['cabinets' => $cabinets]);
    }

    public function fiberSchema(): View
    {
        $projects = Project::with([
            'houses' => fn ($query) => $query->whereNull('cabinet_id')->orderBy('label'),
            'branches' => fn ($query) => $query->with(['route', 'cabinets.houses', 'childBranches.route', 'childBranches.cabinets.houses'])->orderBy('sort_order'),
            'odfs.cabinets' => fn ($query) => $query
                ->whereNull('parent_cabinet_id')
                ->with([
                    'houses' => fn ($houseQuery) => $houseQuery->orderBy('label'),
                    'childCabinets' => fn ($childQuery) => $childQuery->with(['houses' => fn ($houseQuery) => $houseQuery->orderBy('label')])->withCount(['houses', 'subscribers'])->orderBy('name'),
                ])
                ->withCount(['houses', 'subscribers'])
                ->orderBy('name'),
            'routes' => fn ($query) => $query->orderBy('route_type')->orderBy('name'),
        ])->orderBy('name')->get();

        return view('ftth.fiber-schema', ['projects' => $projects]);
    }
}

