<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectWorkItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectWorkItemController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate($this->rules());
        $project->workItems()->create([...$data, 'created_by' => $request->user()->id]);

        return back()->with('success', 'Radni zadatak je kreiran.');
    }

    public function update(Request $request, Project $project, ProjectWorkItem $workItem): RedirectResponse
    {
        abort_unless($workItem->project_id === $project->id, 404);
        $data = $request->validate($this->rules());
        $data['completed_at'] = $data['status'] === 'done' ? ($workItem->completed_at ?: now()) : null;
        $workItem->update($data);

        return back()->with('success', 'Radni zadatak je ažuriran.');
    }

    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:3000'],
            'assigned_to' => ['nullable', 'exists:users,id'], 'status' => ['required', Rule::in(['open', 'in_progress', 'blocked', 'done'])],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])], 'due_date' => ['nullable', 'date'],
        ];
    }
}
