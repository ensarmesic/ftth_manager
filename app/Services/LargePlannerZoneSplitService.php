<?php

namespace App\Services;

use App\Models\House;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LargePlannerZoneSplitService
{
    public function createInitialZones(Project $project, int $targetSize): Collection
    {
        if ($project->largePlannerZones()->exists()) {
            throw new InvalidArgumentException('Automatska podjela je dozvoljena samo dok projekat nema zona.');
        }

        $houses = $project->houses()->whereNotNull('latitude')->whereNotNull('longitude')->orderBy('id')->get();
        if ($houses->isEmpty()) {
            throw new InvalidArgumentException('Nema kuća s koordinatama za automatsku podjelu.');
        }

        $groups = $this->split($houses, $targetSize);

        return DB::transaction(function () use ($project, $groups): Collection {
            return collect($groups)->values()->map(function (Collection $houses, int $index) use ($project) {
                $zone = $project->largePlannerZones()->create([
                    'name' => 'Zona '.($index + 1),
                    'geometry' => $this->boundingPolygon($houses),
                    'status' => 'draft',
                ]);
                House::whereIn('id', $houses->pluck('id'))->update(['large_planner_zone_id' => $zone->id]);
                $zone->setAttribute('houses_count', $houses->count());

                return $zone;
            });
        });
    }

    private function split(Collection $houses, int $targetSize): array
    {
        if ($houses->count() <= $targetSize) {
            return [$houses];
        }

        $latSpread = (float) $houses->max('latitude') - (float) $houses->min('latitude');
        $lngSpread = (float) $houses->max('longitude') - (float) $houses->min('longitude');
        $axis = $lngSpread >= $latSpread ? 'longitude' : 'latitude';
        $sorted = $houses->sortBy(fn ($house) => [(float) $house->{$axis}, $house->id])->values();
        $middle = (int) ceil($sorted->count() / 2);

        return [
            ...$this->split($sorted->take($middle)->values(), $targetSize),
            ...$this->split($sorted->slice($middle)->values(), $targetSize),
        ];
    }

    private function boundingPolygon(Collection $houses): array
    {
        $minLat = (float) $houses->min('latitude');
        $maxLat = (float) $houses->max('latitude');
        $minLng = (float) $houses->min('longitude');
        $maxLng = (float) $houses->max('longitude');
        $latPadding = max(($maxLat - $minLat) * 0.08, 0.00009);
        $lngPadding = max(($maxLng - $minLng) * 0.08, 0.00012);

        return [
            [$minLat - $latPadding, $minLng - $lngPadding],
            [$minLat - $latPadding, $maxLng + $lngPadding],
            [$maxLat + $latPadding, $maxLng + $lngPadding],
            [$maxLat + $latPadding, $minLng - $lngPadding],
        ];
    }
}
