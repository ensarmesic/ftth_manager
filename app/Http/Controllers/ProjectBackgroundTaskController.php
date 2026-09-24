<?php

namespace App\Http\Controllers;

use App\Jobs\RunProjectBackgroundTask;
use App\Models\Project;
use App\Models\ProjectBackgroundTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectBackgroundTaskController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(['dxf', 'print_pdf', 'auto_plan', 'survey_import', 'large_plan'])], 'points_file' => ['required_if:type,survey_import', 'file', 'max:20480', 'mimes:txt,csv'], 'limit' => ['nullable', 'integer', 'min:10', 'max:500'], 'base_task_id' => ['nullable', 'integer']]);
        abort_if($data['type'] === 'large_plan' && $project->planning_mode !== 'large_auto', 404);
        if ($data['type'] === 'large_plan' && isset($data['base_task_id'])) {
            abort_unless($project->backgroundTasks()->whereKey($data['base_task_id'])->where('type', 'large_plan')->where('status', 'completed')->exists(), 422, 'Osnovna varijanta nije dostupna.');
        }
        if ($data['type'] === 'large_plan' && $project->backgroundTasks()->where('type', 'large_plan')->whereIn('status', ['queued', 'running'])->exists()) {
            return response()->json(['message' => 'Proračun za ovaj projekat je već u toku.'], 409);
        }
        $file = $request->file('points_file');
        $task = $project->backgroundTasks()->create(['user_id' => $request->user()->id, 'type' => $data['type'], 'options' => ['limit' => $data['limit'] ?? 80, 'filename' => $file?->getClientOriginalName(), 'base_task_id' => $data['base_task_id'] ?? null], 'input_path' => $file?->store('background-inputs/'.$project->id)]);
        RunProjectBackgroundTask::dispatch($task->id);
        $task->refresh();

        return $request->expectsJson() ? response()->json(['task' => $task, 'message' => 'Zadatak je stavljen u red obrade.'], 202) : back()->with('success', 'Zadatak je stavljen u red obrade.');
    }

    public function show(Project $project, ProjectBackgroundTask $task): JsonResponse
    {
        abort_unless($task->project_id === $project->id, 404);

        return response()->json(['task' => $task->fresh()]);
    }

    public function download(Project $project, ProjectBackgroundTask $task): StreamedResponse
    {
        abort_unless($task->project_id === $project->id && $task->status === 'completed' && $task->result_path && Storage::exists($task->result_path), 404);

        return Storage::download($task->result_path, $task->result_name);
    }

    public function retry(Request $request, Project $project, ProjectBackgroundTask $task): JsonResponse
    {
        abort_unless($task->project_id === $project->id && $task->type === 'large_plan', 404);
        abort_unless($task->status === 'failed', 409, 'Ponoviti se može samo neuspješan proračun.');

        $task->update([
            'status' => 'queued',
            'progress' => 0,
            'status_message' => 'Ponovni pokušaj je stavljen u red obrade.',
            'error' => null,
            'finished_at' => null,
        ]);
        RunProjectBackgroundTask::dispatch($task->id);

        return response()->json(['task' => $task->fresh(), 'message' => 'Ponovni pokušaj je stavljen u red obrade.'], 202);
    }
}
