<?php

namespace App\Services;

use App\Models\Project;
use App\Support\FiberColorCode;
use Illuminate\Support\Collection;

class FiberPlanService
{
    private const PON_PROFILES = [
        'gpon_b_plus' => ['label' => 'GPON B+', 'standard' => 'ITU-T G.984.2', 'min' => 13.0, 'max' => 28.0, 'downstream_nm' => 1490, 'upstream_nm' => 1310],
        'gpon_c_plus' => ['label' => 'GPON C+', 'standard' => 'ITU-T G.984.2', 'min' => 17.0, 'max' => 32.0, 'downstream_nm' => 1490, 'upstream_nm' => 1310],
        'gpon_d' => ['label' => 'GPON D', 'standard' => 'ITU-T G.984.2', 'min' => 20.0, 'max' => 35.0, 'downstream_nm' => 1490, 'upstream_nm' => 1310],
        'xgs_n1' => ['label' => 'XGS-PON N1', 'standard' => 'ITU-T G.9807.1', 'min' => 14.0, 'max' => 29.0, 'downstream_nm' => 1577, 'upstream_nm' => 1270],
        'xgs_n2' => ['label' => 'XGS-PON N2', 'standard' => 'ITU-T G.9807.1', 'min' => 16.0, 'max' => 31.0, 'downstream_nm' => 1577, 'upstream_nm' => 1270],
        'xgs_e1' => ['label' => 'XGS-PON E1', 'standard' => 'ITU-T G.9807.1', 'min' => 18.0, 'max' => 33.0, 'downstream_nm' => 1577, 'upstream_nm' => 1270],
        'xgs_e2' => ['label' => 'XGS-PON E2', 'standard' => 'ITU-T G.9807.1', 'min' => 20.0, 'max' => 35.0, 'downstream_nm' => 1577, 'upstream_nm' => 1270],
    ];

    public function build(Project $project): array
    {
        $project->loadMissing(['odfs', 'branches.route', 'cabinets.houses', 'cabinets.branch.route', 'routes', 'fiberSplices']);
        $fibersPerTube = str_ends_with($project->fiber_layout ?? '6x24', 'x12') ? 12 : 24;
        $reservePerTube = min((int) ($project->fiber_reserve_per_tube ?? 0), $fibersPerTube - 1);
        $nextFiber = 1;
        $allocations = [];

        $allocate = function (int $count) use (&$nextFiber, $fibersPerTube, $reservePerTube): array {
            $position = (($nextFiber - 1) % $fibersPerTube) + 1;
            $usable = $fibersPerTube - $reservePerTube;
            if ($position > $usable || $position + $count - 1 > $usable) {
                $nextFiber += $fibersPerTube - $position + 1;
            }
            $range = ['from' => $nextFiber, 'to' => $nextFiber + $count - 1, 'count' => $count];
            $nextFiber += $count;

            return $range;
        };

        $cabinets = $project->cabinets->sortBy(fn ($cabinet) => sprintf('%08d|%08d|%s', $cabinet->branch?->sort_order ?? 999999, $cabinet->branch_order ?? 999999, $cabinet->name));
        foreach ($cabinets as $cabinet) {
            if (! $cabinet->odf_id || ! $cabinet->branch_id) {
                continue;
            }
            $splitters = max(1, (int) ceil($cabinet->houses->count() / max(1, (int) $cabinet->ports_per_splitter)));
            $allocations[$cabinet->id] = $allocate($splitters);
        }

        $claimed = collect($allocations)->flatMap(fn (array $range) => range($range['from'], $range['to']));
        $duplicates = $claimed->duplicates()->unique()->values();
        $capacity = max(1, (int) ($project->odfs->max('fiber_capacity') ?: 144));
        $usedTo = (int) (collect($allocations)->max('to') ?: 0);
        $profile = self::PON_PROFILES[$project->pon_profile ?? 'gpon_b_plus'] ?? self::PON_PROFILES['gpon_b_plus'];
        $budgetLimit = $profile['max'];
        $engineeringMargin = (float) ($project->engineering_margin_db ?? 3);
        $connections = $cabinets->map(function ($cabinet) use ($allocations, $fibersPerTube, $project, $budgetLimit, $profile, $engineeringMargin): array {
            $range = $allocations[$cabinet->id] ?? null;
            $routeLengthKm = ((float) ($cabinet->branch?->route?->fiber_length_m ?: $cabinet->branch?->route?->duct_length_m)) / 1000;
            $splitterRatio = max(2, (int) $cabinet->ports_per_splitter);
            $feederSplitterRatio = max(1, (int) ($project->feeder_splitter_ratio ?? 1));
            $accessSplitterLoss = $this->splitterLoss($splitterRatio);
            $feederSplitterLoss = $this->splitterLoss($feederSplitterRatio);
            $splitterLoss = $accessSplitterLoss + $feederSplitterLoss;
            $splices = $project->fiberSplices->where('cabinet_id', $cabinet->id);
            $spliceCount = $splices->isNotEmpty() ? $splices->count() : (int) ($project->planned_splice_count ?? 2);
            $spliceLoss = $splices->isNotEmpty() ? (float) $splices->sum('loss_db') : $spliceCount * (float) ($project->splice_allowance_db ?? .1);
            $connectorCount = (int) ($project->connector_count ?? 2);
            $connectorLoss = $connectorCount * (float) ($project->connector_loss_db ?? .5);
            $additionalPassiveLoss = (float) ($project->additional_passive_loss_db ?? 0);
            $downstreamCoefficient = $profile['downstream_nm'] === 1490 ? (float) ($project->fiber_attenuation_1490_db_km ?? .3) : (float) ($project->fiber_attenuation_1577_db_km ?? .3);
            $upstreamCoefficient = (float) ($project->fiber_attenuation_1310_db_km ?? .4);
            $downstreamLoss = ($routeLengthKm * $downstreamCoefficient) + $splitterLoss + $spliceLoss + $connectorLoss + $additionalPassiveLoss;
            $upstreamLoss = ($routeLengthKm * $upstreamCoefficient) + $splitterLoss + $spliceLoss + $connectorLoss + $additionalPassiveLoss;
            $downstreamRx = $project->olt_tx_power_dbm !== null ? round((float) $project->olt_tx_power_dbm - $downstreamLoss, 2) : null;
            $upstreamRx = $project->onu_tx_power_dbm !== null ? round((float) $project->onu_tx_power_dbm - $upstreamLoss, 2) : null;
            $downstreamReceiverMargin = $downstreamRx !== null && $project->onu_rx_sensitivity_dbm !== null ? round($downstreamRx - (float) $project->onu_rx_sensitivity_dbm, 2) : null;
            $upstreamReceiverMargin = $upstreamRx !== null && $project->olt_rx_sensitivity_dbm !== null ? round($upstreamRx - (float) $project->olt_rx_sensitivity_dbm, 2) : null;
            $loss = round(max($downstreamLoss, $upstreamLoss), 2);
            $designLoss = round($loss + $engineeringMargin, 2);
            $headroom = round($budgetLimit - $designLoss, 2);
            $belowMinimum = $loss < $profile['min'];
            $budgetStatus = ($belowMinimum || $designLoss > $budgetLimit) ? 'error' : ($headroom < 1 ? 'warning' : 'ok');

            return [
                'cabinet_id' => $cabinet->id, 'cabinet' => $cabinet->name, 'odf_id' => $cabinet->odf_id,
                'branch' => $cabinet->branch?->name, 'fiber_from' => $range['from'] ?? null, 'fiber_to' => $range['to'] ?? null,
                'tube' => $range ? FiberColorCode::describe($range['from'], $fibersPerTube, $project->fiber_color_standard ?? 'telcordia')['tube_number'] : null,
                'houses' => $cabinet->houses->count(), 'capacity' => $cabinet->capacity, 'route_km' => round($routeLengthKm, 3),
                'splitter_ratio' => $feederSplitterRatio > 1 ? "1:{$feederSplitterRatio} × 1:{$splitterRatio}" : "1:{$splitterRatio}", 'access_splitter_ratio' => "1:{$splitterRatio}", 'feeder_splitter_ratio' => $feederSplitterRatio > 1 ? "1:{$feederSplitterRatio}" : 'Nema', 'splice_loss_db' => round($spliceLoss, 2), 'loss_db' => $loss,
                'margin_db' => round($budgetLimit - $loss, 2), 'engineering_margin_db' => $engineeringMargin, 'design_loss_db' => $designLoss,
                'headroom_db' => $headroom, 'budget_status' => $budgetStatus, 'below_minimum' => $belowMinimum,
                'downstream_nm' => $profile['downstream_nm'], 'downstream_loss_db' => round($downstreamLoss, 2),
                'upstream_nm' => $profile['upstream_nm'], 'upstream_loss_db' => round($upstreamLoss, 2),
                'fiber_loss_downstream_db' => round($routeLengthKm * $downstreamCoefficient, 2), 'fiber_loss_upstream_db' => round($routeLengthKm * $upstreamCoefficient, 2),
                'splitter_loss_db' => round($splitterLoss, 2), 'access_splitter_loss_db' => $accessSplitterLoss, 'feeder_splitter_loss_db' => $feederSplitterLoss, 'connector_count' => $connectorCount, 'connector_loss_db' => round($connectorLoss, 2), 'splice_count' => $spliceCount, 'additional_passive_loss_db' => $additionalPassiveLoss,
                'olt_tx_power_dbm' => $project->olt_tx_power_dbm, 'onu_tx_power_dbm' => $project->onu_tx_power_dbm,
                'downstream_rx_dbm' => $downstreamRx, 'upstream_rx_dbm' => $upstreamRx,
                'downstream_receiver_margin_db' => $downstreamReceiverMargin, 'upstream_receiver_margin_db' => $upstreamReceiverMargin,
            ];
        })->values();

        $confirmed = (bool) $project->power_budget_confirmed;
        if (! $confirmed) {
            $connections = $connections->map(fn (array $connection) => array_replace($connection, ['budget_status' => 'estimate']));
        }

        $unassigned = $cabinets->filter(fn ($cabinet) => ! isset($allocations[$cabinet->id]));
        $issues = collect()
            ->when($usedTo > $capacity, fn (Collection $items) => $items->push(['level' => 'error', 'message' => "Kapacitet je prekoračen: F{$usedTo} / {$capacity}F."]))
            ->when($duplicates->isNotEmpty(), fn (Collection $items) => $items->push(['level' => 'error', 'message' => 'Dupla dodjela: F'.$duplicates->implode(', F').'.']))
            ->when($unassigned->isNotEmpty(), fn (Collection $items) => $items->push(['level' => 'warning', 'message' => $unassigned->count().' ODO ormarića nema potpunu ODF/krak vezu.']))
            ->merge($confirmed ? $connections->where('below_minimum', true)->map(fn ($item) => ['level' => 'error', 'message' => $item['cabinet'].' je ispod minimalnog ODN gubitka klase; provjeriti prijemni nivo ili predvidjeti atenuator.']) : collect())
            ->merge($connections->where('budget_status', 'error')->where('below_minimum', false)->map(fn ($item) => ['level' => 'error', 'message' => $item['cabinet'].' prelazi projektni budžet sa rezervom za '.abs($item['headroom_db']).' dB.']))
            ->merge($connections->where('budget_status', 'warning')->map(fn ($item) => ['level' => 'warning', 'message' => $item['cabinet'].' ima manje od 1 dB rezerve nakon inženjerske margine.']))
            ->values();

        return compact('allocations', 'connections', 'issues', 'fibersPerTube', 'reservePerTube', 'capacity', 'usedTo', 'budgetLimit', 'profile', 'engineeringMargin') + [
            'assumptionsConfirmed' => $confirmed,
            'reserveFrom' => $usedTo + 1, 'reserveTo' => $capacity, 'health' => max(0, 100 - $issues->where('level', 'error')->count() * 20 - $issues->where('level', 'warning')->count() * 7),
        ];
    }

    private function splitterLoss(int $ratio): float
    {
        return match (true) {
            $ratio <= 1 => 0.0,
            $ratio <= 2 => 3.7,
            $ratio <= 4 => 7.4,
            $ratio <= 8 => 10.7,
            $ratio <= 16 => 13.8,
            $ratio <= 32 => 17.1,
            default => 20.5,
        };
    }
}
