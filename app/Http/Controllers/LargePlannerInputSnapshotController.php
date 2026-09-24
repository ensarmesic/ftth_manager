<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\LargePlannerInputSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LargePlannerInputSnapshotController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $this->ensureLargeProject($project);

        return response()->json(['snapshots' => $project->largePlannerInputSnapshots()
            ->with('user:id,name')->latest('revision')->limit(25)->get()]);
    }

    public function store(Request $request, Project $project, LargePlannerInputSnapshotService $snapshots): JsonResponse|RedirectResponse
    {
        $this->ensureLargeProject($project);
        $data = $request->validate(['label' => ['nullable', 'string', 'max:120']]);
        $snapshot = $snapshots->create($project, $request->user(), $data['label'] ?? null);

        if ($request->expectsJson()) {
            return response()->json(['snapshot' => $snapshot], 201);
        }

        return back()->with('success', "Sačuvana je ulazna revizija {$snapshot->revision}.");
    }

    private function ensureLargeProject(Project $project): void
    {
        abort_unless($project->planning_mode === 'large_auto', 404);
    }
}
