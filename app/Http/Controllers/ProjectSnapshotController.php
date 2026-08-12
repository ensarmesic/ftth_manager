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
        $versions = ProjectSnapshot::query()->where('project_id', $project->id)->latest()->limit(10)->get()
            ->map(fn (ProjectSnapshot $snapshot) => [
                'id' => $snapshot->id,
                'label' => $snapshot->label,
                'source' => $snapshot->source(),
                'content_summary' => $snapshot->contentSummary(),
                'item_count' => array_sum($snapshot->contentSummary()),
                'created_at' => $snapshot->created_at,
            ]);

        return response()->json(['snapshots' => $versions, 'versions' => $versions]);
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

    public function download(Project $project, ProjectSnapshot $snapshot)
    {
        abort_unless($snapshot->project_id === $project->id, 404);
        $filename = str($project->code ?: $project->name)->slug().'-backup-'.$snapshot->created_at->format('Ymd-His').'.json';
        $contents = json_encode([
            'format' => 'ftth-manager-project-snapshot', 'version' => 1,
            'exported_at' => now()->toIso8601String(),
            'project' => $project->only(['id', 'name', 'code', 'location', 'status']),
            'snapshot' => ['id' => $snapshot->id, 'label' => $snapshot->label, 'created_at' => $snapshot->created_at->toIso8601String()],
            'data' => $snapshot->payload,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return response($contents, 200, ['Content-Type' => 'application/json; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$filename.'"']);
    }
}
