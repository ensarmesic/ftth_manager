<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\Odf;
use App\Models\Project;
use App\Models\SurveyPoint;
use Illuminate\Support\Collection;

class SurveyBaseElementImportService
{
    public function __construct(
        private readonly SurveyImportIdentityService $identity,
        private readonly SurveyDuctBindingService $ductBinding,
    ) {}

    /** @return array{counts: array{points:int,cabinets:int,odfs:int,houses:int}, cabinets: Collection<int, Cabinet>, odfs: Collection<int, Odf>, houses: Collection<int, House>} */
    public function import(Project $project, array $points, string $batch, string $filename, float $elementToleranceM, float $odfMergeM): array
    {
        $counts = ['points' => 0, 'cabinets' => 0, 'odfs' => 0, 'houses' => 0];

        foreach ($points as $point) {
            SurveyPoint::create([
                'project_id' => $project->id, 'import_batch' => $batch, 'source_file' => $filename ?: null,
                'point_no' => $point['point_no'], 'x' => $point['x'], 'y' => $point['y'], 'z' => $point['z'],
                'latitude' => $point['lat'], 'longitude' => $point['lng'], 'code' => $point['code'], 'kind' => $point['kind'],
            ]);
            $counts['points']++;
        }

        foreach (collect($points)->where('kind', 'cabinet') as $point) {
            $nearby = $this->ductBinding->nearestWithin(
                Cabinet::where('project_id', $project->id)->whereNotNull('latitude')->get(),
                [$point['lat'], $point['lng']], $elementToleranceM
            );
            if ($nearby !== null) {
                $nearby->update(['name' => $this->identity->cabinetLabel($point['code'])]);

                continue;
            }
            Cabinet::create([
                'project_id' => $project->id,
                'name' => $this->identity->uniqueName(Cabinet::class, $project->id, $this->identity->cabinetLabel($point['code'])),
                'address' => 'Geodetski snimak', 'splitter_count' => 3, 'ports_per_splitter' => 4,
                'latitude' => $point['lat'], 'longitude' => $point['lng'], 'import_batch' => $batch,
            ]);
            $counts['cabinets']++;
        }
        $cabinets = Cabinet::where('project_id', $project->id)->whereNotNull('latitude')->get();

        foreach ($this->identity->mergeOdfPoints($points, $odfMergeM) as $point) {
            $name = $this->identity->odfLabel($point['code']);
            $named = $name !== 'ODF'
                ? Odf::where('project_id', $project->id)->get()->first(fn (Odf $odf) => $this->identity->odfIdentity($odf->name) === $this->identity->odfIdentity($name))
                : null;
            if ($named !== null || $this->identity->existsNearby(Odf::class, $project->id, $point['lat'], $point['lng'], $elementToleranceM)) {
                continue;
            }
            Odf::create([
                'project_id' => $project->id, 'name' => $this->identity->uniqueName(Odf::class, $project->id, $name),
                'address' => 'Geodetski snimak', 'fiber_capacity' => 144, 'port_count' => 48,
                'latitude' => $point['lat'], 'longitude' => $point['lng'], 'import_batch' => $batch,
            ]);
            $counts['odfs']++;
        }
        $odfs = Odf::where('project_id', $project->id)->whereNotNull('latitude')->get();

        foreach (collect($points)->where('kind', 'sling') as $point) {
            if (($point['prepared_sling'] ?? false) && filled($point['house_ref'] ?? null) && ! ($point['house_ref_generated'] ?? false)) {
                $reference = (string) $point['house_ref'];
                $exists = House::where('project_id', $project->id)
                    ->where(fn ($query) => $query->where('label', $reference)->orWhere('address', 'like', '%'.$reference.'%'))->exists();
                if (! $exists) {
                    House::create([
                        'project_id' => $project->id, 'label' => $this->identity->uniqueHouseLabel($project->id, $reference),
                        'address' => 'Planirana kuca iz SLINGA '.$reference, 'status' => 'planned',
                        'latitude' => null, 'longitude' => null, 'import_batch' => $batch,
                    ]);
                    $counts['houses']++;
                }

                continue;
            }
            if ($this->identity->existsNearby(House::class, $project->id, $point['lat'], $point['lng'], $elementToleranceM)) {
                continue;
            }
            House::create([
                'project_id' => $project->id,
                'label' => $this->identity->uniqueHouseLabel($project->id, (($point['prepared_sling'] ?? false) ? 'Slinga t' : 'Kuca t').$point['point_no']),
                'address' => $point['code'] ?: 'Geodetski snimak', 'status' => 'planned',
                'latitude' => $point['lat'], 'longitude' => $point['lng'], 'import_batch' => $batch,
            ]);
            $counts['houses']++;
        }

        return ['counts' => $counts, 'cabinets' => $cabinets, 'odfs' => $odfs, 'houses' => House::where('project_id', $project->id)->get()];
    }
}
