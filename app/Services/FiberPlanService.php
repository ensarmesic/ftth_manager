<?php

namespace App\Services;

use App\Models\Project;
use App\Support\FiberColorCode;
use Illuminate\Support\Collection;

class FiberPlanService
{
    private const PON_PROFILES = [
        'gpon_b_plus' => ['label' => 'GPON B+', 'standard' => 'ITU-T G.984.2', 'min' => 13.0, 'max' => 28.0, 'downstream_nm' => 1490, 'upstream_nm' => 1310, 'distance_category' => '20 km'],
        'gpon_c_plus' => ['label' => 'GPON C+', 'standard' => 'ITU-T G.984.2', 'min' => 17.0, 'max' => 32.0, 'downstream_nm' => 1490, 'upstream_nm' => 1310, 'distance_category' => '20 km'],
        'gpon_d' => ['label' => 'GPON D', 'standard' => 'ITU-T G.984.2', 'min' => 20.0, 'max' => 35.0, 'downstream_nm' => 1490, 'upstream_nm' => 1310, 'distance_category' => '20 km'],
        'xgs_n1' => ['label' => 'XGS-PON N1', 'standard' => 'ITU-T G.9807.1', 'min' => 14.0, 'max' => 29.0, 'downstream_nm' => 1577, 'upstream_nm' => 1270, 'distance_category' => 'DD20'],
        'xgs_n2' => ['label' => 'XGS-PON N2', 'standard' => 'ITU-T G.9807.1', 'min' => 16.0, 'max' => 31.0, 'downstream_nm' => 1577, 'upstream_nm' => 1270, 'distance_category' => 'DD20'],
        'xgs_e1' => ['label' => 'XGS-PON E1', 'standard' => 'ITU-T G.9807.1', 'min' => 18.0, 'max' => 33.0, 'downstream_nm' => 1577, 'upstream_nm' => 1270, 'distance_category' => 'DD20'],
        'xgs_e2' => ['label' => 'XGS-PON E2', 'standard' => 'ITU-T G.9807.1', 'min' => 20.0, 'max' => 35.0, 'downstream_nm' => 1577, 'upstream_nm' => 1270, 'distance_category' => 'DD20'],
    ];

    public function build(Project $project): array
    {
        $project->loadMissing(['odfs', 'branches.route', 'cabinets.houses', 'cabinets.branch.route', 'routes', 'fiberSplices']);
        $fibersPerTube = str_ends_with($project->fiber_layout ?? '6x24', 'x12') ? 12 : 24;
        $reservePerTube = min((int) ($project->fiber_reserve_per_tube ?? 0), $fibersPerTube - 1);
        $nextFibers = [];
        $allocations = [];
        $cabinetIndex = $project->cabinets->keyBy('id');
        $branchIndex = $project->branches->keyBy('id');
        $contexts = [];

        $resolveContext = function ($cabinet) use ($cabinetIndex): array {
            $odfId = $cabinet->odf_id;
            $branchId = $cabinet->branch_id;
            $parentId = $cabinet->parent_cabinet_id;
            $visited = [];
            while ((! $odfId || ! $branchId) && $parentId && ! isset($visited[$parentId])) {
                $visited[$parentId] = true;
                $parent = $cabinetIndex->get($parentId);
                if (! $parent) {
                    break;
                }
                $odfId ??= $parent->odf_id;
                $branchId ??= $parent->branch_id;
                $parentId = $parent->parent_cabinet_id;
            }

            return ['odf_id' => $odfId ? (int) $odfId : null, 'branch_id' => $branchId ? (int) $branchId : null];
        };

        $allocate = function (int $count, int $odfId) use (&$nextFibers, $fibersPerTube, $reservePerTube): array {
            $nextFiber = $nextFibers[$odfId] ?? 1;
            $position = (($nextFiber - 1) % $fibersPerTube) + 1;
            $usable = $fibersPerTube - $reservePerTube;
            if ($position > $usable || $position + $count - 1 > $usable) {
                $nextFiber += $fibersPerTube - $position + 1;
            }
            $range = ['from' => $nextFiber, 'to' => $nextFiber + $count - 1, 'count' => $count];
            $nextFibers[$odfId] = $nextFiber + $count;

            return $range;
        };

        foreach ($project->cabinets as $cabinet) {
            $contexts[$cabinet->id] = $resolveContext($cabinet);
        }
        $branchDepth = function ($branch) use ($branchIndex, $cabinetIndex): int {
            $depth = 0;
            $visited = [];
            while ($branch?->route?->from_type === 'cabinet' && $branch->route->from_id && ! isset($visited[$branch->id])) {
                $visited[$branch->id] = true;
                $sourceCabinet = $cabinetIndex->get($branch->route->from_id);
                $branch = $sourceCabinet?->branch_id ? $branchIndex->get($sourceCabinet->branch_id) : null;
                $depth++;
            }

            return $depth;
        };
        $cabinets = $project->cabinets->sortBy(function ($cabinet) use ($contexts, $branchIndex, $branchDepth): string {
            $context = $contexts[$cabinet->id];
            $branch = $branchIndex->get($context['branch_id']);

            return sprintf('%08d|%08d|%08d|%s|%08d|%s', $context['odf_id'] ?? 999999, $branchDepth($branch), $branch?->sort_order ?? 999999, $branch?->name ?? '', $cabinet->branch_order ?? 999999, $cabinet->name);
        });
        foreach ($cabinets as $cabinet) {
            $context = $contexts[$cabinet->id];
            if (! $context['odf_id']) {
                continue;
            }
            $splitters = max(1, (int) ceil($cabinet->houses->count() / max(1, (int) $cabinet->ports_per_splitter)));
            $allocations[$cabinet->id] = $allocate($splitters, $context['odf_id']) + $context;
        }

        $odfPlans = $project->odfs->mapWithKeys(function ($odf) use ($allocations): array {
            $ranges = collect($allocations)->where('odf_id', $odf->id);
            $claimed = $ranges->flatMap(fn (array $range) => range($range['from'], $range['to']));

            return [$odf->id => [
                'name' => $odf->name,
                'capacity' => max(1, (int) $odf->fiber_capacity),
                'usedTo' => (int) ($ranges->max('to') ?: 0),
                'usedFibers' => $claimed->unique()->count(),
                'duplicates' => $claimed->duplicates()->unique()->values()->all(),
                'reserveFrom' => (int) ($ranges->max('to') ?: 0) + 1,
                'reserveTo' => max(1, (int) $odf->fiber_capacity),
            ]];
        });
        $capacity = max(1, (int) $odfPlans->sum('capacity'));
        $usedFibers = (int) $odfPlans->sum('usedFibers');
        $usedTo = (int) (collect($allocations)->max('to') ?: 0);
        $profile = self::PON_PROFILES[$project->pon_profile ?? 'gpon_b_plus'] ?? self::PON_PROFILES['gpon_b_plus'];
        $budgetLimit = $profile['max'];
        $engineeringMargin = (float) ($project->engineering_margin_db ?? 3);
        $routeLengthFor = function ($cabinet) use ($branchIndex, $cabinetIndex): float {
            $meters = 0.0;
            $branch = $cabinet->branch_id ? $branchIndex->get($cabinet->branch_id) : null;
            $visited = [];
            while ($branch && ! isset($visited[$branch->id])) {
                $visited[$branch->id] = true;
                $meters += (float) ($branch->route?->fiber_length_m ?: $branch->route?->duct_length_m ?: 0);
                if ($branch->route?->from_type !== 'cabinet' || ! $branch->route?->from_id) {
                    break;
                }
                $sourceCabinet = $cabinetIndex->get($branch->route->from_id);
                $branch = $sourceCabinet?->branch_id ? $branchIndex->get($sourceCabinet->branch_id) : null;
            }

            return $meters / 1000;
        };
        $connections = $cabinets->map(function ($cabinet) use ($allocations, $contexts, $branchIndex, $fibersPerTube, $project, $budgetLimit, $profile, $engineeringMargin, $routeLengthFor): array {
            $range = $allocations[$cabinet->id] ?? null;
            $context = $contexts[$cabinet->id];
            $branch = $branchIndex->get($context['branch_id']);
            $routeLengthKm = $routeLengthFor($cabinet);
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
            $receiverMargin = collect([$downstreamReceiverMargin, $upstreamReceiverMargin])->filter(fn ($value) => $value !== null)->min();
            $loss = round(max($downstreamLoss, $upstreamLoss), 2);
            $designLoss = round($loss + $engineeringMargin, 2);
            $headroom = round($budgetLimit - $designLoss, 2);
            $incompleteFields = collect([
                ! $range ? 'dodjela vlakana' : null,
                $routeLengthKm <= 0 ? 'dužina optičke putanje' : null,
                ! $context['odf_id'] ? 'ODF veza' : null,
                ! $context['branch_id'] ? 'mrežni krak' : null,
            ])->filter()->values()->all();
            $belowMinimum = $loss < $profile['min'];
            $receiverLevelInvalid = $receiverMargin !== null && $receiverMargin < 0;
            $receiverMarginLow = $receiverMargin !== null && ! $receiverLevelInvalid && $receiverMargin < $engineeringMargin;
            $budgetStatus = ($belowMinimum || $designLoss > $budgetLimit || $receiverLevelInvalid) ? 'error' : (($headroom < 1 || $receiverMarginLow) ? 'warning' : 'ok');

            return [
                'cabinet_id' => $cabinet->id, 'cabinet' => $cabinet->name, 'odf_id' => $context['odf_id'],
                'branch' => $branch?->name, 'fiber_from' => $range['from'] ?? null, 'fiber_to' => $range['to'] ?? null,
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
                'receiver_margin_db' => $receiverMargin, 'receiver_level_invalid' => $receiverLevelInvalid, 'receiver_margin_low' => $receiverMarginLow,
                'data_complete' => $incompleteFields === [], 'incomplete_fields' => $incompleteFields,
            ];
        })->values();

        $confirmed = (bool) $project->power_budget_confirmed;
        if (! $confirmed) {
            $connections = $connections->map(fn (array $connection) => array_replace($connection, ['budget_status' => 'estimate']));
        }

        $unassigned = $cabinets->filter(fn ($cabinet) => ! isset($allocations[$cabinet->id]));
        $staleSplices = $project->fiberSplices->filter(function ($splice) use ($allocations): bool {
            $range = $allocations[$splice->cabinet_id] ?? null;

            return ! $range || $splice->fiber_number < $range['from'] || $splice->fiber_number > $range['to'];
        });
        $issues = $odfPlans->flatMap(function (array $odf): array {
            $issues = [];
            if ($odf['usedTo'] > $odf['capacity']) {
                $issues[] = ['level' => 'error', 'message' => "{$odf['name']}: kapacitet je prekoračen: F{$odf['usedTo']} / {$odf['capacity']}F."];
            }
            if ($odf['duplicates'] !== []) {
                $issues[] = ['level' => 'error', 'message' => "{$odf['name']}: dupla dodjela: F".implode(', F', $odf['duplicates']).'.'];
            }

            return $issues;
        })->values()
            ->when($unassigned->isNotEmpty(), fn (Collection $items) => $items->push(['level' => 'warning', 'message' => $unassigned->count().' ODO ormarića nema ODF vezu.']))
            ->merge($staleSplices->map(function ($splice) use ($cabinetIndex): array {
                $cabinetName = $cabinetIndex->get($splice->cabinet_id)?->name ?? 'Nepoznati ODO';

                return ['level' => 'error', 'message' => "{$cabinetName}: splice F{$splice->fiber_number} više ne pripada trenutnoj fiber dodjeli; ažurirati splice plan."];
            }))
            ->merge($confirmed ? $connections->where('below_minimum', true)->map(fn ($item) => ['level' => 'error', 'message' => $item['cabinet'].' je ispod minimalnog ODN gubitka klase; provjeriti prijemni nivo ili predvidjeti atenuator.']) : collect())
            ->merge($connections->where('below_minimum', false)->filter(fn ($item) => $item['design_loss_db'] > $budgetLimit)->map(fn ($item) => ['level' => 'error', 'message' => $item['cabinet'].' prelazi projektni budžet sa rezervom za '.abs($item['headroom_db']).' dB.']))
            ->merge($connections->where('receiver_level_invalid', true)->map(fn ($item) => ['level' => 'error', 'message' => $item['cabinet'].' ima prijemni nivo ispod osjetljivosti prijemnika.']))
            ->merge($connections->where('receiver_margin_low', true)->map(fn ($item) => ['level' => 'warning', 'message' => $item['cabinet'].' nema punu inženjersku rezervu prema osjetljivosti prijemnika.']))
            ->merge($connections->where('data_complete', false)->map(fn ($item) => ['level' => 'warning', 'message' => $item['cabinet'].' nema kompletne projektne podatke: '.implode(', ', $item['incomplete_fields']).'.']))
            ->merge($connections->filter(fn ($item) => $item['route_km'] > 20)->map(fn ($item) => ['level' => 'error', 'message' => $item['cabinet'].' ima optičku putanju '.$item['route_km'].' km, iznad '.$profile['distance_category'].' klase aktivnog profila.']))
            ->merge($connections->filter(fn ($item) => $item['headroom_db'] >= 0 && $item['headroom_db'] < 1)->map(fn ($item) => ['level' => 'warning', 'message' => $item['cabinet'].' ima manje od 1 dB rezerve nakon inženjerske margine.']))
            ->values();

        $signature = strtoupper(substr(hash('sha256', json_encode([
            'project' => $project->id,
            'allocations' => collect($allocations)->sortKeys()->all(),
            'connections' => $connections->map(fn (array $connection): array => collect($connection)
                ->only(['cabinet_id', 'route_km', 'loss_db', 'design_loss_db', 'headroom_db', 'budget_status'])
                ->all())->all(),
            'profile' => $project->pon_profile ?? 'gpon_b_plus',
            'layout' => $project->fiber_layout ?? '6x24',
            'color' => $project->fiber_color_standard ?? 'telcordia',
        ], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE)), 0, 16));

        return compact('allocations', 'connections', 'issues', 'fibersPerTube', 'reservePerTube', 'capacity', 'usedFibers', 'usedTo', 'budgetLimit', 'profile', 'engineeringMargin', 'signature') + [
            'odfs' => $odfPlans->all(),
            'assumptionsConfirmed' => $confirmed,
            'reserveFrom' => $usedTo + 1, 'reserveTo' => (int) ($odfPlans->max('capacity') ?: 0), 'health' => max(0, 100 - $issues->where('level', 'error')->count() * 20 - $issues->where('level', 'warning')->count() * 7),
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
