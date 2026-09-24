<?php

namespace App\Services;

use App\Models\Project;

class LargePlannerReadinessService
{
    public function __construct(private readonly LargePlannerInputValidationService $inputValidator) {}

    public function assess(Project $project): array
    {
        $project->loadMissing(['largePlannerSetting', 'largePlannerConstraints']);
        $validation = $this->inputValidator->validate($project);
        $issues = collect($validation['issues'])->keyBy('code');
        $settings = $project->largePlannerSetting;

        $checks = [
            $this->check('houses', 'Kuće s koordinatama', ! $issues->has('houses_without_coordinates') && $validation['summary']['houses'] > 0,
                $validation['summary']['houses'] > 0 ? $issues->get('houses_without_coordinates')['message'] ?? "Evidentirano kuća: {$validation['summary']['houses']}." : 'Nije evidentirana nijedna kuća.'),
            $this->check('corridors', 'Dozvoljeni koridori', ! $issues->has('missing_corridors') && $validation['summary']['corridors'] > 0,
                $issues->get('missing_corridors')['message'] ?? "Klasifikovano koridora: {$validation['summary']['corridors']}."),
            $this->check('connectivity', 'Povezanost koridora', ! $issues->has('disconnected_corridors'),
                $issues->get('disconnected_corridors')['message'] ?? 'Koridori čine jednu povezanu cjelinu.'),
            $this->check('house_distance', 'Dostupnost kuća', ! $issues->has('houses_too_far_from_corridor') && $settings?->max_drop_length_m !== null,
                $issues->get('houses_too_far_from_corridor')['message'] ?? ($settings?->max_drop_length_m === null ? 'Maksimalni drop nije određen.' : 'Sve kuće su unutar dozvoljene udaljenosti.')),
            $this->check('odo_capacity', 'ODO kapacitet', $settings?->odo_capacity !== null,
                $settings?->odo_capacity !== null ? "Kapacitet: {$settings->odo_capacity}." : 'ODO kapacitet nije određen.'),
            $this->check('fiber_reserve', 'Rezerva vlakana', $settings?->fiber_reserve_percent !== null,
                $settings?->fiber_reserve_percent !== null ? "Rezerva: {$settings->fiber_reserve_percent}%." : 'Rezerva vlakana nije određena.'),
            $this->check('optimization_goal', 'Cilj optimizacije', $settings?->optimization_goal !== null,
                $settings?->optimization_goal !== null ? 'Cilj optimizacije je odabran.' : 'Cilj optimizacije nije odabran.'),
            $this->check('odf_capacity', 'Kapacitet predloženog ODF-a', ! $settings?->propose_odfs || $settings?->odf_capacity !== null,
                ! $settings?->propose_odfs ? 'Automatski prijedlog ODF-a nije uključen.' : ($settings?->odf_capacity !== null ? "Kapacitet: {$settings->odf_capacity} ODO-a." : 'Kapacitet ODF-a nije određen.')),
            $this->check('constraints', 'Posebna ograničenja', true, $this->constraintSummary($project)),
        ];

        return [
            'ready' => collect($checks)->where('blocking', true)->every(fn (array $check) => $check['status'] === 'ready'),
            'checks' => $checks,
            'summary' => [
                'ready' => collect($checks)->where('status', 'ready')->count(),
                'blocking' => collect($checks)->where('status', 'blocked')->count(),
                'total' => count($checks),
            ],
        ];
    }

    private function check(string $code, string $label, bool $ready, string $message, bool $blocking = true): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'status' => $ready ? 'ready' : 'blocked',
            'blocking' => $blocking,
            'message' => $message,
        ];
    }

    private function constraintSummary(Project $project): string
    {
        $waypoints = $project->largePlannerConstraints->where('type', 'required_waypoint')->count();
        $restricted = $project->largePlannerConstraints->where('type', 'restricted_area')->count();

        return "Obaveznih tačaka: {$waypoints}; zabranjenih područja: {$restricted}.";
    }
}
