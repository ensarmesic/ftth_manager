<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LargePlannerSettingController extends Controller
{
    public function update(Request $request, Project $project): RedirectResponse
    {
        abort_unless($project->planning_mode === 'large_auto', 404);

        $settings = $request->validate([
            'odo_capacity' => ['nullable', 'integer', 'min:1', 'max:1152'],
            'max_drop_length_m' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'fiber_reserve_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'optimization_goal' => ['nullable', 'in:min_trench,min_cable,min_odo,weighted'],
            'propose_odfs' => ['nullable', 'boolean'],
            'odf_capacity' => ['nullable', 'required_if:propose_odfs,1', 'integer', 'min:1', 'max:1152'],
        ]);

        $project->largePlannerSetting()->updateOrCreate([], $settings);

        return back()->with('success', 'Postavke velikog planera su sačuvane.');
    }
}
