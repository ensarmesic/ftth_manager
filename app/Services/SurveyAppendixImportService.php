<?php

namespace App\Services;

use App\Models\ProjectAppendixItem;

class SurveyAppendixImportService
{
    public function __construct(private readonly GeometryService $geometry) {}

    /**
     * Persist a classified survey point kind as project appendix items, skipping
     * already-stored items of the same type within the supplied tolerance.
     */
    public function importKind(
        int $projectId,
        array $points,
        string $kind,
        string $appendixType,
        string $batch,
        float $toleranceMeters,
    ): int {
        $existing = ProjectAppendixItem::where('project_id', $projectId)
            ->where('type', $appendixType)
            ->get(['latitude', 'longitude']);
        $created = 0;

        foreach (collect($points)->where('kind', $kind) as $point) {
            $nearby = $existing->contains(fn ($item) => $item->latitude !== null && $this->geometry->distanceMeters(
                (float) $item->latitude,
                (float) $item->longitude,
                $point['lat'],
                $point['lng'],
            ) <= $toleranceMeters);
            if ($nearby) {
                continue;
            }

            $existing->push(ProjectAppendixItem::create([
                'project_id' => $projectId,
                'type' => $appendixType,
                'quantity' => $appendixType === 'boring_fi_130' ? 0 : 1,
                'unit' => $appendixType === 'boring_fi_130' ? 'metara' : 'KOMADA',
                'latitude' => $point['lat'],
                'longitude' => $point['lng'],
                'note' => 'Geodetski snimak',
                'import_batch' => $batch,
            ]));
            $created++;
        }

        return $created;
    }
}
