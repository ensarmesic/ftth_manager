<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesFtthData;
use App\Models\Project;
use Illuminate\View\View;

class ProjectPrintController extends Controller
{
    use ManagesFtthData;

    public function __invoke(Project $project): View
    {
        $project->load([
            'odfs.cabinets',
            'cabinets.houses',
            'houses.cabinet',
            'routes' => fn ($query) => $query->orderBy('route_type')->orderBy('name'),
            'appendixItems',
        ]);

        return view('ftth.projects.print', [
            'project' => $project,
            'validationItems' => collect($this->ftthIntelligence->validateProject($project)),
            'materials' => $this->ftthIntelligence->materialSummary($project),
        ]);
    }
}
