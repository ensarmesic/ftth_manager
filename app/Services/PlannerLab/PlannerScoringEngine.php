<?php

namespace App\Services\PlannerLab;

class PlannerScoringEngine
{
    public function score(array $plan): array
    {
        $cabinets   = $plan['planned_cabinets']  ?? [];
        $routes     = $plan['planned_routes']    ?? [];
        $branches   = $plan['planned_branches']  ?? [];
        $assignments= $plan['house_assignments'] ?? [];

        $score   = 100;
        $reasons = [];

        // Popunjenost ODO-a
        $houseCounts = array_column($cabinets, 'house_count');
        if (! empty($houseCounts)) {
            $avgFill = round(array_sum($houseCounts) / count($houseCounts), 1);
            if ($avgFill < 6) {
                $score -= 10;
                $reasons[] = "Prosječna popunjenost ODO je {$avgFill} kuća (niska)";
            } else {
                $reasons[] = "Prosječna popunjenost ODO je {$avgFill} kuća";
            }
        }

        // Kuće bez ormarića
        if (isset($plan['summary']['unassigned_houses']) && $plan['summary']['unassigned_houses'] > 0) {
            $score -= min(20, $plan['summary']['unassigned_houses'] * 2);
            $reasons[] = $plan['summary']['unassigned_houses'] . ' kuća nije dodijeljeno niti jednom ormariu';
        }

        // Dužina trasa
        $totalRouteLen = array_sum(array_column($routes, 'length_m'));
        if ($totalRouteLen > 5000) {
            $score -= 10;
            $reasons[] = 'Ukupna dužina trasa prelazi 5 km';
        }

        // Fallback rute
        $straightRoutes = count(array_filter($routes, fn ($r) => ($r['source'] ?? '') === 'straight'));
        if ($straightRoutes > 0) {
            $score -= min(15, $straightRoutes * 3);
            $reasons[] = "{$straightRoutes} dionica ne prati cestu (fallback ravna linija)";
        }

        // Drop trase
        $drops    = $plan['planned_drops'] ?? [];
        $maxDrop  = (float) ($plan['debug']['options']['maxDropDistance'] ?? 150);
        if (! empty($drops)) {
            $dropLengths = array_column($drops, 'length_m');
            $avgDrop     = round(array_sum($dropLengths) / count($dropLengths));
            $longDrops   = count(array_filter($drops, fn ($d) => $d['length_m'] > $maxDrop));

            $reasons[] = 'Prosjecna duzina dropa: ' . $avgDrop . ' m';

            if ($longDrops > 0) {
                $score    -= min(10, $longDrops * 2);
                $reasons[] = "{$longDrops} drop(ova) prelazi maks. duzinu ({$maxDrop} m)";
            }

            $straightDrops = count(array_filter($drops, fn ($d) => ($d['source'] ?? '') === 'straight'));
            if ($straightDrops > 0 && count($drops) > 0) {
                $pct = round($straightDrops / count($drops) * 100);
                if ($pct > 50) {
                    $score    -= 5;
                    $reasons[] = "{$pct}% dropova ne prati trasu (ravna linija)";
                }
            }
        }

        // Branch summary
        $branchSummary = [];
        foreach ($branches as $branch) {
            $branchSummary[] = [
                'temp_id'        => $branch['temp_id'],
                'name'           => $branch['name'],
                'cabinet_count'  => $branch['cabinet_count'],
                'length_m'       => $branch['length_m'] ?? 0,
                'straight_count' => $branch['straight_count'] ?? 0,
            ];
        }

        $fallbackBranches = count(array_filter($branchSummary, fn ($b) => $b['straight_count'] > 0));
        if ($fallbackBranches > 0) {
            $reasons[] = "{$fallbackBranches} krakova ima dionice koje ne prate cestu";
        }

        usort($branchSummary, fn ($a, $b) => $b['length_m'] <=> $a['length_m']);

        $score = max(0, min(100, $score));

        return [
            'score'          => $score,
            'grade'          => $this->grade($score),
            'reasons'        => $reasons,
            'branch_summary' => $branchSummary,
        ];
    }

    private function grade(int $score): string
    {
        if ($score >= 90) return 'excellent';
        if ($score >= 75) return 'good';
        if ($score >= 55) return 'fair';
        return 'poor';
    }
}
