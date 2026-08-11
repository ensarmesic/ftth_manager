<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class ProjectMaterialController extends Controller
{
    public function __invoke(Project $project): RedirectResponse
    {
        $routes = $project->routes()->where('route_type', '!=', 'trench')->get();
        $materials = [];

        foreach ($routes->groupBy('microduct_type')->filter(fn ($group, $type) => filled($type)) as $type => $group) {
            $materials["Mikrocijev $type"] = [
                'unit' => 'm',
                'planned_quantity' => $group->sum(fn ($route) => $route->duct_length_m * max((int) $route->microduct_count, 1)),
            ];
        }
        foreach ($routes->groupBy('fiber_count')->filter(fn ($group, $count) => filled($count)) as $count => $group) {
            $materials["Opticki kabl $count niti"] = [
                'unit' => 'm',
                'planned_quantity' => $group->sum('fiber_length_m'),
            ];
        }

        DB::transaction(function () use ($project, $materials): void {
            foreach ($materials as $name => $data) {
                $project->materials()->updateOrCreate(['name' => $name], $data);
            }
        });

        return back();
    }
}
