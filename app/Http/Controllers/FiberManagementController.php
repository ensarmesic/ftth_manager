<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\FiberSchemaVersion;
use App\Models\FiberSplice;
use App\Models\Project;
use App\Services\FiberPlanService;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FiberManagementController extends Controller
{
    public function plan(Project $project, FiberPlanService $service): JsonResponse
    {
        return response()->json($service->build($project));
    }

    public function storeSplice(Request $request, Project $project): JsonResponse
    {
        abort_if($project->fiber_schema_locked, 423, 'Fiber šema je zaključana.');
        $data = $request->validate([
            'cabinet_id' => ['required', 'integer', 'exists:cabinets,id'], 'fiber_number' => ['required', 'integer', 'min:1'],
            'tray' => ['required', 'integer', 'min:1'], 'position' => ['required', 'integer', 'min:1'],
            'incoming_label' => ['nullable', 'string', 'max:255'], 'outgoing_label' => ['nullable', 'string', 'max:255'],
            'loss_db' => ['required', 'numeric', 'min:0', 'max:5'], 'note' => ['nullable', 'string', 'max:1000'],
        ]);
        abort_unless(Cabinet::whereKey($data['cabinet_id'])->where('project_id', $project->id)->exists(), 422);
        $splice = FiberSplice::updateOrCreate(
            ['project_id' => $project->id, 'cabinet_id' => $data['cabinet_id'], 'fiber_number' => $data['fiber_number']],
            $data,
        );

        return response()->json(['message' => 'Splice zapis je sačuvan.', 'splice' => $splice], $splice->wasRecentlyCreated ? 201 : 200);
    }

    public function destroySplice(Project $project, FiberSplice $splice): JsonResponse
    {
        abort_if($project->fiber_schema_locked, 423, 'Fiber šema je zaključana.');
        abort_unless($splice->project_id === $project->id, 404);
        $splice->delete();

        return response()->json(['message' => 'Splice zapis je obrisan.']);
    }

    public function storeVersion(Request $request, Project $project, FiberPlanService $service): JsonResponse
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:255']]);
        $version = FiberSchemaVersion::create([
            'project_id' => $project->id, 'user_id' => $request->user()->id, 'label' => $data['label'],
            'payload' => ['plan' => $service->build($project), 'splices' => $project->fiberSplices()->get()->toArray(), 'settings' => $project->only(['fiber_layout', 'fiber_color_standard', 'fiber_reserve_per_tube', 'fiber_budget_limit_db'])],
        ]);
        FiberSchemaVersion::where('project_id', $project->id)->latest()->get()->slice(20)->each->delete();

        return response()->json(['message' => 'Verzija fiber šeme je sačuvana.', 'version' => $version], 201);
    }

    public function versions(Project $project): JsonResponse
    {
        return response()->json(['versions' => $project->fiberSchemaVersions()->with('user:id,name')->latest()->limit(20)->get()]);
    }

    public function compare(Project $project, FiberSchemaVersion $version): JsonResponse
    {
        abort_unless($version->project_id === $project->id, 404);
        $current = app(FiberPlanService::class)->build($project);
        $old = $version->payload['plan'] ?? [];

        return response()->json(['version' => $version, 'changes' => [
            'used_fibers' => ['before' => $old['usedTo'] ?? 0, 'after' => $current['usedTo']],
            'health' => ['before' => $old['health'] ?? 0, 'after' => $current['health']],
            'connections' => ['before' => count($old['connections'] ?? []), 'after' => count($current['connections'])],
            'issues' => ['before' => count($old['issues'] ?? []), 'after' => count($current['issues'])],
        ]]);
    }

    public function restore(Request $request, Project $project, FiberSchemaVersion $version, FiberPlanService $service): JsonResponse
    {
        abort_if($project->fiber_schema_locked, 423, 'Prvo otključajte fiber šemu.');
        abort_unless($version->project_id === $project->id, 404);
        DB::transaction(function () use ($request, $project, $version, $service): void {
            FiberSchemaVersion::create(['project_id' => $project->id, 'user_id' => $request->user()->id, 'label' => 'Automatski: prije vraćanja '.$version->label, 'payload' => ['plan' => $service->build($project), 'splices' => $project->fiberSplices()->get()->toArray(), 'settings' => $project->only(['fiber_layout', 'fiber_color_standard', 'fiber_reserve_per_tube', 'fiber_budget_limit_db'])]]);
            $settings = $version->payload['settings'] ?? [];
            $project->update(collect($settings)->only(['fiber_layout', 'fiber_color_standard', 'fiber_reserve_per_tube', 'fiber_budget_limit_db'])->all());
            $project->fiberSplices()->delete();
            foreach ($version->payload['splices'] ?? [] as $splice) {
                $project->fiberSplices()->create(collect($splice)->only(['cabinet_id', 'fiber_number', 'tray', 'position', 'incoming_label', 'outgoing_label', 'loss_db', 'note'])->all());
            }
        });

        return response()->json(['message' => 'Fiber šema je vraćena na odabranu verziju; prethodno stanje je automatski sačuvano.']);
    }

    public function toggleLock(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate(['locked' => ['required', 'boolean']]);
        $project->update(['fiber_schema_locked' => $data['locked'], 'fiber_schema_locked_at' => $data['locked'] ? now() : null, 'fiber_schema_locked_by' => $data['locked'] ? $request->user()->id : null]);

        return response()->json(['message' => $data['locked'] ? 'Fiber šema je zaključana i odobrena.' : 'Fiber šema je otključana.', 'locked' => $project->fiber_schema_locked]);
    }

    public function storeLayout(Request $request, Project $project): JsonResponse
    {
        abort_if($project->fiber_schema_locked, 423, 'Fiber šema je zaključana.');
        $data = $request->validate(['positions' => ['required', 'array', 'max:1000'], 'positions.*.x' => ['required', 'numeric'], 'positions.*.y' => ['required', 'numeric']]);
        $project->update(['fiber_schema_layout' => $data['positions']]);

        return response()->json(['message' => 'Ručni raspored fiber šeme je sačuvan.']);
    }

    public function csv(Project $project, FiberPlanService $service): Response
    {
        $plan = $service->build($project);
        $lines = ['ODO;ODF ID;Krak;Vlakna;Tuba;Kuće;Kapacitet;Dužina km;Splitter;Gubitak dB;Rezerva dB;Status'];
        foreach ($plan['connections'] as $row) {
            $lines[] = implode(';', [$row['cabinet'], $row['odf_id'], $row['branch'], 'F'.$row['fiber_from'].'-F'.$row['fiber_to'], $row['tube'], $row['houses'], $row['capacity'], $row['route_km'], $row['splitter_ratio'], $row['loss_db'], $row['margin_db'], $row['budget_status']]);
        }

        return response("\xEF\xBB\xBF".implode("\r\n", $lines), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="fiber-plan-'.str($project->code ?: $project->name)->slug().'.csv"']);
    }

    public function fieldSheet(Project $project, Cabinet $cabinet, FiberPlanService $service): View
    {
        abort_unless($cabinet->project_id === $project->id, 404);
        $cabinet->load(['odf', 'houses', 'branch.route']);
        $plan = collect($service->build($project)['connections'])->firstWhere('cabinet_id', $cabinet->id);
        $qrCode = new QrCode(data: route('projects.fiber.field-sheet', [$project, $cabinet]), encoding: new Encoding('UTF-8'), errorCorrectionLevel: ErrorCorrectionLevel::High, size: 260, margin: 8);
        $qrDataUri = (new SvgWriter)->write($qrCode)->getDataUri();

        return view('ftth.fiber-field-sheet', compact('project', 'cabinet', 'plan', 'qrDataUri'));
    }
}
