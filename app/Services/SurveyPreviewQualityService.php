<?php

namespace App\Services;

use Illuminate\Support\Collection;

class SurveyPreviewQualityService
{
    public function analyze(array $points, array $ducts): array
    {
        $duplicatePointNumbers = collect($points)->countBy('point_no')->filter(fn (int $count) => $count > 1)->keys()->values();
        $unrecognizedCodes = collect($points)->where('kind', 'other')->pluck('code')->filter()->unique()->values();
        $customerPointsWithoutCabinet = collect($points)->filter(fn (array $point) => ($point['microduct_type'] ?? null) === '10/8'
            && in_array($point['kind'], ['trench', 'sling'], true)
            && blank($point['zo_tag'] ?? null)
        )->pluck('point_no')->values();
        $terminalDucts = collect($ducts)->filter(fn (array $duct) => ($duct['prepared_sling'] ?? false) || isset($duct['_terminal_point']));
        $unreachableDucts = $terminalDucts->reject(fn (array $duct) => (bool) ($duct['cabinet_reached'] ?? false));
        $errors = collect()
            ->when($duplicatePointNumbers->isNotEmpty(), fn (Collection $items) => $items->push('Dupli brojevi tačaka: '.$duplicatePointNumbers->take(12)->join(', ').'.'))
            ->when($unrecognizedCodes->isNotEmpty(), fn (Collection $items) => $items->push($unrecognizedCodes->count().' opisa nije prepoznato.'))
            ->when($customerPointsWithoutCabinet->isNotEmpty(), fn (Collection $items) => $items->push($customerPointsWithoutCabinet->count().' korisničkih 10/8 tačaka nema -ZO oznaku.'))
            ->values();
        $warnings = collect()
            ->when($unreachableDucts->isNotEmpty(), fn (Collection $items) => $items->push($unreachableDucts->count().' korisničkih linija nema dokazanu putanju kroz rov do ODO-a; možeš je ručno ispraviti prije uvoza.'))
            ->values();

        return [
            'status' => $errors->isEmpty() ? 'ready' : 'blocked',
            'errors' => $errors->all(),
            'warnings' => $warnings->all(),
            'complete_drop_routes' => $terminalDucts->count() - $unreachableDucts->count(),
            'unreachable_drop_routes' => $unreachableDucts->count(),
            'duplicate_point_numbers' => $duplicatePointNumbers->all(),
            'customer_points_without_cabinet' => $customerPointsWithoutCabinet->all(),
            'unrecognized_codes' => $unrecognizedCodes->all(),
            'issue_points' => collect($points)->filter(fn (array $point) => $point['kind'] === 'other'
                || $duplicatePointNumbers->contains($point['point_no'])
                || $customerPointsWithoutCabinet->contains($point['point_no'])
            )->map(fn (array $point) => [
                'point_no' => $point['point_no'], 'code' => $point['code'],
                'lat' => $point['lat'], 'lng' => $point['lng'],
            ])->values()->all(),
        ];
    }
}
