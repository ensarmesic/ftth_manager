<?php

namespace App\Http\Controllers;

use App\Models\LargePlannerZone;
use App\Models\Project;
use App\Services\LargePlannerZoneAssignmentService;
use App\Services\LargePlannerZoneSplitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class LargePlannerZoneController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $this->ensureLargeProject($project);

        return response()->json(['zones' => $project->largePlannerZones()->withCount('houses')->orderBy('name')->get()]);
    }

    public function store(Request $request, Project $project, LargePlannerZoneAssignmentService $assignments): JsonResponse
    {
        $this->ensureLargeProject($project);
        $zone = $project->largePlannerZones()->create($request->validate($this->rules($project)));
        $zone->setAttribute('houses_count', $assignments->assignUnassignedInside($zone));

        return response()->json(['zone' => $zone], 201);
    }

    public function update(Request $request, Project $project, LargePlannerZone $zone): JsonResponse
    {
        $this->ensureZone($project, $zone);
        abort_if($zone->isLocked(), 423, 'Zaključana zona se ne može mijenjati.');
        $zone->update($request->validate($this->rules($project, $zone)));

        return response()->json(['zone' => $zone->fresh()]);
    }

    public function destroy(Project $project, LargePlannerZone $zone): JsonResponse
    {
        $this->ensureZone($project, $zone);
        abort_if($zone->isLocked(), 423, 'Zaključana zona se ne može obrisati.');
        $zone->delete();

        return response()->json(['deleted' => true]);
    }

    public function autoSplit(Request $request, Project $project, LargePlannerZoneSplitService $splitter): JsonResponse
    {
        $this->ensureLargeProject($project);
        $data = $request->validate(['target_size' => ['required', 'integer', 'min:25', 'max:500']]);

        try {
            $zones = $splitter->createInitialZones($project, (int) $data['target_size']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['zones' => $zones, 'created' => $zones->count()], 201);
    }

    public function assignHouses(Request $request, Project $project, LargePlannerZone $zone, LargePlannerZoneAssignmentService $assignments): JsonResponse
    {
        $this->ensureZone($project, $zone);
        $data = $request->validate(['house_ids' => ['required', 'array', 'min:1', 'max:20000'], 'house_ids.*' => ['integer', 'distinct']]);

        try {
            $assigned = $assignments->assign($zone, $data['house_ids']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['assigned' => $assigned, 'zone_id' => $zone->id]);
    }

    public function updateStatus(Request $request, Project $project, LargePlannerZone $zone): JsonResponse
    {
        $this->ensureZone($project, $zone);
        $data = $request->validate(['status' => ['required', Rule::in(LargePlannerZone::STATUSES)]]);
        $allowed = [
            'draft' => ['calculated'],
            'calculated' => ['draft', 'accepted'],
            'accepted' => ['calculated', 'locked'],
            'locked' => [],
        ];
        abort_unless(in_array($data['status'], $allowed[$zone->status] ?? [], true), 422, 'Nedozvoljen prijelaz statusa zone.');
        $zone->update(['status' => $data['status']]);

        return response()->json(['zone' => $zone->fresh()->loadCount('houses')]);
    }

    public function prepareReplan(Request $request, Project $project): JsonResponse
    {
        $this->ensureLargeProject($project);
        $data = $request->validate(['zone_ids' => ['required', 'array', 'min:1'], 'zone_ids.*' => ['integer', 'distinct']]);
        $zones = $project->largePlannerZones()->whereIn('id', $data['zone_ids'])->get();
        if ($zones->count() !== count($data['zone_ids'])) {
            return response()->json(['message' => 'Sve izabrane zone moraju pripadati projektu.'], 422);
        }
        if ($zones->contains->isLocked()) {
            return response()->json(['message' => 'Zaključana zona ne može biti uključena u ponovni proračun.'], 422);
        }

        DB::transaction(fn () => LargePlannerZone::whereIn('id', $zones->pluck('id'))->update(['status' => 'draft']));

        return response()->json([
            'zone_ids' => $zones->pluck('id')->values(),
            'message' => 'Izabrane zone su pripremljene za ponovni proračun.',
        ]);
    }

    private function rules(Project $project, ?LargePlannerZone $zone = null): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('large_planner_zones')->where('project_id', $project->id)->ignore($zone)],
            'geometry' => ['required', 'array', 'min:3', 'max:10000'],
            'geometry.*' => ['array', 'size:2'],
            'geometry.*.0' => ['numeric', 'between:-90,90'],
            'geometry.*.1' => ['numeric', 'between:-180,180'],
        ];
    }

    private function ensureLargeProject(Project $project): void
    {
        abort_unless($project->planning_mode === 'large_auto', 404);
    }

    private function ensureZone(Project $project, LargePlannerZone $zone): void
    {
        $this->ensureLargeProject($project);
        abort_unless($zone->project_id === $project->id, 404);
    }
}
