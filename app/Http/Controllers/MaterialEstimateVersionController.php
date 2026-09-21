<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MaterialEstimateVersionController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:120']]);
        $items = $project->materials()->orderBy('name')->get()->map(fn ($item) => $item->only(['name', 'unit', 'planned_quantity', 'used_quantity', 'unit_price']))->all();
        $project->materialEstimateVersions()->create([
            'user_id' => $request->user()->id, 'label' => $data['label'], 'items' => $items,
            'planned_total' => collect($items)->sum(fn ($item) => (float) $item['planned_quantity'] * (float) $item['unit_price']),
            'used_total' => collect($items)->sum(fn ($item) => (float) $item['used_quantity'] * (float) $item['unit_price']),
        ]);
        $project->materialEstimateVersions()->latest()->get()->slice(30)->each->delete();

        return back()->with('success', 'Revizija troškovnika je sačuvana.');
    }
}
