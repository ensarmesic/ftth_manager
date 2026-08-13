<?php

namespace App\Services;

use App\Models\Project;
use App\Support\FiberColorCode;
use Illuminate\Support\Collection;

class FiberPlanService
{
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
        $budgetLimit = (float) ($project->fiber_budget_limit_db ?: 28);
        $connections = $cabinets->map(function ($cabinet) use ($allocations, $fibersPerTube, $project, $budgetLimit): array {
            $range = $allocations[$cabinet->id] ?? null;
            $routeLengthKm = ((float) ($cabinet->branch?->route?->fiber_length_m ?: $cabinet->branch?->route?->duct_length_m)) / 1000;
            $splitterRatio = max(2, (int) $cabinet->ports_per_splitter);
            $splitterLoss = match (true) {
                $splitterRatio <= 2 => 3.7, $splitterRatio <= 4 => 7.4, $splitterRatio <= 8 => 10.7, $splitterRatio <= 16 => 13.8, default => 17.1
            };
            $splices = $project->fiberSplices->where('cabinet_id', $cabinet->id);
            $spliceLoss = $splices->isNotEmpty() ? (float) $splices->sum('loss_db') : .2;
            $loss = round(($routeLengthKm * .35) + $splitterLoss + $spliceLoss + .6, 2);

            return [
                'cabinet_id' => $cabinet->id, 'cabinet' => $cabinet->name, 'odf_id' => $cabinet->odf_id,
                'branch' => $cabinet->branch?->name, 'fiber_from' => $range['from'] ?? null, 'fiber_to' => $range['to'] ?? null,
                'tube' => $range ? FiberColorCode::describe($range['from'], $fibersPerTube, $project->fiber_color_standard ?? 'telcordia')['tube_number'] : null,
                'houses' => $cabinet->houses->count(), 'capacity' => $cabinet->capacity, 'route_km' => round($routeLengthKm, 3),
                'splitter_ratio' => "1:{$splitterRatio}", 'splice_loss_db' => round($spliceLoss, 2), 'loss_db' => $loss,
                'margin_db' => round($budgetLimit - $loss, 2), 'budget_status' => $loss > $budgetLimit ? 'error' : ($loss > $budgetLimit - 3 ? 'warning' : 'ok'),
            ];
        })->values();

        $unassigned = $cabinets->filter(fn ($cabinet) => ! isset($allocations[$cabinet->id]));
        $issues = collect()
            ->when($usedTo > $capacity, fn (Collection $items) => $items->push(['level' => 'error', 'message' => "Kapacitet je prekoračen: F{$usedTo} / {$capacity}F."]))
            ->when($duplicates->isNotEmpty(), fn (Collection $items) => $items->push(['level' => 'error', 'message' => 'Dupla dodjela: F'.$duplicates->implode(', F').'.']))
            ->when($unassigned->isNotEmpty(), fn (Collection $items) => $items->push(['level' => 'warning', 'message' => $unassigned->count().' ODO ormarića nema potpunu ODF/krak vezu.']))
            ->merge($connections->where('budget_status', 'error')->map(fn ($item) => ['level' => 'error', 'message' => $item['cabinet'].' prelazi optički budžet za '.abs($item['margin_db']).' dB.']))
            ->merge($connections->where('budget_status', 'warning')->map(fn ($item) => ['level' => 'warning', 'message' => $item['cabinet'].' ima malu rezervu: '.$item['margin_db'].' dB.']))
            ->values();

        return compact('allocations', 'connections', 'issues', 'fibersPerTube', 'reservePerTube', 'capacity', 'usedTo', 'budgetLimit') + [
            'reserveFrom' => $usedTo + 1, 'reserveTo' => $capacity, 'health' => max(0, 100 - $issues->where('level', 'error')->count() * 20 - $issues->where('level', 'warning')->count() * 7),
        ];
    }
}
