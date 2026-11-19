<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesFtthData;
use App\Models\NetworkRoute;
use App\Models\Project;
use App\Models\ProjectAppendixItem;
use App\Services\FiberSchema\FiberSchemaDrawing;
use App\Services\FiberSchema\FiberSchemaSvg;
use App\Services\ProjectReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class ReportController extends Controller
{
    use ManagesFtthData;

    public function reports(Request $request, ProjectReportService $reports): View
    {
        $projects = Project::withCount(['odfs', 'cabinets', 'houses', 'routes'])->orderBy('name')->get();
        $selected = $request->integer('project') > 0 ? $projects->firstWhere('id', $request->integer('project')) : null;

        return view('ftth.reports', [
            'projects' => $projects,
            'selectedProject' => $selected,
            'overview' => $selected ? null : $reports->overview($projects),
            'report' => $selected ? $reports->detail($selected) : null,
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

        $aerial = $project->isAerial();
        $segments = [
            ['label' => 'ODF ormari na betonskoj stopi a*b*h / 40*80*100', 'quantity' => $project->odfs->count(), 'unit' => 'KOMADA', 'strong' => true],
            ['label' => $aerial ? 'ODP/NAP PRIKLJUČNE KUTIJE NA STUBU (MAX 4 KUĆE)' : 'FTTH ORMARI na betonskoj stopi fi=30 cm i l=100 cm', 'quantity' => $project->cabinets->count(), 'unit' => 'KOMADA', 'strong' => true],
            ['label' => 'SPLITERI 1/16', 'quantity' => 0, 'unit' => 'KOMADA', 'strong' => true],
            ['label' => 'SPLITERI 1/4 (FTTH*3)', 'quantity' => $splitter14, 'unit' => 'KOMADA', 'strong' => true],
            ['label' => 'PROLAZNI SAHTOVI', 'quantity' => $appendixQuantity('manhole'), 'unit' => 'KOMADA', 'strong' => true],
            ['label' => 'STUBOVI ZA NADZEMNU ADSS MREZU', 'quantity' => $appendixQuantity('pole'), 'unit' => 'KOMADA', 'strong' => true],
            ['label' => 'BUSENJE PNEUMATSKOM RAKETOM fi 130 ispod ceste', 'quantity' => $boringFi130Length, 'unit' => 'metara', 'strong' => true],
            ['label' => 'BUSENJE PNEUMATSKOM RAKETOM FI 75 ispod ceste', 'quantity' => 0, 'unit' => 'KOMADA', 'strong' => true],
        ];

        $recap = $aerial ? [
            ['label' => 'ADSS OPTIČKI KABL', 'quantity' => $materialRoutes->where('installation_type', 'aerial')->sum('fiber_length_m'), 'unit' => 'metara'],
            ['label' => 'PRIKLJUČNI STUBOVI', 'quantity' => $appendixQuantity('pole'), 'unit' => 'KOMADA'],
            ['label' => 'ODP/NAP KUTIJE NA STUBU', 'quantity' => $project->cabinets->count(), 'unit' => 'KOMADA'],
            ['label' => 'KORISNIČKI ADSS DROP KABL', 'quantity' => $dropRoutes->sum('fiber_length_m'), 'unit' => 'metara'],
        ] : [
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
            'type' => ['required', 'in:manhole,boring_fi_130,pole'],
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

    /** PDF: page 1 is the fiber schematic sheet (same drawing as screen and DXF), page 2 the optical budget. */
    public function fiberSchemaPdf(Project $project, FiberSchemaDrawing $drawings, FiberSchemaSvg $svg): Response
    {
        $drawing = $drawings->build($project);
        $sheet = $drawing['sheet'];
        $toPoints = 72 / 25.4;

        $pdf = Pdf::loadView('ftth.fiber-schema-pdf', [
            'project' => $project,
            'plan' => $drawing['plan'],
            'sheet' => $sheet,
            'sheetSvg' => $svg->render($drawing, false, true),
            'labels' => $svg->labels($drawing),
        ])->setPaper([0, 0, $sheet['width'] * $toPoints, $sheet['height'] * $toPoints]);

        $response = $pdf->download('fiber-shema-'.str($project->code ?: $project->name)->slug().'-'.now()->format('Ymd').'.pdf');
        $response->headers->set('X-Fiber-Plan-Signature', $drawing['plan']['signature']);

        return $response;
    }

    public function fiberSchema(Request $request, FiberSchemaDrawing $drawings, FiberSchemaSvg $svg): View
    {
        $projectOptions = Project::query()->orderBy('name')->get(['id', 'name', 'code']);
        $selectedProjectId = Project::query()->whereKey($request->integer('project'))->value('id')
            ?? $projectOptions->first()?->id;
        $projects = Project::query()->with(['fiberSplices'])->whereKey($selectedProjectId)->get();
        $project = $projects->first();
        $drawing = $project ? $drawings->build($project) : null;
        // Houses per cabinet in port order, for the port plan (splitter S, port P).
        $housesByCabinet = $project
            ? $project->houses()->whereNotNull('cabinet_id')->orderBy('label')->get(['id', 'cabinet_id', 'label', 'address'])
                ->sortBy(fn ($house) => (string) preg_replace_callback('/\d+/', fn ($m) => str_pad($m[0], 8, '0', STR_PAD_LEFT), mb_strtolower((string) $house->label)))
                ->groupBy('cabinet_id')
            : collect();

        return view('ftth.fiber-schema', [
            'projectOptions' => $projectOptions,
            'selectedProjectId' => $selectedProjectId,
            'projects' => $projects,
            'project' => $project,
            'drawing' => $drawing,
            'sheetSvg' => $drawing ? $svg->render($drawing) : null,
            'housesByCabinet' => $housesByCabinet,
            'unassignedHouses' => $project ? $project->houses()->whereNull('cabinet_id')->orderBy('label')->get(['id', 'label', 'address']) : collect(),
        ]);
    }
}
