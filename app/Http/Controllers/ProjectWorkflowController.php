<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectStageHistory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProjectWorkflowController extends Controller
{
    public const STAGES = ['draft', 'review', 'approved', 'built', 'as_built'];

    private const TRANSITIONS = [
        'draft' => ['review'],
        'review' => ['draft', 'approved'],
        'approved' => ['review', 'built'],
        'built' => ['approved', 'as_built'],
        'as_built' => ['built'],
    ];

    public function update(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'stage' => ['required', Rule::in(self::STAGES)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $from = $project->workflow_stage ?: 'draft';
        abort_unless(in_array($data['stage'], self::TRANSITIONS[$from] ?? [], true), 422, 'Nedozvoljen prijelaz statusa projekta.');

        DB::transaction(function () use ($project, $request, $data, $from): void {
            $project->update(['workflow_stage' => $data['stage'], 'workflow_changed_at' => now(), 'workflow_changed_by' => $request->user()->id]);
            ProjectStageHistory::create(['project_id' => $project->id, 'user_id' => $request->user()->id, 'from_stage' => $from, 'to_stage' => $data['stage'], 'note' => $data['note'] ?? null]);
        });

        return back()->with('success', 'Status projekta je promijenjen.');
    }
}
