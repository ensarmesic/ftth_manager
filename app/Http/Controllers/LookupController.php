<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LookupController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(['projects', 'odfs', 'cabinets', 'routes'])], 'q' => ['nullable', 'string', 'max:120'], 'project' => ['nullable', 'integer', 'exists:projects,id']]);
        $model = match ($data['type']) {
            'projects' => Project::class, 'odfs' => Odf::class, 'cabinets' => Cabinet::class, 'routes' => NetworkRoute::class
        };
        $query = $model::query();
        if (($data['project'] ?? null) && $data['type'] !== 'projects') {
            $query->where('project_id', $data['project']);
        }
        if ($term = trim($data['q'] ?? '')) {
            $query->where(fn ($search) => $search->where('name', 'like', '%'.$term.'%')->when($data['type'] === 'projects', fn ($q) => $q->orWhere('code', 'like', '%'.$term.'%')));
        }
        $items = $query->orderBy('name')->limit(30)->get(['id', 'name', ...($data['type'] === 'projects' ? ['code'] : ['project_id'])])
            ->map(fn ($item) => ['id' => $item->id, 'text' => $item->name, 'meta' => $data['type'] === 'projects' ? $item->code : $item->project_id]);

        return response()->json(['items' => $items, 'more' => $items->count() === 30]);
    }
}
