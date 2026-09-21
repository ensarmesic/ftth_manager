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
        $data = $request->validate(['type' => ['required', Rule::in(['dxf', 'print_pdf', 'auto_plan', 'survey_import'])], 'points_file' => ['required_if:type,survey_import', 'file', 'max:20480', 'mimes:txt,csv'], 'limit' => ['nullable', 'integer', 'min:10', 'max:500']]);
        $file = $request->file('points_file');
        $task = $project->backgroundTasks()->create(['user_id' => $request->user()->id, 'type' => $data['type'], 'options' => ['limit' => $data['limit'] ?? 80, 'filename' => $file?->getClientOriginalName()], 'input_path' => $file?->store('background-inputs/'.$project->id)]);
        RunProjectBackgroundTask::dispatch($task->id);

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
}
