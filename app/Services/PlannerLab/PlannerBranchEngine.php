<?php

namespace App\Services\PlannerLab;

class PlannerBranchEngine
{
    private int $counter = 0;

    public function buildBranches(array $odf, array $cabinets, array $options = []): array
    {
        $sectorCount = max(2, (int) ($options['branchSectorCount'] ?? 8));

        if (empty($cabinets)) {
            return [];
        }

        $sectors = $this->assignCabinetsToSectors($odf, $cabinets, $sectorCount);

        $branches = [];
        foreach ($sectors as $sectorIndex => $sectorCabinets) {
            if (empty($sectorCabinets)) {
                continue;
            }

            $this->counter++;
            $sorted = $this->sortCabinetsInsideBranch($odf, $sectorCabinets);

            $branches[] = [
                'temp_id'       => 'branch-temp-' . $this->counter,
                'name'          => $this->branchName($this->counter),
                'sector'        => $sectorIndex,
                'cabinets'      => $sorted,
                'cabinet_count' => count($sorted),
                'source'        => 'planner_lab',
                'temporary'     => true,
            ];
        }

        return $branches;
    }

    protected function assignCabinetsToSectors(array $odf, array $cabinets, int $sectorCount): array
    {
        $sectors = array_fill(0, $sectorCount, []);
        $sectorAngle = 360.0 / $sectorCount;

        foreach ($cabinets as $cabinet) {
            $angle = $this->bearingDeg(
                $odf['latitude'], $odf['longitude'],
                $cabinet['lat'], $cabinet['lng']
            );
            $sectorIndex = (int) floor($angle / $sectorAngle) % $sectorCount;
            $sectors[$sectorIndex][] = $cabinet;
        }

        return $sectors;
    }

    protected function sortCabinetsInsideBranch(array $odf, array $cabinets): array
    {
        usort($cabinets, function ($a, $b) use ($odf) {
            $dA = PlannerGeometry::haversine($odf['latitude'], $odf['longitude'], $a['lat'], $a['lng']);
            $dB = PlannerGeometry::haversine($odf['latitude'], $odf['longitude'], $b['lat'], $b['lng']);
            return $dA <=> $dB;
        });

        return $cabinets;
    }

    protected function branchName(int $index): string
    {
        return sprintf('LAB-KRAK-%02d', $index);
    }

    private function bearingDeg(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $φ1 = deg2rad($lat1);
        $φ2 = deg2rad($lat2);
        $Δλ = deg2rad($lng2 - $lng1);

        $x = sin($Δλ) * cos($φ2);
        $y = cos($φ1) * sin($φ2) - sin($φ1) * cos($φ2) * cos($Δλ);

        return fmod(rad2deg(atan2($x, $y)) + 360, 360);
    }

    public function reset(): void
    {
        $this->counter = 0;
    }
}
