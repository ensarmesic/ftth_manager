<?php

namespace App\Services\LargePlanner;

use App\Models\Project;

class OdfProposalService
{
    public function __construct(private readonly NetworkRouteProposalService $routes) {}

    public function propose(Project $project, array $graph, array $placement): array
    {
        $settings = $project->largePlannerSetting()->firstOrFail();
        if (! $settings->propose_odfs) {
            return ['enabled' => false, 'odfs' => [], 'primary_routes' => [], 'warnings' => [], 'summary' => ['proposed_odfs' => 0, 'primary_routes' => 0]];
        }

        $capacity = (int) $settings->odf_capacity;
        $groups = collect($placement['odos'])->groupBy('component')->sortKeys();
        $proposals = [];
        foreach ($groups as $odos) {
            $sorted = $odos->sortBy(fn (array $odo) => [$odo['point'][0], $odo['point'][1], $odo['key']])->values();
            foreach ($sorted->chunk($capacity) as $chunk) {
                $items = $chunk->values()->all();
                $candidate = $items[(int) floor((count($items) - 1) / 2)];
                $proposals[] = [
                    'key' => 'odf-'.str_pad((string) (count($proposals) + 1), 4, '0', STR_PAD_LEFT),
                    'provisional_name' => 'ODF-P-'.str_pad((string) (count($proposals) + 1), 4, '0', STR_PAD_LEFT),
                    'point' => $candidate['point'],
                    'corridor_id' => $candidate['corridor_id'],
                    'component' => $candidate['component'],
                    'capacity' => $capacity,
                    'occupancy' => count($items),
                    'odo_keys' => array_column($items, 'key'),
                ];
            }
        }

        $existingSources = $project->odfs()->whereNotNull('latitude')->whereNotNull('longitude')->orderBy('id')->get()
            ->map(fn ($odf) => ['odf_id' => $odf->id, 'odf_key' => null, 'point' => [(float) $odf->latitude, (float) $odf->longitude]])->all();
        $primary = [];
        $warnings = [];
        foreach ($proposals as $index => $proposal) {
            $sources = $existingSources;
            if ($sources === [] && $index > 0) {
                $sources[] = ['odf_id' => null, 'odf_key' => $proposals[0]['key'], 'point' => $proposals[0]['point']];
            }
            if ($sources === []) {
                continue;
            }
            $best = null;
            $source = null;
            foreach ($sources as $candidate) {
                $path = $this->routes->pathThroughGraph($graph, $candidate['point'], $proposal['point']);
                if ($path !== null && ($best === null || $path['length_m'] < $best['length_m'])) {
                    $best = $path;
                    $source = $candidate;
                }
            }
            if ($best === null) {
                $warnings[] = ['code' => 'odf_without_primary_route', 'odf_key' => $proposal['key'], 'message' => "Za {$proposal['provisional_name']} nije pronađena primarna ruta."];

                continue;
            }
            $primary[] = [
                'key' => 'primary-'.$proposal['key'],
                'type' => 'primary',
                'from_odf_id' => $source['odf_id'],
                'from_odf_key' => $source['odf_key'],
                'to_odf_key' => $proposal['key'],
                'path' => $best['path'],
                'length_m' => $best['length_m'],
            ];
        }

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
}
