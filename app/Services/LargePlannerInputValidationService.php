<?php

namespace App\Services;

use App\Models\GisSegment;
use App\Models\Project;

class LargePlannerInputValidationService
{
    private const CONNECTION_TOLERANCE_M = 2.0;

    public function __construct(private readonly GeometryService $geometry) {}

    public function validate(Project $project): array
    {
        $project->loadMissing(['houses', 'largePlannerSetting']);
        $corridors = GisSegment::query()
            ->where('project_id', $project->id)
            ->where('is_allowed', true)
            ->whereNotNull('planning_corridor_type')
            ->get()
            ->filter(fn (GisSegment $segment) => count($segment->path ?? []) >= 2)
            ->values();

        $issues = [];
        if ($corridors->isEmpty()) {
            $issues[] = $this->issue('error', 'missing_corridors', 'Nije definisan nijedan dozvoljeni koridor velikog planera.');
        } else {
            $components = $this->corridorComponents($corridors->all());
            if (count($components) > 1) {
                $issues[] = $this->issue(
                    'error',
                    'disconnected_corridors',
                    'Dozvoljeni koridori imaju '.count($components).' nepovezane cjeline.',
                    ['components' => $components],
                );
            }
        }

        $missingCoordinates = $project->houses
            ->filter(fn ($house) => $house->latitude === null || $house->longitude === null)
            ->values();
        if ($missingCoordinates->isNotEmpty()) {
            $issues[] = $this->issue(
                'error',
                'houses_without_coordinates',
                $missingCoordinates->count().' kuća nema ispravne koordinate.',
                ['house_ids' => $missingCoordinates->pluck('id')->all()],
            );
        }

        $dropLimit = $project->largePlannerSetting?->max_drop_length_m;
        $distantHouses = [];
        if ($dropLimit !== null && $corridors->isNotEmpty()) {
            foreach ($project->houses->diff($missingCoordinates) as $house) {
                $distance = $corridors->min(fn (GisSegment $corridor) => $this->geometry->distanceToRoute(
                    (float) $house->latitude,
                    (float) $house->longitude,
                    $corridor->path ?? [],
                ));
                if ($distance > $dropLimit) {
                    $distantHouses[] = ['id' => $house->id, 'label' => $house->label, 'distance_m' => (int) round($distance)];
                }
            }
            if ($distantHouses !== []) {
                $issues[] = $this->issue(
                    'error',
                    'houses_too_far_from_corridor',
                    count($distantHouses)." kuća je dalje od dozvoljenih {$dropLimit} m od koridora.",
                    ['houses' => $distantHouses, 'limit_m' => $dropLimit],
                );
            }
        } elseif ($dropLimit === null) {
            $issues[] = $this->issue('warning', 'missing_drop_limit', 'Maksimalna dužina drop veze još nije potvrđena.');
        }

        return [
            'ready' => collect($issues)->where('severity', 'error')->isEmpty(),
            'summary' => [
                'houses' => $project->houses->count(),
                'corridors' => $corridors->count(),
                'errors' => collect($issues)->where('severity', 'error')->count(),
                'warnings' => collect($issues)->where('severity', 'warning')->count(),
            ],
            'issues' => $issues,
        ];
    }

    private function corridorComponents(array $corridors): array
    {
        $parents = array_keys($corridors);
        $find = function (int $node) use (&$parents, &$find): int {
            return $parents[$node] === $node ? $node : $parents[$node] = $find($parents[$node]);
        };
        $union = function (int $a, int $b) use (&$parents, $find): void {
            $parents[$find($b)] = $find($a);
        };

        for ($i = 0; $i < count($corridors); $i++) {
            for ($j = $i + 1; $j < count($corridors); $j++) {
                if ($this->pathsTouch($corridors[$i]->path ?? [], $corridors[$j]->path ?? [])) {
                    $union($i, $j);
                }
            }
        }

        $components = [];
        foreach ($corridors as $index => $corridor) {
            $components[$find($index)][] = $corridor->id;
        }

        return array_values($components);
    }

    private function pathsTouch(array $first, array $second): bool
    {
        foreach ($first as $point) {
            if ($this->geometry->distanceToRoute((float) $point[0], (float) $point[1], $second) <= self::CONNECTION_TOLERANCE_M) {
                return true;
            }
        }
        foreach ($second as $point) {
            if ($this->geometry->distanceToRoute((float) $point[0], (float) $point[1], $first) <= self::CONNECTION_TOLERANCE_M) {
                return true;
            }
        }

        return false;
    }

    private function issue(string $severity, string $code, string $message, array $details = []): array
    {
        return compact('severity', 'code', 'message', 'details');
    }
}
