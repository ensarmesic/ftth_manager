<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\Odf;

class SurveyDuctBindingService
{
    public function __construct(
        private readonly GeometryService $geometry,
        private readonly SurveyImportIdentityService $identity,
    ) {}

    /**
     * Resolve the network elements at a surveyed duct's endpoints.
     *
     * @return array{cabinet:?object,odf:?Odf,house:?object,route_type:string,match_confidence:string,candidates:array}
     */
    public function resolve(
        array $duct,
        $cabinets,
        $odfs,
        $houses,
        array $cabinetOverrides,
        float $elementToleranceMeters,
        float $endpointToleranceMeters,
    ): array {
        $house = null;
        if (filled($duct['house_ref'] ?? null)) {
            $reference = mb_strtolower((string) $duct['house_ref']);
            $house = $houses->first(fn ($candidate) => mb_strtolower((string) ($candidate->label ?? '')) === $reference
                || str_contains(mb_strtolower((string) ($candidate->address ?? '')), $reference));
        }

        $locatedHouses = $houses->filter(fn ($candidate) => $candidate->latitude !== null && $candidate->longitude !== null);
        if (($duct['prepared_sling'] ?? false) || isset($duct['_terminal_point']) || filled($duct['house_ref'] ?? null)) {
            $house ??= $this->nearestWithin($locatedHouses, end($duct['path']), $elementToleranceMeters)
                ?? $this->nearestWithin($locatedHouses, $duct['path'][0], $elementToleranceMeters);
        }

        $odf = $this->nearestWithin($odfs, $duct['path'][0], $endpointToleranceMeters)
            ?? $this->nearestWithin($odfs, end($duct['path']), $endpointToleranceMeters);

        $cabinet = null;
        $matchConfidence = 'none';
        $candidates = [];

        if (($duct['transit'] ?? false) === true) {
            // Transit ducts intentionally pass cabinets without terminating.
        } elseif (isset($cabinetOverrides[$duct['key']])) {
            $cabinet = $cabinets->firstWhere('id', (int) $cabinetOverrides[$duct['key']]);
            $matchConfidence = $cabinet ? 'manual' : 'none';
        } elseif ($duct['zo_tag'] !== null) {
            $cabinet = $cabinets->first(fn ($candidate) => $candidate->id !== null
                && $this->identity->cabinetTag($candidate->name) === $duct['zo_tag'])
                ?? $cabinets->first(fn ($candidate) => $this->identity->cabinetTag($candidate->name) === $duct['zo_tag']);
            $matchConfidence = $cabinet ? 'exact' : 'none';
        }

        if ($cabinet === null && ($duct['transit'] ?? false) !== true) {
            $start = $duct['path'][0];
            $end = end($duct['path']);
            $nearby = $cabinets
                ->map(fn ($candidate) => ['cabinet' => $candidate, 'distance_m' => min(
                    $this->geometry->distanceMeters((float) $candidate->latitude, (float) $candidate->longitude, $end[0], $end[1]),
                    $this->geometry->distanceMeters((float) $candidate->latitude, (float) $candidate->longitude, $start[0], $start[1]),
                )])
                ->filter(fn (array $row) => $row['distance_m'] <= $endpointToleranceMeters)
                ->sortBy('distance_m')
                ->values();

            if ($nearby->isNotEmpty()) {
                $cabinet = $nearby->first()['cabinet'];
                $matchConfidence = $nearby->count() === 1 ? 'exact' : 'ambiguous';
                $candidates = $nearby->filter(fn (array $row) => $row['cabinet']->id !== null)
                    ->map(fn (array $row) => [
                        'id' => $row['cabinet']->id,
                        'name' => $row['cabinet']->name,
                        'distance_m' => round($row['distance_m'], 1),
                    ])->values()->all();
            }
        }

        return [
            'cabinet' => $cabinet,
            'odf' => $odf,
            'house' => $house,
            'route_type' => match (true) {
                $house !== null => 'drop',
                $odf !== null => 'feeder',
                default => 'distribution',
            },
            'match_confidence' => $matchConfidence,
            'candidates' => $candidates,
        ];
    }

    public function nearestWithin($models, array $point, float $maxMeters)
    {
        $best = null;
        $bestDistance = INF;
        foreach ($models as $model) {
            $distance = $this->geometry->distanceMeters((float) $model->latitude, (float) $model->longitude, $point[0], $point[1]);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $model;
            }
        }

        return $bestDistance <= $maxMeters ? $best : null;
    }

    public function assignDropCabinetToHouse(House $house, ?Cabinet $cabinet): void
    {
        if ($cabinet === null || (int) $house->project_id !== (int) $cabinet->project_id) {
            return;
        }

        if ((int) $house->cabinet_id !== (int) $cabinet->id) {
            $house->update(['cabinet_id' => $cabinet->id]);
        }
    }

    public function assignCabinetToOdf(?Cabinet $cabinet, ?Odf $odf): void
    {
        if ($cabinet === null || $odf === null
            || (int) $cabinet->project_id !== (int) $odf->project_id
            || $cabinet->odf_id !== null) {
            return;
        }

        $cabinet->update(['odf_id' => $odf->id]);
    }
}
