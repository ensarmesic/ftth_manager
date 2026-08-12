<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectSnapshot;
use App\Services\ProjectSnapshotService;
use Illuminate\Http\Request;

class ProjectSnapshotController extends Controller
{
    public function index(Project $project)
    {
        return response()->json(['snapshots' => ProjectSnapshot::query()->where('project_id', $project->id)->latest()->limit(10)->get(['id', 'label', 'created_at'])]);
    }

    public function store(Request $request, Project $project, ProjectSnapshotService $service)
    {
        $data = $request->validate(['label' => ['nullable', 'string', 'max:255']]);
        $snapshot = $service->create($project, $data['label'] ?? 'Ručna sigurnosna kopija');

        return response()->json(['message' => 'Sigurnosna kopija projekta je sačuvana.', 'snapshot' => $snapshot], 201);
    }

    public function restore(Project $project, ProjectSnapshot $snapshot, ProjectSnapshotService $service)
    {
        $service->restore($project, $snapshot);

        return response()->json(['message' => 'Projekat je vraćen na odabranu sigurnosnu kopiju.']);
    }
}
