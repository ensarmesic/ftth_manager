<?php

namespace App\Services\PlannerLab;

class PlannerCabinetEngine
{
    private int $counter = 0;

    public function groupHouses(array $houses, array $options): array
    {
        $max     = $options['maxHousesPerCabinet'] ?? 12;
        $maxDrop = (float) ($options['maxDropDistance'] ?? 150);
        $unassigned = array_values(array_filter($houses, fn ($h) => empty($h['cabinet_id'])));

        if (empty($unassigned)) {
            return [];
        }

        return $this->clusterByDistance($unassigned, $max, $maxDrop);
    }

    private function clusterByDistance(array $houses, int $maxSize, float $maxDrop): array
    {
        $clusters = [];
        $assigned = [];

        foreach ($houses as $i => $house) {
            if (isset($assigned[$i])) {
                continue;
            }

            $cluster    = [$house];
            $assigned[$i] = true;

            foreach ($houses as $j => $other) {
                if (isset($assigned[$j]) || count($cluster) >= $maxSize) {
                    continue;
                }
                $dist = PlannerGeometry::haversine(
                    $house['latitude'], $house['longitude'],
                    $other['latitude'], $other['longitude']
                );
                if ($dist <= $maxDrop) {
                    $cluster[]    = $other;
                    $assigned[$j] = true;
                }
            }

            $clusters[] = $cluster;
        }

        return $clusters;
    }

    public function placeCabinet(array $group): array
    {
        $this->counter++;
        $pos = PlannerGeometry::medoid($group);

        return [
            'temp_id' => 'cab-temp-' . $this->counter,
            'name' => sprintf('LAB-ODO-%03d', $this->counter),
            'lat' => $pos['lat'],
            'lng' => $pos['lng'],
            'house_ids' => array_column($group, 'id'),
            'splitters' => $this->splitterCount(count($group)),
            'house_count' => count($group),
            'source' => 'planner_lab',
            'temporary' => true,
            'status' => 'preview',
        ];
    }

    protected function splitterCount(int $houseCount): int
    {
        if ($houseCount <= 4) return 1;
        if ($houseCount <= 8) return 2;
        return 3;
    }

    public function reset(): void
    {
        $this->counter = 0;
    }
}
