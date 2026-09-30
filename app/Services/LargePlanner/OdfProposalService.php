<?php

namespace App\Services\LargePlanner;

use App\Models\Project;
use App\Services\GeometryService;

class OdfProposalService
{
    private const MIN_ODO_DISTANCE_M = 5.0;

    private const ODO_DISTANCE_SELECTION_MARGIN_M = 0.5;

    public function __construct(
        private readonly NetworkRouteProposalService $routes,
        private readonly GeometryService $geometry,
    ) {}

    public function propose(Project $project, array $graph, array $placement): array
    {
        $settings = $project->largePlannerSetting()->firstOrFail();
        $manualOdfs = $project->odfs()
            ->whereNull('import_batch')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get();
        if ($manualOdfs->isNotEmpty()) {
            [$primary, $warnings] = $this->minimumPrimaryNetwork($graph, $manualOdfs->map(fn ($odf) => [
                'odf_id' => $odf->id,
                'odf_key' => null,
                'name' => $odf->name,
                'point' => [(float) $odf->latitude, (float) $odf->longitude],
            ])->values()->all());

            return [
                'enabled' => false,
                'odfs' => [],
                'primary_routes' => $primary,
                'warnings' => $warnings,
                'summary' => ['proposed_odfs' => 0, 'primary_routes' => count($primary), 'primary_length_m' => array_sum(array_column($primary, 'length_m')), 'existing_source_odfs' => $manualOdfs->count()],
            ];
        }
        if (! $settings->propose_odfs) {
            return ['enabled' => false, 'odfs' => [], 'primary_routes' => [], 'warnings' => [], 'summary' => ['proposed_odfs' => 0, 'primary_routes' => 0]];
        }

        $maxOdos = (int) $settings->odf_capacity;
        $groups = collect($placement['odos'])->groupBy('component')->sortKeys();
        $proposals = [];
        foreach ($groups as $odos) {
            $sorted = $odos->sortBy(fn (array $odo) => [$odo['point'][0], $odo['point'][1], $odo['key']])->values();
            foreach ($sorted->chunk($maxOdos) as $chunk) {
                $items = $chunk->values()->all();
                $candidate = $items[(int) floor((count($items) - 1) / 2)];
                $odfLocation = $this->optimizedLocation($graph, $candidate['point'], $items, $placement['odos'], (int) $candidate['component']);
                $proposals[] = [
                    'key' => 'odf-'.str_pad((string) (count($proposals) + 1), 4, '0', STR_PAD_LEFT),
                    'provisional_name' => 'ODF-P-'.str_pad((string) (count($proposals) + 1), 4, '0', STR_PAD_LEFT),
                    'point' => $odfLocation['point'],
                    'corridor_id' => $odfLocation['corridor_id'],
                    'component' => $candidate['component'],
                    'capacity' => 144,
                    'fiber_capacity' => 144,
                    'max_odos' => $maxOdos,
                    'occupancy' => count($items),
                    'odo_keys' => array_column($items, 'key'),
                    'selection_reason' => 'Najmanja ukupna dužina sekundarnih trasa uz sigurnosni razmak od ODO-a.',
                    'secondary_cost_m' => $odfLocation['network_cost_m'],
                ];
            }
        }

        [$primary, $warnings] = $this->minimumPrimaryNetwork($graph, collect($proposals)->map(fn (array $odf) => [
            'odf_id' => null,
            'odf_key' => $odf['key'],
            'name' => $odf['provisional_name'],
            'point' => $odf['point'],
        ])->all());

        return [
            'enabled' => true,
            'odfs' => $proposals,
            'primary_routes' => $primary,
            'warnings' => $warnings,
            'summary' => [
                'proposed_odfs' => count($proposals),
                'primary_routes' => count($primary),
                'primary_length_m' => array_sum(array_column($primary, 'length_m')),
            ],
        ];
    }

    public function reconnect(Project $project, array $graph, array $odfPlan): array
    {
        $sources = ($odfPlan['enabled'] ?? false)
            ? collect($odfPlan['odfs'] ?? [])->map(fn (array $odf) => ['odf_id' => null, 'odf_key' => $odf['key'], 'name' => $odf['provisional_name'], 'point' => $odf['point']])->all()
            : $project->odfs()->whereNull('import_batch')->whereNotNull('latitude')->whereNotNull('longitude')->orderBy('id')->get()
                ->map(fn ($odf) => ['odf_id' => $odf->id, 'odf_key' => null, 'name' => $odf->name, 'point' => [(float) $odf->latitude, (float) $odf->longitude]])->all();
        [$primary, $networkWarnings] = $this->minimumPrimaryNetwork($graph, $sources);
        $odfPlan['primary_routes'] = $primary;
        $odfPlan['warnings'] = collect($odfPlan['warnings'] ?? [])
            ->reject(fn (array $warning) => in_array($warning['code'] ?? '', ['odf_without_primary_route', 'odf_network_disconnected'], true))
            ->concat($networkWarnings)->values()->all();
        $odfPlan['summary']['primary_routes'] = count($primary);
        $odfPlan['summary']['primary_length_m'] = array_sum(array_column($primary, 'length_m'));

        return $odfPlan;
    }

    private function minimumPrimaryNetwork(array $graph, array $odfs): array
    {
        if (count($odfs) < 2) {
            return [[], []];
        }
        $edges = [];
        for ($left = 0; $left < count($odfs); $left++) {
            for ($right = $left + 1; $right < count($odfs); $right++) {
                $route = $this->routes->pathThroughGraph($graph, $odfs[$left]['point'], $odfs[$right]['point'], ['main', 'primary']);
                if ($route !== null) {
                    $edges[] = ['left' => $left, 'right' => $right, 'route' => $route];
                }
            }
        }
        usort($edges, fn (array $a, array $b) => $a['route']['length_m'] <=> $b['route']['length_m']
            ?: $a['left'] <=> $b['left'] ?: $a['right'] <=> $b['right']);
        $parent = array_keys($odfs);
        $find = function (int $node) use (&$find, &$parent): int {
            return $parent[$node] === $node ? $node : ($parent[$node] = $find($parent[$node]));
        };
        $selected = [];
        foreach ($edges as $edge) {
            $leftRoot = $find($edge['left']);
            $rightRoot = $find($edge['right']);
            if ($leftRoot === $rightRoot) {
                continue;
            }
            $parent[$rightRoot] = $leftRoot;
            $selected[] = $edge;
            if (count($selected) === count($odfs) - 1) {
                break;
            }
        }
        $primary = collect($selected)->values()->map(function (array $edge, int $index) use ($odfs): array {
            $from = $odfs[$edge['left']];
            $to = $odfs[$edge['right']];
            $path = $edge['route']['path'];
            if (count($path) < 2) {
                $path = [$from['point'], $to['point']];
            }

            return [
                'key' => 'primary-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'type' => 'primary',
                'from_odf_id' => $from['odf_id'],
                'from_odf_key' => $from['odf_key'],
                'to_odf_id' => $to['odf_id'],
                'to_odf_key' => $to['odf_key'],
                'path' => $path,
                'length_m' => $edge['route']['length_m'],
            ];
        })->all();
        $warnings = count($primary) === count($odfs) - 1 ? [] : [[
            'code' => 'odf_network_disconnected',
            'message' => 'Svi ODF-ovi se ne mogu povezati primarnim krakovima kroz dozvoljeni glavni rov.',
        ]];

        return [$primary, $warnings];
    }

    private function optimizedLocation(array $graph, array $preferred, array $assignedOdos, array $allOdos, int $component): array
    {
        $componentNodes = array_flip($graph['components'][$component] ?? []);
        $candidates = [];
        foreach ($graph['edges'] as $from => $targets) {
            if (! isset($componentNodes[$from])) {
                continue;
            }
            foreach ($targets as $to => $edge) {
                if (strcmp((string) $from, (string) $to) >= 0 || ! isset($componentNodes[$to]) || $edge['corridor_id'] === null
                    || ! in_array($edge['corridor_type'], ['main', 'primary'], true)) {
                    continue;
                }
                $start = $graph['nodes'][$from];
                $end = $graph['nodes'][$to];
                $steps = max(1, (int) ceil((float) $edge['weight_m'] / 10));
                for ($step = 0; $step <= $steps; $step++) {
                    $ratio = $step / $steps;
                    $point = [
                        round($start[0] + (($end[0] - $start[0]) * $ratio), 7),
                        round($start[1] + (($end[1] - $start[1]) * $ratio), 7),
                    ];
                    $clearance = collect($allOdos)->min(fn (array $odo) => $this->geometry->distanceBetweenPoints($point, $odo['point']));
                    $candidates[] = [
                        'point' => $point,
                        'corridor_id' => $edge['corridor_id'],
                        'clearance' => (float) $clearance,
                        'preferred_distance' => $this->geometry->distanceBetweenPoints($point, $preferred),
                        'direct_cost_m' => collect($assignedOdos)->sum(fn (array $odo) => $this->geometry->distanceBetweenPoints($point, $odo['point'])),
                    ];
                }
            }
        }

        $shortlist = collect($candidates)
            ->filter(fn (array $candidate) => $candidate['clearance'] >= self::MIN_ODO_DISTANCE_M + self::ODO_DISTANCE_SELECTION_MARGIN_M)
            ->sortBy(fn (array $candidate) => [$candidate['direct_cost_m'], $candidate['preferred_distance']])
            ->take(40)
            ->map(function (array $candidate) use ($graph, $assignedOdos): array {
                $paths = collect($assignedOdos)->map(fn (array $odo) => $this->routes->pathThroughGraph($graph, $candidate['point'], $odo['point']));
                $candidate['network_cost_m'] = $paths->contains(null)
                    ? INF
                    : $paths->sum(fn (array $path) => $path['length_m']);

                return $candidate;
            })
            ->filter(fn (array $candidate) => is_finite($candidate['network_cost_m']))
            ->sortBy(fn (array $candidate) => [$candidate['network_cost_m'], $candidate['direct_cost_m'], -$candidate['clearance']]);
        $valid = $shortlist->first();

        // A valid project corridor normally provides many candidates. If a tiny
        // corridor is completely occupied, return its point with greatest clearance;
        // final validation will block confirmation until the collision is resolved.
        $fallback = collect($candidates)->sortByDesc('clearance')->first();
        if ($fallback !== null) {
            $fallback['network_cost_m'] = $fallback['direct_cost_m'];
        }

        return $valid ?? $fallback ?? [
            'point' => $preferred,
            'corridor_id' => null,
            'network_cost_m' => 0,
        ];
    }
}
