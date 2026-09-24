<?php

namespace App\Services;

use App\Models\House;
use App\Models\LargePlannerZone;
use InvalidArgumentException;

class LargePlannerZoneAssignmentService
{
    public function assign(LargePlannerZone $zone, array $houseIds): int
    {
        if ($zone->isLocked()) {
            throw new InvalidArgumentException('Zaključanoj zoni nije moguće mijenjati kuće.');
        }

        $houses = House::query()->whereIn('id', $houseIds)->get();
        if ($houses->count() !== count(array_unique($houseIds)) || $houses->contains(fn (House $house) => $house->project_id !== $zone->project_id)) {
            throw new InvalidArgumentException('Sve kuće moraju pripadati istom projektu kao zona.');
        }
        $lockedZoneIds = $houses->pluck('large_planner_zone_id')->filter()->unique();
        if ($lockedZoneIds->isNotEmpty() && LargePlannerZone::whereIn('id', $lockedZoneIds)->where('status', 'locked')->exists()) {
            throw new InvalidArgumentException('Kuća iz zaključane zone ne može se premjestiti.');
        }

        return House::whereIn('id', $houseIds)->update(['large_planner_zone_id' => $zone->id]);
    }

    public function assignUnassignedInside(LargePlannerZone $zone): int
    {
        $houses = House::query()->where('project_id', $zone->project_id)
            ->whereNull('large_planner_zone_id')->whereNotNull('latitude')->whereNotNull('longitude')->get();
        $ids = $houses->filter(fn (House $house) => $this->pointInPolygon(
            [(float) $house->latitude, (float) $house->longitude],
            $zone->geometry,
        ))->pluck('id')->all();

        return $ids === [] ? 0 : $this->assign($zone, $ids);
    }

    private function pointInPolygon(array $point, array $polygon): bool
    {
        $inside = false;
        for ($i = 0, $j = count($polygon) - 1; $i < count($polygon); $j = $i++) {
            $xi = (float) $polygon[$i][1];
            $yi = (float) $polygon[$i][0];
            $xj = (float) $polygon[$j][1];
            $yj = (float) $polygon[$j][0];
            if ((($yi > $point[0]) !== ($yj > $point[0]))
                && ($point[1] < (($xj - $xi) * ($point[0] - $yi) / (($yj - $yi) ?: 0.000000001)) + $xi)) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
