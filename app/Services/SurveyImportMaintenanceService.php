<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Models\ProjectAppendixItem;
use App\Models\SurveyPoint;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SurveyImportMaintenanceService
{
    public function __construct(private readonly BranchSyncService $branchSync) {}

    /**
     * Remove everything a geodetic survey import created in this project — routes,
     * cabinets, ODFs, houses, appendix items (all tagged with an `import_batch` at
     * creation time, never on a merge/extend of a pre-existing route — see confirm()),
     * plus all raw survey_points — so the same TXT file can be re-imported. Elements the
     * user drew manually are never tagged and are therefore never touched here, even if a
     * later import extended one of their routes.
     *
     * @return array<string,int> counts removed, keyed like confirm()'s $created
     */
    public function clearImportedData(Project $project): array
    {
        $removed = ['points' => 0, 'trenches' => 0, 'ducts' => 0, 'cabinets' => 0, 'odfs' => 0, 'manholes' => 0, 'borings' => 0, 'splices' => 0, 'loops' => 0, 'houses' => 0];

        DB::transaction(function () use ($project, &$removed): void {
            $routes = NetworkRoute::where('project_id', $project->id)->whereNotNull('import_batch')->get();
            foreach ($routes as $route) {
                $removed[$route->route_type === 'trench' ? 'trenches' : 'ducts']++;
                $this->branchSync->deleteRouteWithBranch($route);
            }

            $removed['cabinets'] = Cabinet::where('project_id', $project->id)->whereNotNull('import_batch')->count();
            Cabinet::where('project_id', $project->id)->whereNotNull('import_batch')->delete();

            $removed['odfs'] = Odf::where('project_id', $project->id)->whereNotNull('import_batch')->count();
            Odf::where('project_id', $project->id)->whereNotNull('import_batch')->delete();

            $removed['houses'] = House::where('project_id', $project->id)->whereNotNull('import_batch')->count();
            House::where('project_id', $project->id)->whereNotNull('import_batch')->delete();

            foreach (['manhole', 'boring_fi_130', 'splice', 'loop'] as $appendixType) {
                $key = match ($appendixType) {
                    'manhole' => 'manholes',
                    'boring_fi_130' => 'borings',
                    'splice' => 'splices',
                    'loop' => 'loops',
                };
                $removed[$key] = ProjectAppendixItem::where('project_id', $project->id)
                    ->where('type', $appendixType)->whereNotNull('import_batch')->count();
                ProjectAppendixItem::where('project_id', $project->id)
                    ->where('type', $appendixType)->whereNotNull('import_batch')->delete();
            }

            $txtPoints = SurveyPoint::where('project_id', $project->id)->where('source', '!=', 'gps');
            $removed['points'] = (clone $txtPoints)->count();
            $txtPoints->delete();
        });

        return $removed;
    }

    /** List individual TXT imports available for selective removal. */
    public function importedBatches(Project $project): array
    {
        return SurveyPoint::where('project_id', $project->id)
            ->whereNotNull('source_file')
            ->whereNotNull('import_batch')
            ->selectRaw('import_batch, source_file, COUNT(*) as points_count, MIN(created_at) as imported_at')
            ->groupBy('import_batch', 'source_file')
            ->orderByDesc('imported_at')
            ->get()
            ->map(fn (SurveyPoint $row) => [
                'batch' => $row->import_batch,
                'filename' => $row->source_file ?: 'TXT uvoz',
                'points_count' => (int) $row->points_count,
                'imported_at' => $row->imported_at,
            ])
            ->values()
            ->all();
    }

    /** Remove exactly one TXT import batch; every other import remains untouched. */
    public function clearImportedBatch(Project $project, string $batch): array
    {
        $belongsToProject = SurveyPoint::where('project_id', $project->id)
            ->where('import_batch', $batch)
            ->whereNotNull('source_file')
            ->exists();
        if (! $belongsToProject) {
            throw new InvalidArgumentException('Odabrani TXT uvoz ne postoji u ovom projektu.');
        }

        $removed = ['points' => 0, 'trenches' => 0, 'ducts' => 0, 'cabinets' => 0, 'odfs' => 0, 'manholes' => 0, 'borings' => 0, 'splices' => 0, 'loops' => 0, 'houses' => 0];
        DB::transaction(function () use ($project, $batch, &$removed): void {
            $routes = NetworkRoute::where('project_id', $project->id)->where('import_batch', $batch)->get();
            foreach ($routes as $route) {
                $removed[$route->route_type === 'trench' ? 'trenches' : 'ducts']++;
                $this->branchSync->deleteRouteWithBranch($route);
            }

            foreach ([
                'cabinets' => Cabinet::class,
                'odfs' => Odf::class,
                'houses' => House::class,
            ] as $key => $model) {
                $removed[$key] = $model::where('project_id', $project->id)->where('import_batch', $batch)->count();
                $model::where('project_id', $project->id)->where('import_batch', $batch)->delete();
            }

            foreach (['manhole', 'boring_fi_130', 'splice', 'loop'] as $appendixType) {
                $key = match ($appendixType) {
                    'manhole' => 'manholes',
                    'boring_fi_130' => 'borings',
                    'splice' => 'splices',
                    'loop' => 'loops',
                };
                $query = ProjectAppendixItem::where('project_id', $project->id)
                    ->where('import_batch', $batch)->where('type', $appendixType);
                $removed[$key] = (clone $query)->count();
                $query->delete();
            }

            $points = SurveyPoint::where('project_id', $project->id)->where('import_batch', $batch);
            $removed['points'] = (clone $points)->count();
            $points->delete();
        });

        return $removed;
    }
}
