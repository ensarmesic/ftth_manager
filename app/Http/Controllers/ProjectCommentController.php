<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Models\ProjectComment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectCommentController extends Controller
{
    private const SUBJECTS = ['project' => Project::class, 'odf' => Odf::class, 'cabinet' => Cabinet::class, 'house' => House::class, 'route' => NetworkRoute::class];

    public function store(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'], 'subject_type' => ['required', Rule::in(array_keys(self::SUBJECTS))],
            'subject_id' => ['nullable', 'integer'], 'attachment' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf,txt,csv'],
        ]);
        $subjectId = $data['subject_type'] === 'project' ? $project->id : ($data['subject_id'] ?? null);
        if ($data['subject_type'] !== 'project') {
            $model = self::SUBJECTS[$data['subject_type']]::query()->whereKey($subjectId)->where('project_id', $project->id)->first();
            abort_unless($model, 422, 'Odabrani mrežni element ne pripada projektu.');
        }
        $file = $request->file('attachment');
        $comment = $project->comments()->create([
            'user_id' => $request->user()->id, 'subject_type' => $data['subject_type'], 'subject_id' => $subjectId, 'body' => $data['body'],
            'attachment_path' => $file?->store('project-comments/'.$project->id), 'attachment_name' => $file?->getClientOriginalName(),
            'attachment_mime' => $file?->getMimeType(), 'attachment_size' => $file?->getSize(),
        ]);

        return back()->with('success', "Komentar #{$comment->id} je dodan.");
    }

    public function download(Project $project, ProjectComment $comment): StreamedResponse
    {
        abort_unless($comment->project_id === $project->id && $comment->attachment_path && Storage::exists($comment->attachment_path), 404);

        return Storage::download($comment->attachment_path, $comment->attachment_name);
    }
}
