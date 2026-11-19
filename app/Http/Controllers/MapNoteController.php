<?php

namespace App\Http\Controllers;

use App\Models\MapNote;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Review notes on the map; anyone who can open the project may write, resolve and delete them. */
class MapNoteController extends Controller
{
    private const MIN_POINTS = ['point' => 1, 'line' => 2, 'area' => 3];

    public function index(Project $project): JsonResponse
    {
        $notes = $project->mapNotes()->with(['user', 'resolver'])->latest()->get();

        return response()->json(['notes' => $notes->map->toMapArray()->values()]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'geometry_type' => ['required', Rule::in(MapNote::GEOMETRY_TYPES)],
            'geometry' => ['required', 'array', 'max:500'],
            'geometry.*' => ['required', 'array', 'size:2'],
            'geometry.*.0' => ['required', 'numeric', 'between:-90,90'],
            'geometry.*.1' => ['required', 'numeric', 'between:-180,180'],
            'kind' => ['nullable', Rule::in(array_keys(MapNote::KINDS))],
            'body' => ['required', 'string', 'max:5000'],
        ]);
        $type = $data['geometry_type'];
        $geometry = array_map(fn (array $point) => [round((float) $point[0], 8), round((float) $point[1], 8)], $data['geometry']);
        if ($type === 'point') {
            $geometry = array_slice($geometry, 0, 1);
        }
        if (count($geometry) < self::MIN_POINTS[$type]) {
            throw ValidationException::withMessages(['geometry' => match ($type) {
                'line' => 'Linija treba najmanje dvije tačke.',
                'area' => 'Površina treba najmanje tri tačke.',
                default => 'Napomena treba tačku na mapi.',
            }]);
        }

        $note = $project->mapNotes()->create([
            'user_id' => $request->user()->id,
            'geometry_type' => $type,
            'geometry' => $geometry,
            'kind' => $data['kind'] ?? 'general',
            'body' => trim($data['body']),
            'status' => MapNote::OPEN,
        ]);

        return response()->json(['message' => 'Napomena je sačuvana.', 'note' => $note->load('user')->toMapArray()], 201);
    }

    public function update(Request $request, Project $project, MapNote $note): JsonResponse
    {
        abort_unless($note->project_id === $project->id, 404);
        $data = $request->validate([
            'kind' => ['sometimes', Rule::in(array_keys(MapNote::KINDS))],
            'body' => ['sometimes', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in([MapNote::OPEN, MapNote::RESOLVED])],
        ]);
        if (isset($data['body'])) {
            $data['body'] = trim($data['body']);
        }
        if (isset($data['status']) && $data['status'] !== $note->status) {
            $resolved = $data['status'] === MapNote::RESOLVED;
            $data['resolved_by'] = $resolved ? $request->user()->id : null;
            $data['resolved_at'] = $resolved ? now() : null;
        }
        $note->update($data);
        $message = match (true) {
            isset($data['resolved_at']) => 'Napomena je označena kao riješena.',
            array_key_exists('resolved_at', $data) => 'Napomena je ponovo otvorena.',
            default => 'Napomena je izmijenjena.',
        };

        return response()->json(['message' => $message, 'note' => $note->load(['user', 'resolver'])->toMapArray()]);
    }

    public function destroy(Project $project, MapNote $note): JsonResponse
    {
        abort_unless($note->project_id === $project->id, 404);
        $note->delete();

        return response()->json(['message' => 'Napomena je obrisana.']);
    }
}
