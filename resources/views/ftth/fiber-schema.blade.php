@extends('ftth.layout')

@section('title', 'FTTH Topologija')
@section('subtitle', 'Tehnicka fiber sema: ODF, magistralni kabl, FTTH ormarici, splitteri i kuce.')

@section('content')
<style>
    .schema-page { display: grid; gap: .75rem; }
    .schema-project { overflow: hidden; border: 1px solid #dbe7f3; border-radius: .75rem; background: #fff; box-shadow: 0 12px 30px rgb(16 24 40 / .06); }
    .schema-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem; border-bottom: 1px solid #dfeaf5; background: #f8fbff; padding: .7rem .85rem; }
    .schema-stats { display: flex; flex-wrap: wrap; gap: .35rem; }
    .schema-chip { border-radius: 999px; background: #edf6ff; padding: .2rem .5rem; color: #005f96; font-size: .71rem; font-weight: 800; }
    .schema-shell { display: grid; gap: .65rem; padding: .65rem; }
    @media (min-width: 1180px) { .schema-shell { grid-template-columns: minmax(0, 1fr) 260px; } }
    .schema-board { min-width: 0; overflow: hidden; border: 1px solid #dbe7f3; border-radius: .75rem; background:
        linear-gradient(#e8eef6 1px, transparent 1px),
        linear-gradient(90deg, #e8eef6 1px, transparent 1px),
        #f9fbfe; background-size: 28px 28px; padding: .65rem; }
    .schema-legend { display: flex; flex-wrap: wrap; gap: .35rem; margin-bottom: .55rem; }
    .legend-item { display: inline-flex; align-items: center; gap: .3rem; border: 1px solid #dbe7f3; border-radius: 999px; background: rgb(255 255 255 / .9); padding: .22rem .45rem; color: #475467; font-size: .67rem; font-weight: 800; }
    .legend-swatch { width: .72rem; height: .72rem; border-radius: .18rem; background: #2684c2; }
    .legend-swatch.odf { border: 2px solid #2684c2; background: #eaf6ff; }
    .legend-swatch.ftth { border: 2px solid #65a845; background: #f2faeb; }
    .legend-swatch.used { border: 1px solid #91c6eb; background: #eaf6ff; }
    .legend-swatch.free { border: 1px dashed #aab7c4; background: #fff; }
    .board-labels { display: grid; grid-template-columns: minmax(110px, 145px) minmax(22px, 34px) minmax(0, 1fr); gap: .45rem; margin: 0 0 .35rem; color: #667085; font-size: .62rem; font-weight: 950; letter-spacing: 0; text-transform: uppercase; }
    .board-labels span { border-bottom: 1px solid #d8e4f0; padding: 0 0 .22rem; }
    .schema-row { display: grid; grid-template-columns: minmax(110px, 145px) minmax(22px, 34px) minmax(0, 1fr); gap: .45rem; align-items: stretch; }
    .odf-rack { display: grid; grid-template-rows: auto 1fr; min-height: 150px; border: 2px solid #2684c2; border-radius: .45rem; background: #eaf6ff; box-shadow: inset 0 0 0 1px #fff; }
    .odf-rack header { display: flex; align-items: center; justify-content: space-between; gap: .35rem; border-bottom: 1px solid #b8d7ef; padding: .4rem .5rem; color: #004f7d; font-size: .72rem; font-weight: 900; }
    .odf-meta { display: grid; gap: .2rem; padding: .45rem .5rem; color: #334155; font-size: .7rem; line-height: 1.18; }
    .fiber-bus { position: relative; min-height: 100%; }
    .fiber-bus::before { content: ""; position: absolute; left: 50%; top: .75rem; bottom: .75rem; width: 4px; transform: translateX(-50%); border-radius: 999px; background: #2684c2; box-shadow: 0 0 0 2px #dff0ff; }
    .fiber-bus span { position: absolute; left: 50%; top: .35rem; transform: translateX(-50%) rotate(90deg); transform-origin: center; border: 1px solid #b8d7ef; border-radius: 999px; background: #fff; padding: .08rem .28rem; color: #005f96; font-size: .52rem; font-weight: 900; white-space: nowrap; }
    .cabinet-area { display: grid; gap: .42rem; min-width: 0; }
    .cabinet-node { position: relative; display: grid; grid-template-columns: minmax(100px, 145px) minmax(0, 1fr); gap: .42rem; align-items: stretch; min-width: 0; }
    .cabinet-node::before { content: ""; position: absolute; left: -2.4rem; top: 1.45rem; width: 2.4rem; height: 2px; background: #2684c2; }
    .connection-tag { position: absolute; left: -2.35rem; top: .35rem; z-index: 1; border: 1px solid #b8d7ef; border-radius: 999px; background: #fff; padding: .08rem .28rem; color: #005f96; font-size: .5rem; font-weight: 950; }
    .cabinet-box { border: 2px solid #65a845; border-radius: .45rem; background: #f2faeb; padding: .48rem; min-width: 0; }
    .cabinet-box.warn { border-color: #d99a15; background: #fff8e8; }
    .cabinet-box.full { border-color: #dc2626; background: #fff2f2; }
    .cabinet-title { display: flex; justify-content: space-between; gap: .4rem; color: #172033; font-size: .76rem; font-weight: 900; }
    .cabinet-title span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .child-cabinets { grid-column: 1 / -1; display: grid; gap: .35rem; margin-left: 1.1rem; padding-left: .75rem; border-left: 2px dashed #65a845; }
    .child-cabinet-node { position: relative; display: grid; grid-template-columns: minmax(100px, 145px) minmax(0, 1fr); gap: .42rem; align-items: stretch; }
    .child-cabinet-node::before { content: ""; position: absolute; left: -.75rem; top: 1.35rem; width: .75rem; height: 2px; background: #65a845; }
    .child-tag { display: inline-block; width: fit-content; border: 1px solid #b7dfaa; border-radius: 999px; background: #fff; padding: .08rem .28rem; color: #34751f; font-size: .5rem; font-weight: 950; }
    .child-cabinet-node .cabinet-box { border-color: #2f8f5b; background: #f0fff7; }
    .util-bar { height: .28rem; overflow: hidden; border-radius: 999px; background: #dfe7ef; margin-top: .4rem; }
    .util-bar div { height: 100%; border-radius: inherit; background: #65a845; }
    .cabinet-box.warn .util-bar div { background: #d99a15; }
    .cabinet-box.full .util-bar div { background: #dc2626; }
    .splitter-panel { display: grid; gap: .28rem; min-width: 0; }
    .splitter-line { display: grid; grid-template-columns: 70px repeat(4, minmax(0, 1fr)); gap: .22rem; align-items: center; }
    .splitter-label { border: 1px solid #cddbea; border-radius: .35rem; background: #fff; padding: .28rem .35rem; color: #334155; font-size: .66rem; font-weight: 850; text-align: center; }
    .port { position: relative; min-height: 28px; border: 1px solid #d8e4f0; border-left: 4px solid #2684c2; border-radius: .35rem; background: #fff; padding: .22rem .3rem .22rem .42rem; color: #1f2a3a; font-size: clamp(.62rem, .66vw, .72rem); font-weight: 760; text-align: left; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
    .port::after { content: ""; position: absolute; right: .32rem; top: .42rem; width: .34rem; height: .34rem; border-radius: 999px; background: #16a34a; box-shadow: 0 0 0 2px #dcfce7; }
    .port b { display: inline-block; margin-right: .25rem; color: #7a8797; font-size: .58rem; }
    .port.empty { border-left-color: #cbd5e1; border-style: dashed; color: #9aa6b2; font-weight: 650; }
    .port.empty::after { background: #cbd5e1; box-shadow: none; }
    .port.active, .port:hover { border-color: #2684c2; background: #eaf6ff; color: #004f7d; }
    .trace-panel { border: 1px solid #dbe7f3; border-radius: .75rem; background: #fff; padding: .75rem; align-self: start; }
    @media (min-width: 1180px) { .trace-panel { position: sticky; top: .75rem; } }
    .trace-chain { display: grid; gap: .4rem; margin-top: .6rem; }
    .trace-step { border-left: 3px solid #2684c2; border-radius: .35rem; background: #f8fbff; padding: .45rem .55rem; }
    .trace-step b { display: block; color: #101828; font-size: .82rem; }
    .trace-step span { color: #667085; font-size: .72rem; }
    .topology-wrap { margin: .65rem; overflow: hidden; border: 1px solid #dbe7f3; border-radius: .65rem; background: #fff; padding: .75rem; }
    .topology-title { margin-bottom: .6rem; color: #334155; font-size: .78rem; font-weight: 900; }
    .topology-viewport { width: 100%; overflow: hidden; }
    .topology-canvas { width: max-content; min-width: 100%; transform-origin: top left; }
    .topology-odf { display: grid; justify-items: center; min-width: max-content; padding: .15rem .5rem .6rem; }
    .topology-box { position: relative; z-index: 2; border: 2px solid #60a5fa; border-radius: .3rem; background: #eff6ff; padding: .32rem .5rem; color: #1e3a5f; font-size: .68rem; font-weight: 900; box-shadow: 0 1px 3px rgb(15 23 42 / .12); }
    .topology-box.odo { display: grid; gap: .12rem; min-width: 84px; border-color: #65a845; background: #f2faeb; color: #315f24; text-align: center; }
    .topology-box.odo.full { border-color: #ef4444; background: #fff1f2; color: #991b1b; }
    .topology-box small { color: #64748b; font-size: .48rem; font-weight: 800; }
    .topology-cabinets { position: relative; display: flex; align-items: flex-start; gap: 1rem; padding-top: 1.35rem; }
    .topology-cabinets::before { content: ""; position: absolute; left: 2.5rem; right: 2.5rem; top: .7rem; height: 2px; background: #64748b; }
    .topology-cabinet { position: relative; display: grid; justify-items: center; min-width: 112px; }
    .topology-cabinet::before { content: ""; position: absolute; left: 50%; top: -1.35rem; width: 2px; height: 1.35rem; background: #64748b; }
    .topology-splitters { position: relative; display: flex; align-items: flex-start; gap: .35rem; padding-top: 1.15rem; }
    .topology-splitters::before { content: ""; position: absolute; left: 1.35rem; right: 1.35rem; top: .55rem; height: 2px; background: #65a845; }
    .topology-splitter { position: relative; display: grid; justify-items: center; gap: .3rem; min-width: 34px; }
    .topology-splitter::before { content: ""; position: absolute; left: 50%; top: -1.15rem; width: 2px; height: 1.15rem; background: #65a845; }
    .topology-splitter-label { border: 1px solid #a78bfa; border-radius: .25rem; background: #f5f3ff; padding: .2rem .25rem; color: #6d28d9; font-size: .52rem; font-weight: 900; text-align: center; }
    .topology-houses { position: relative; display: flex; gap: .22rem; padding-top: .8rem; }
    .topology-houses::before { content: ""; position: absolute; left: .45rem; right: .45rem; top: .38rem; height: 1px; background: #a78bfa; }
    .topology-port { position: relative; display: grid; justify-items: center; gap: .12rem; width: 1rem; padding-top: .05rem; }
    .topology-port::before { content: ""; position: absolute; left: 50%; top: -.8rem; width: 1px; height: .62rem; background: #a78bfa; }
    .topology-fiber { display: none; }
    .topology-house { position: relative; width: .72rem; height: .58rem; border-radius: .08rem; background: #fb923c; }
    .topology-house::before { content: ""; position: absolute; left: 50%; top: -.32rem; transform: translateX(-50%); border-left: .42rem solid transparent; border-right: .42rem solid transparent; border-bottom: .38rem solid #f97316; }
    .topology-port.empty { opacity: .42; }
    .topology-port.empty .topology-house { background: #cbd5e1; }
    .topology-port.empty .topology-house::before { border-bottom-color: #94a3b8; }
    .topology-house-name { display: none; }
    .topology-legend { display: flex; flex-wrap: wrap; justify-content: center; gap: 1.1rem; border-top: 1px solid #e2e8f0; padding-top: .6rem; color: #475569; font-size: .62rem; font-weight: 800; }
    .topology-line { display: inline-block; width: 1.3rem; height: 2px; vertical-align: middle; margin-right: .25rem; background: #64748b; }
    .topology-line.distribution { background: #65a845; }
    .topology-line.drop { background: #a78bfa; }
    .schema-view-tabs { display: flex; gap: .4rem; padding: .65rem .65rem 0; }
    .schema-view-tab { border: 1px solid #cbd5e1; border-radius: .4rem; background: #fff; padding: .38rem .7rem; color: #475569; font-size: .72rem; font-weight: 900; }
    .schema-view-tab.active { border-color: #2563eb; background: #eff6ff; color: #1d4ed8; }
    .topology-graph-shell { position: relative; margin: .65rem; height: min(72vh, 720px); min-height: 420px; overflow: hidden; border: 1px solid #cbd5e1; border-radius: .65rem; background:
        radial-gradient(circle, #cbd5e1 1px, transparent 1px), #f8fafc; background-size: 18px 18px; }
    .topology-graph-stage { position: absolute; inset: 0; transform-origin: 0 0; }
    .topology-graph-stage svg { overflow: visible; }
    .topology-node { cursor: pointer; }
    .topology-node rect { stroke-width: 2; filter: drop-shadow(0 2px 2px rgb(15 23 42 / .15)); }
    .topology-node text { pointer-events: none; font-family: ui-sans-serif, system-ui, sans-serif; font-size: 11px; font-weight: 800; fill: #172033; }
    .topology-edge { fill: none; stroke: #64748b; stroke-width: 2.5; }
    .topology-edge.child { stroke: #65a845; stroke-dasharray: 6 4; }
    .topology-edge.cabinet-branch { stroke: #16a34a; stroke-width: 3.5; }
    .topology-edge.drop { stroke: #a78bfa; stroke-width: 1.4; }
    .topology-minimap { position: absolute; right: .7rem; bottom: .7rem; width: 180px; height: 120px; overflow: hidden; border: 1px solid #94a3b8; border-radius: .4rem; background: rgb(255 255 255 / .94); box-shadow: 0 5px 16px rgb(15 23 42 / .18); }
    .topology-minimap svg { width: 100%; height: 100%; }
    .topology-controls { position: absolute; left: .7rem; top: .7rem; z-index: 3; display: flex; gap: .3rem; }
    .topology-controls button { border: 1px solid #cbd5e1; border-radius: .35rem; background: rgb(255 255 255 / .95); padding: .3rem .5rem; color: #334155; font-size: .68rem; font-weight: 900; box-shadow: 0 2px 6px rgb(15 23 42 / .12); }
    .topology-help { position: absolute; left: .7rem; bottom: .7rem; z-index: 3; border-radius: .35rem; background: rgb(255 255 255 / .9); padding: .3rem .45rem; color: #64748b; font-size: .6rem; font-weight: 800; }
    .cad-fiber-shell { position: relative; margin: .65rem; height: min(78vh, 820px); min-height: 520px; overflow: hidden; border: 1px solid #cbd5e1; border-radius: .65rem; background: #fff; }
    .cad-fiber-stage { position: absolute; inset: 0; transform-origin: 0 0; }
    .cad-fiber-stage svg { overflow: visible; }
    .cad-fiber-controls { position: absolute; left: .7rem; top: .7rem; z-index: 3; display: flex; gap: .3rem; }
    .cad-fiber-controls button { border: 1px solid #cbd5e1; border-radius: .25rem; background: rgb(255 255 255 / .96); padding: .3rem .55rem; color: #334155; font-size: .68rem; font-weight: 900; }
    .cad-fiber-help { position: absolute; left: .7rem; bottom: .7rem; z-index: 3; background: rgb(255 255 255 / .92); padding: .3rem .45rem; color: #64748b; font-size: .62rem; font-weight: 800; }
    @media (max-width: 920px) {
        .schema-row, .board-labels { grid-template-columns: 1fr; }
        .fiber-bus, .cabinet-node::before { display: none; }
        .cabinet-node, .child-cabinet-node { grid-template-columns: 1fr; }
        .child-cabinets { margin-left: 0; }
        .connection-tag { position: static; width: fit-content; margin-bottom: -.2rem; }
    }
    @media (max-width: 620px) {
        .schema-board, .schema-shell, .schema-head { padding: .5rem; }
        .splitter-line { grid-template-columns: 1fr 1fr; }
        .splitter-label { grid-column: 1 / -1; text-align: left; }
    }
</style>

<section class="schema-page">
@forelse($projects as $project)
    @php
        $cabinets = $project->odfs->flatMap->cabinets;
        $childCabinets = $cabinets->flatMap->childCabinets;
        $allCabinets = $cabinets->merge($childCabinets);
        $totalHouses = $allCabinets->sum('houses_count');
        $totalCapacity = max($allCabinets->sum(fn ($cabinet) => max($cabinet->capacity, 12)), 1);
        $projectUtilization = min(100, round($totalHouses / $totalCapacity * 100));
        $fiberAllocations = [];
        $nextFiber = 1;
        $odfOrder = $project->odfs->values()->mapWithKeys(fn ($odf, $index) => [$odf->id => $index + 1]);
        $allocatedCabinetIds = [];
        $branchesFromCabinet = $project->branches
            ->filter(fn ($branch) => $branch->route?->from_type === 'cabinet' && $branch->route?->from_id)
            ->groupBy(fn ($branch) => (int) $branch->route->from_id);
        $childBranchIds = $branchesFromCabinet->flatten(1)->pluck('id')->all();
        $allocateCabinetFibers = function ($cabinet) use (&$allocateCabinetFibers, &$fiberAllocations, &$nextFiber, &$allocatedCabinetIds, $branchesFromCabinet) {
            if (isset($allocatedCabinetIds[$cabinet->id])) {
                return;
            }
            $allocatedCabinetIds[$cabinet->id] = true;
            $fiberCount = max(1, (int) $cabinet->splitter_count);
            $fiberAllocations[$cabinet->id] = [
                'from' => $nextFiber,
                'to' => $nextFiber + $fiberCount - 1,
                'count' => $fiberCount,
            ];
            $nextFiber += $fiberCount;

            $cabinet->childCabinets
                ->sortBy(fn ($child) => sprintf('%06d|%s', (int) ($child->branch_order ?? 0), (string) $child->name))
                ->each(fn ($child) => $allocateCabinetFibers($child));

            ($branchesFromCabinet[(int) $cabinet->id] ?? collect())
                ->sortBy(fn ($branch) => sprintf('%06d|%s', (int) ($branch->sort_order ?? 0), (string) $branch->name))
                ->flatMap->cabinets
                ->sortBy(fn ($child) => sprintf('%06d|%s', (int) ($child->branch_order ?? 0), (string) $child->name))
                ->each(fn ($child) => $allocateCabinetFibers($child));
        };
        $allCabinets
            ->filter(fn ($cabinet) => $cabinet->odf_id && $cabinet->branch_id && ! $cabinet->parent_cabinet_id && ! in_array($cabinet->branch_id, $childBranchIds, true))
            ->sortBy(fn ($cabinet) => sprintf(
                '%06d|%06d|%s',
                (int) ($odfOrder[$cabinet->odf_id] ?? PHP_INT_MAX),
                (int) ($cabinet->branch_order ?? 0),
                (string) $cabinet->name
            ))
            ->each(fn ($cabinet) => $allocateCabinetFibers($cabinet));
    @endphp
    <article class="schema-project">
        <div class="schema-head">
            <div>
                <h2 class="text-base font-black text-slate-950">{{ $project->name }}</h2>
                <p class="text-xs text-slate-500">{{ $project->code }} / {{ $project->location }}</p>
            </div>
            <div class="schema-stats">
                <span class="schema-chip">{{ $project->odfs->count() }} ODF</span>
                <span class="schema-chip">{{ $allCabinets->count() }} FTTH</span>
                <span class="schema-chip">{{ $totalHouses }}/{{ $totalCapacity }}</span>
                <span class="schema-chip">{{ $projectUtilization }}%</span>
            </div>
        </div>

        <div class="schema-view-tabs">
            <button type="button" class="schema-view-tab active" data-schema-view="cad-fiber">CAD Fiber View</button>
            <button type="button" class="schema-view-tab" data-schema-view="topology">Topology View</button>
            <button type="button" class="schema-view-tab" data-schema-view="rack">Fiber Rack View</button>
        </div>
        @php
            $topologyGraph = [
                'odfs' => $project->odfs->map(fn ($odf) => [
                    'id' => $odf->id, 'name' => $odf->name, 'ports' => $odf->port_count, 'fibers' => $odf->fiber_capacity,
                ])->values(),
                'cabinets' => $allCabinets->map(fn ($cabinet) => [
                    'id' => $cabinet->id, 'odf_id' => $cabinet->odf_id, 'parent_id' => $cabinet->parent_cabinet_id, 'branch_id' => $cabinet->branch_id, 'branch_order' => $cabinet->branch_order,
                    'name' => $cabinet->name, 'used' => $cabinet->houses_count, 'capacity' => max($cabinet->capacity, 12), 'splitters' => $cabinet->splitter_count,
                    'fiber_from' => $fiberAllocations[$cabinet->id]['from'] ?? null, 'fiber_to' => $fiberAllocations[$cabinet->id]['to'] ?? null, 'fiber_count' => $fiberAllocations[$cabinet->id]['count'] ?? max(1, (int) $cabinet->splitter_count),
                    'houses' => $cabinet->houses->map(fn ($house) => ['id' => $house->id, 'label' => $house->label])->values(),
                ])->values(),
                'branches' => $project->branches->map(fn ($branch) => [
                    'id' => $branch->id, 'route_id' => $branch->route_id, 'odf_id' => $branch->odf_id, 'parent_id' => $branch->parent_branch_id,
                    'from_cabinet_id' => $branch->route?->from_type === 'cabinet' ? $branch->route->from_id : null,
                    'name' => $branch->name, 'code' => $branch->code, 'type' => $branch->type, 'order' => $branch->sort_order,
                    'fibers' => $branch->route?->fiber_count ?? 12,
                ])->values(),
            ];
        @endphp
        <div data-schema-panel="cad-fiber">
            <div class="cad-fiber-shell" data-cad-fiber='@json($topologyGraph)'>
                <div class="cad-fiber-controls"><button data-cad-action="zoom-in">+</button><button data-cad-action="zoom-out">−</button><button data-cad-action="fit">Fit</button></div>
                <div class="cad-fiber-stage"></div>
                <div class="cad-fiber-help">ODF u centru · krakovi lijevo/desno · magenta linije prikazuju aktivne fiber grupe</div>
            </div>
        </div>
        <div class="hidden" data-schema-panel="topology">
            <div class="topology-graph-shell" data-topology-graph='@json($topologyGraph)'>
                <div class="topology-controls"><button data-topology-action="zoom-in">+</button><button data-topology-action="zoom-out">−</button><button data-topology-action="fit">Fit</button><button data-topology-action="collapse">Sažmi</button></div>
                <div class="topology-graph-stage"></div>
                <div class="topology-minimap"></div>
                <div class="topology-help">Povuci za pomjeranje · točkić za zoom · klik ODO za korisnike</div>
            </div>
        </div>

        <div class="schema-shell hidden" data-schema-panel="rack">
            <div class="schema-board">
                <div class="schema-legend" aria-label="Legenda fiber seme">
                    <span class="legend-item"><span class="legend-swatch odf"></span>ODF rack</span>
                    <span class="legend-item"><span class="legend-swatch"></span>Magistralni kabl</span>
                    <span class="legend-item"><span class="legend-swatch ftth"></span>FTTH ormaric</span>
                    <span class="legend-item"><span class="legend-swatch used"></span>Zauzet port</span>
                    <span class="legend-item"><span class="legend-swatch free"></span>Slobodan port</span>
                </div>
                <div class="board-labels" aria-hidden="true">
                    <span>ODF / patch panel</span>
                    <span>Feeder</span>
                    <span>FTTH ormarici / splitteri 1:4 / korisnici</span>
                </div>
                <div class="grid gap-3">
                @forelse($project->odfs as $odf)
                    <section class="schema-row">
                        <aside class="odf-rack">
                            <header><span>ODF</span><span>{{ $odf->port_count }}P</span></header>
                            <div class="odf-meta">
                                <b class="truncate" title="{{ $odf->name }}">{{ $odf->name }}</b>
                                <span class="truncate" title="{{ $odf->address ?: 'Bez adrese' }}">{{ $odf->address ?: 'Bez adrese' }}</span>
                                <span>{{ $odf->fiber_capacity }} vlakana</span>
                                <span>{{ $odf->cabinets->count() }} izlaza</span>
                            </div>
                        </aside>
                        <div class="fiber-bus"><span>SM FO</span></div>
                        <div class="cabinet-area">
                        @forelse($odf->cabinets as $cabinet)
                            @php
                                $cabinetOrdinal = $loop->iteration;
                                $houses = $cabinet->houses->values();
                                $capacity = max($cabinet->capacity, 12);
                                $used = $cabinet->houses_count;
                                $utilization = min(100, round($used / max($capacity, 1) * 100));
                                $state = $utilization >= 100 ? 'full' : ($utilization >= 80 ? 'warn' : '');
                                $fiberRange = $fiberAllocations[$cabinet->id] ?? null;
                                $fiberLabel = $fiberRange ? ($fiberRange['from'] === $fiberRange['to'] ? (string) $fiberRange['from'] : $fiberRange['from'].'-'.$fiberRange['to']) : '?';
                            @endphp
                            <div class="cabinet-node">
                                <span class="connection-tag">OUT {{ $cabinetOrdinal }}</span>
                                <div class="cabinet-box {{ $state }}">
                                    <div class="cabinet-title">
                                        <span title="{{ $cabinet->name }}">{{ $cabinet->name }}</span>
                                        <span>{{ $used }}/{{ $capacity }}</span>
                                    </div>
                                    <div class="mt-1 truncate text-[10px] font-semibold text-slate-500" title="{{ $cabinet->address ?: 'Bez adrese' }}">{{ $cabinet->address ?: 'Bez adrese' }}</div>
                                    <div class="util-bar"><div style="width: {{ $utilization }}%"></div></div>
                                    <div class="mt-1 text-[10px] font-bold text-slate-500">{{ $cabinet->splitter_count }} x 1:4 · F {{ $fiberLabel }}</div>
                                </div>
                                <div class="splitter-panel">
                                @for($splitter = 1; $splitter <= max($cabinet->splitter_count, 1); $splitter++)
                                    <div class="splitter-line">
                                        <div class="splitter-label">S{{ $splitter }} 1:4</div>
                                        @for($port = 1; $port <= 4; $port++)
                                            @php
                                                $absolutePort = ($splitter - 1) * 4 + $port;
                                                $house = $houses->get($absolutePort - 1);
                                            @endphp
                                            @if($house)
                                                <button type="button" class="port" title="S{{ $splitter }} / P{{ $absolutePort }} -> {{ $house->label }}" data-trace-house="{{ $house->id }}" data-house-label="{{ $house->label }}" data-cabinet-name="{{ $cabinet->name }}" data-odf-name="{{ $odf->name }}" data-fiber-range="{{ $fiberLabel }}" data-splitter="{{ $splitter }}" data-port="{{ $absolutePort }}" data-out="{{ $cabinetOrdinal }}">
                                                    <b>P{{ $absolutePort }}</b>{{ $house->label }}
                                                </button>
                                            @else
                                                <div class="port empty" title="S{{ $splitter }} / P{{ $absolutePort }} slobodan"><b>P{{ $absolutePort }}</b>Slobodno</div>
                                            @endif
                                        @endfor
                                    </div>
                                @endfor
                                </div>
                                @if($cabinet->childCabinets->isNotEmpty())
                                    <div class="child-cabinets">
                                        @foreach($cabinet->childCabinets as $childCabinet)
                                            @php
                                                $childHouses = $childCabinet->houses->values();
                                                $childCapacity = max($childCabinet->capacity, 12);
                                                $childUsed = $childCabinet->houses_count;
                                                $childUtilization = min(100, round($childUsed / max($childCapacity, 1) * 100));
                                                $childState = $childUtilization >= 100 ? 'full' : ($childUtilization >= 80 ? 'warn' : '');
                                                $childFiberRange = $fiberAllocations[$childCabinet->id] ?? null;
                                                $childFiberLabel = $childFiberRange ? ($childFiberRange['from'] === $childFiberRange['to'] ? (string) $childFiberRange['from'] : $childFiberRange['from'].'-'.$childFiberRange['to']) : '?';
                                            @endphp
                                            <div class="child-cabinet-node">
                                                <div class="cabinet-box {{ $childState }}">
                                                    <span class="child-tag">IZ {{ $cabinet->name }}</span>
                                                    <div class="cabinet-title mt-1">
                                                        <span title="{{ $childCabinet->name }}">{{ $childCabinet->name }}</span>
                                                        <span>{{ $childUsed }}/{{ $childCapacity }}</span>
                                                    </div>
                                                    <div class="mt-1 truncate text-[10px] font-semibold text-slate-500" title="{{ $childCabinet->address ?: 'Bez adrese' }}">{{ $childCabinet->address ?: 'Bez adrese' }}</div>
                                                    <div class="util-bar"><div style="width: {{ $childUtilization }}%"></div></div>
                                                    <div class="mt-1 text-[10px] font-bold text-slate-500">{{ $childCabinet->splitter_count }} x 1:4 · F {{ $childFiberLabel }}</div>
                                                </div>
                                                <div class="splitter-panel">
                                                @for($childSplitter = 1; $childSplitter <= max($childCabinet->splitter_count, 1); $childSplitter++)
                                                    <div class="splitter-line">
                                                        <div class="splitter-label">S{{ $childSplitter }} 1:4</div>
                                                        @for($childPort = 1; $childPort <= 4; $childPort++)
                                                            @php
                                                                $childAbsolutePort = ($childSplitter - 1) * 4 + $childPort;
                                                                $childHouse = $childHouses->get($childAbsolutePort - 1);
                                                            @endphp
                                                            @if($childHouse)
                                                                <button type="button" class="port" title="S{{ $childSplitter }} / P{{ $childAbsolutePort }} -> {{ $childHouse->label }}" data-trace-house="{{ $childHouse->id }}" data-house-label="{{ $childHouse->label }}" data-cabinet-name="{{ $childCabinet->name }}" data-parent-cabinet="{{ $cabinet->name }}" data-odf-name="{{ $odf->name }}" data-fiber-range="{{ $childFiberLabel }}" data-splitter="{{ $childSplitter }}" data-port="{{ $childAbsolutePort }}" data-out="{{ $cabinetOrdinal }}">
                                                                    <b>P{{ $childAbsolutePort }}</b>{{ $childHouse->label }}
                                                                </button>
                                                            @else
                                                                <div class="port empty" title="S{{ $childSplitter }} / P{{ $childAbsolutePort }} slobodan"><b>P{{ $childAbsolutePort }}</b>Slobodno</div>
                                                            @endif
                                                        @endfor
                                                    </div>
                                                @endfor
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @empty
                            <div class="rounded-md border border-dashed border-slate-300 bg-white p-3 text-sm text-slate-500">ODF jos nema povezane FTTH ormarice.</div>
                        @endforelse
                        </div>
                    </section>
                @empty
                    <div class="rounded-md border border-slate-200 bg-white p-5 text-sm text-slate-500">Projekat jos nema ODF lokaciju.</div>
                @endforelse
                </div>
            </div>

            <aside class="trace-panel">
                <h3 class="font-bold text-slate-950">Fiber tracing</h3>
                <p class="mt-1 text-sm text-slate-500">Klikni port za prikaz putanje.</p>
                <div data-trace-output class="trace-chain">
                    <div class="rounded-md bg-slate-50 p-3 text-sm text-slate-500">Nema odabrane kuce.</div>
                </div>
                <a href="{{ route('map.dashboard') }}" class="mt-4 block rounded-md border border-blue-200 px-3 py-2 text-center text-sm font-bold text-blue-700">Otvori mapu</a>
            </aside>
        </div>
        @if($project->houses->isNotEmpty())
            <details class="mx-3 mb-3 rounded-md border border-amber-200 bg-amber-50 p-3">
                <summary class="cursor-pointer text-sm font-black text-amber-900">Nepovezane kuće ({{ $project->houses->count() }})</summary>
                <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach($project->houses as $house)
                        <div class="rounded border border-amber-200 bg-white p-2 text-xs"><b>{{ $house->label }}</b><br>{{ $house->address ?: 'Bez adrese' }} · {{ $house->status }}</div>
                    @endforeach
                </div>
            </details>
        @endif
    </article>
@empty
    <div class="rounded-xl border border-slate-200 bg-white p-8 text-center text-slate-500">Nema projekata.</div>
@endforelse
</section>

<script>
document.querySelectorAll('.schema-project').forEach(project => {
    project.querySelectorAll('[data-schema-view]').forEach(button => button.addEventListener('click', () => {
        project.querySelectorAll('[data-schema-view]').forEach(item => item.classList.toggle('active', item === button));
        project.querySelectorAll('[data-schema-panel]').forEach(panel => panel.classList.toggle('hidden', panel.dataset.schemaPanel !== button.dataset.schemaView));
    }));
});
function cadFiberRenderer(shell) {
    const data=JSON.parse(shell.dataset.cadFiber || '{"odfs":[],"cabinets":[],"branches":[]}');
    const stage=shell.querySelector('.cad-fiber-stage');
    let scale=1, panX=0, panY=0, dragging=false, start=null;
    const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    const odfX=1550, odfY=560, odfGap=650, cabinetW=58, cabinetH=96, cabinetGap=126, branchGap=172, fiberPitch=8;
    function branchCabinets(branch) {
        return data.cabinets.filter(c=>Number(c.branch_id)===Number(branch.id)).sort((a,b)=>(a.branch_order||0)-(b.branch_order||0));
    }
    function cabinetFiberLabel(cabinet) {
        const from=Number(cabinet.fiber_from)||0, to=Number(cabinet.fiber_to)||from;
        if(!from) return 'F?';
        return from===to ? `F${from}` : `F${from}-${to}`;
    }
    function fiberRangeText(cabinet) {
        const from=Number(cabinet.fiber_from)||0, to=Number(cabinet.fiber_to)||from;
        if(!from) return '?';
        return from===to ? `${from}` : `${from}-${to}`;
    }
    function branchFiberRange(cabinets) {
        const ranges=cabinets.filter(c=>Number(c.fiber_from)).map(c=>[Number(c.fiber_from),Number(c.fiber_to)||Number(c.fiber_from)]);
        if(!ranges.length) return '?';
        const from=Math.min(...ranges.map(range=>range[0])), to=Math.max(...ranges.map(range=>range[1]));
        return from===to ? `${from}` : `${from}-${to}`;
    }
    function branchSide(branch, index) {
        const label=String(branch.name||branch.code||'').toUpperCase();
        const match=label.match(/(?:KRAK|S|I-|II-|III-)?\s*([IVX]+|\d+)/);
        const token=match ? match[1] : '';
        const roman={I:1,II:2,III:3,IV:4,V:5,VI:6,VII:7,VIII:8,IX:9,X:10};
        const number=roman[token] || Number(token) || index+1;
        return number%2 ? 1 : -1;
    }
    function drawFiberLedger(odf, x, y, parts) {
        const cabinets=data.cabinets
            .filter(cabinet=>Number(cabinet.odf_id)===Number(odf.id)&&cabinet.fiber_from)
            .sort((a,b)=>(Number(a.fiber_from)||0)-(Number(b.fiber_from)||0));
        const rowH=16, width=230, height=Math.max(58, 32+(cabinets.length+1)*rowH);
        const left=x+86, top=y-height/2;
        parts.push(`<g><rect x="${left}" y="${top}" width="${width}" height="${height}" rx="4" fill="#fff" stroke="#bfdbfe" stroke-width="1"/><text x="${left+8}" y="${top+16}" class="cad-meta">Magistralna optika / raspored iz 144</text>`);
        cabinets.forEach((cabinet,index)=>{
            const rowY=top+34+(index*rowH), over=Number(cabinet.fiber_to)>Number(odf.fibers);
            parts.push(`<text x="${left+8}" y="${rowY}" class="${over?'cad-over':'cad-port'}">${cabinetFiberLabel(cabinet)}</text><text x="${left+58}" y="${rowY}" class="cad-meta">${esc(cabinet.name)} · ${Number(cabinet.splitters)||1} spl.</text>`);
        });
        const lastFiber=Math.max(0,...cabinets.map(c=>Number(c.fiber_to)||0));
        const reserveFrom=lastFiber+1, reserveTo=Number(odf.fibers)||144;
        if(reserveFrom<=reserveTo) parts.push(`<text x="${left+8}" y="${top+34+(cabinets.length*rowH)}" class="cad-free">REZERVA F${reserveFrom}-${reserveTo}</text>`);
        parts.push(`</g>`);
    }
    function render() {
        const parts=[], labels=[], positions={}, drawnBranches=new Set(), odfPositions={};
        data.odfs.forEach((odf,odfIndex)=>{odfPositions[odf.id]={x:odfX,y:odfY+(odfIndex*odfGap)};});
        const odfYs=Object.values(odfPositions).map(p=>p.y);
        const trunkTop=Math.min(...odfYs,odfY)-290, trunkBottom=Math.max(...odfYs,odfY)+290;
        const primaryBranches=data.branches.filter(branch=>branch.type==='primary').sort((a,b)=>a.order-b.order);
        const primaryCount=Math.max(primaryBranches.length,2);
        for(let index=0;index<primaryCount;index++){
            const branch=primaryBranches[index] || null, x=odfX-18+(index*12);
            parts.push(`<line x1="${x}" y1="${trunkTop}" x2="${x}" y2="${trunkBottom}" stroke="${index%2?'#6366f1':'#3b82f6'}" stroke-width="${index%2?2:3}"/>`);
            if(branch){
                const fibers=Math.max(1,Number(branch.fibers)||12);
                labels.push(`<g><rect x="${x-62}" y="${trunkTop-50}" width="124" height="36" rx="4" class="cad-label-bg"/><text x="${x}" y="${trunkTop-35}" text-anchor="middle" class="cad-branch">${esc(branch.name)}</text><text x="${x}" y="${trunkTop-21}" text-anchor="middle" class="cad-meta">OPTIKA ${fibers} niti</text></g>`);
            }
        }
        data.odfs.forEach((odf,odfIndex)=>{
            const centerX=odfPositions[odf.id].x, centerY=odfPositions[odf.id].y;
            parts.push(`<g><rect x="${centerX-60}" y="${centerY-70}" width="120" height="140" fill="#fff" stroke="#475569" stroke-width="2"/><text x="${centerX}" y="${centerY-15}" text-anchor="middle" class="cad-title">${esc(odf.name)}</text><text x="${centerX}" y="${centerY+8}" text-anchor="middle" class="cad-meta">ODF / PATCH PANEL</text><text x="${centerX}" y="${centerY+30}" text-anchor="middle" class="cad-meta">${odf.ports}P · ${odf.fibers}F</text></g>`);
            const roots=data.branches.filter(b=>b.type==='secondary'&&Number(b.odf_id)===Number(odf.id)&&!b.from_cabinet_id).sort((a,b)=>a.order-b.order);
            const sideSlots={1:0,'-1':0};
            roots.forEach((branch,index)=>{
                const side=branchSide(branch,index), slot=sideSlots[side]++, maxSide=Math.max(1,roots.filter((item,i)=>branchSide(item,i)===side).length);
                const y=centerY+(slot-(maxSide-1)/2)*branchGap;
                drawManualBranch(branch,centerX,y,side,`odf-${odf.id}`,parts,labels,positions,drawnBranches);
            });
        });
        const width=Math.max(3200,...Object.values(positions).map(p=>p.x+500)), height=Math.max(1800,trunkBottom+260,...Object.values(positions).map(p=>p.y+400));
        stage.innerHTML=`<svg width="${width}" height="${height}"><style>.cad-title{font:700 15px Arial;fill:#111827}.cad-branch{font:700 13px Arial;fill:#ef4444}.cad-meta{font:700 10px Arial;fill:#2563eb}.cad-port{font:700 8px Arial;fill:#d946ef}.cad-free{font:700 8px Arial;fill:#16a34a}.cad-over{font:700 8px Arial;fill:#dc2626}.cad-label-bg{fill:#fff;stroke:#fecaca;stroke-width:1}</style>${parts.join('')}${labels.join('')}</svg>`;
    }
    function drawManualBranch(branch,startX,y,side,parent,parts,labels,positions,drawnBranches) {
        if(drawnBranches.has(String(branch.id))) return;
        drawnBranches.add(String(branch.id));
        const cabinets=branchCabinets(branch), labelWidth=Math.max(170,String(branch.name||'').length*8+24);
        const lineCount=Math.max(1,cabinets.length);
        const busHeight=(lineCount-1)*fiberPitch, odfEdge=startX+side*72, firstCabinetDistance=Math.max(230,labelWidth+95);
        const fibers=Math.max(1,Number(branch.fibers)||12), branchRange=branchFiberRange(cabinets);
        parts.push(`<line x1="${startX+side*2}" y1="${y-busHeight/2-15}" x2="${odfEdge}" y2="${y-busHeight/2-15}" stroke="#94a3b8" stroke-width="1"/><line x1="${odfEdge}" y1="${y-busHeight/2-24}" x2="${odfEdge}" y2="${y+busHeight/2+24}" stroke="#94a3b8" stroke-width="2"/>`);
        if(cabinets.length) parts.push(`<circle cx="${odfEdge}" cy="${y}" r="4" fill="#22c55e"/><text x="${odfEdge+side*10}" y="${y-12}" text-anchor="${side>0?'start':'end'}" class="cad-free">${branchRange}</text>`);
        const labelX=startX+side*(firstCabinetDistance*.52), labelY=y-busHeight/2-70, labelFiber=branchRange!=='?' ? 'F'+branchRange : '';
        labels.push(`<g><text x="${labelX}" y="${labelY+15}" text-anchor="middle" class="cad-branch">${esc(branch.name)}</text><text x="${labelX}" y="${labelY+31}" text-anchor="middle" class="cad-meta">OPTIKA ${fibers} niti ${labelFiber}</text></g>`);
        cabinets.forEach((cabinet,index)=>{
            const x=startX+side*(firstCabinetDistance+index*cabinetGap), tapY=y+(index-lineCount/2+.5)*fiberPitch;
            const boxY=tapY+34, titleY=boxY+cabinetH+28, metaY=titleY+16;
            positions[`cab-${cabinet.id}`]={x,y:tapY, boxY, bottomY:metaY+18};
            parts.push(`<line x1="${odfEdge}" y1="${tapY}" x2="${x}" y2="${tapY}" stroke="#f044e7" stroke-width="2"/>`);
            parts.push(`<circle cx="${x}" cy="${tapY}" r="4" fill="#f044e7"/><text x="${x-side*12}" y="${tapY-8}" text-anchor="${side>0?'end':'start'}" class="cad-port">${fiberRangeText(cabinet)}</text>`);
            parts.push(`<rect x="${x-cabinetW/2}" y="${boxY}" width="${cabinetW}" height="${cabinetH}" fill="#fff" stroke="#64748b" stroke-width="2"/><line x1="${x}" y1="${tapY}" x2="${x}" y2="${boxY}" stroke="#f044e7" stroke-width="2"/><text x="${x}" y="${titleY}" text-anchor="middle" class="cad-title">${esc(cabinet.name)}</text><text x="${x}" y="${metaY}" text-anchor="middle" class="cad-meta">${cabinetFiberLabel(cabinet)} / ${cabinet.used}/${cabinet.capacity}</text>`);
        });
        data.branches.filter(child=>Number(child.from_cabinet_id)&&positions[`cab-${child.from_cabinet_id}`]&&Number(child.odf_id)===Number(branch.odf_id)&&!drawnBranches.has(String(child.id))).forEach((child,index)=>{
            const anchor=positions[`cab-${child.from_cabinet_id}`], childStartY=(anchor.boxY ? anchor.boxY+cabinetH : anchor.y), childY=(anchor.bottomY || childStartY)+150+(index*95);
            parts.push(`<line x1="${anchor.x}" y1="${childStartY}" x2="${anchor.x}" y2="${childY}" stroke="#f044e7" stroke-width="2"/>`);
            drawManualBranch(child,anchor.x,childY,side,`cab-${child.from_cabinet_id}`,parts,labels,positions,drawnBranches);
        });
    }
    function drawBranch(branch,startX,y,side,parent,parts,labels,positions,drawnBranches) {
        if(drawnBranches.has(String(branch.id))) return;
        drawnBranches.add(String(branch.id));
        const cabinets=branchCabinets(branch), labelWidth=Math.max(170,String(branch.name||'').length*8+24);
        const lineCount=Math.max(1,cabinets.reduce((total,cabinet)=>total+Math.max(1,Number(cabinet.splitters)||1),0));
        const busHeight=(lineCount-1)*fiberPitch, odfEdge=startX+side*72, busStart=odfEdge, firstCabinetDistance=Math.max(230,labelWidth+95);
        const fibers=Math.max(1,Number(branch.fibers)||12);
        const branchRange=branchFiberRange(cabinets);
        parts.push(`<line x1="${startX+side*2}" y1="${y-busHeight/2-15}" x2="${odfEdge}" y2="${y-busHeight/2-15}" stroke="#94a3b8" stroke-width="1"/><line x1="${odfEdge}" y1="${y-busHeight/2-24}" x2="${odfEdge}" y2="${y+busHeight/2+24}" stroke="#94a3b8" stroke-width="2"/>`);
        if(cabinets.length) {
            parts.push(`<circle cx="${busStart}" cy="${y}" r="4" fill="#22c55e"/><text x="${busStart+side*10}" y="${y-12}" text-anchor="${side>0?'start':'end'}" class="cad-free">${branchRange}</text>`);
        }
        const labelX=startX+side*(firstCabinetDistance*.52), labelY=y-busHeight/2-70, labelFiber=branchRange!=='?' ? 'F'+branchRange : '';
        labels.push(`<g><text x="${labelX}" y="${labelY+15}" text-anchor="middle" class="cad-branch">${esc(branch.name)}</text><text x="${labelX}" y="${labelY+31}" text-anchor="middle" class="cad-meta">OPTIKA ${fibers} niti ${labelFiber}</text></g>`);
        cabinets.forEach((cabinet,index)=>{
            const x=startX+side*(firstCabinetDistance+index*cabinetGap), splits=Math.max(1,Number(cabinet.splitters)||1), tapY=y+((Math.min(splits,lineCount)-1)-lineCount/2+.5)*fiberPitch;
            positions[`cab-${cabinet.id}`]={x,y:tapY};
            parts.push(`<circle cx="${x}" cy="${tapY}" r="4" fill="#f044e7"/><text x="${x-side*12}" y="${tapY-8}" text-anchor="${side>0?'end':'start'}" class="cad-port">${fiberRangeText(cabinet)}</text>`);
            parts.push(`<rect x="${x-cabinetW/2}" y="${tapY+20}" width="${cabinetW}" height="${cabinetH}" fill="#fff" stroke="#64748b" stroke-width="2"/><line x1="${x}" y1="${tapY}" x2="${x}" y2="${tapY+20}" stroke="#f044e7" stroke-width="2"/><text x="${x}" y="${tapY+cabinetH+40}" text-anchor="middle" class="cad-title">${esc(cabinet.name)}</text><text x="${x}" y="${tapY+cabinetH+56}" text-anchor="middle" class="cad-meta">${cabinetFiberLabel(cabinet)} · ${cabinet.used}/${cabinet.capacity}</text>`);
        });
        data.branches.filter(child=>Number(child.from_cabinet_id)&&positions[`cab-${child.from_cabinet_id}`]&&Number(child.odf_id)===Number(branch.odf_id)&&!drawnBranches.has(String(child.id))).forEach((child,index)=>{
            const anchor=positions[`cab-${child.from_cabinet_id}`], childY=anchor.y+(index+1)*145;
            parts.push(`<line x1="${anchor.x}" y1="${anchor.y}" x2="${anchor.x}" y2="${childY}" stroke="#f044e7" stroke-width="2"/>`);
            drawBranch(child,anchor.x,childY,side,`cab-${child.from_cabinet_id}`,parts,labels,positions,drawnBranches);
        });
    }
    function apply(){stage.style.transform=`translate(${panX}px,${panY}px) scale(${scale})`}
    function fit(){const svg=stage.querySelector('svg');if(!svg)return;scale=Math.min(.95,(shell.clientWidth-40)/svg.width.baseVal.value,(shell.clientHeight-40)/svg.height.baseVal.value);panX=(shell.clientWidth-svg.width.baseVal.value*scale)/2;panY=(shell.clientHeight-svg.height.baseVal.value*scale)/2;apply()}
    shell.addEventListener('wheel',e=>{e.preventDefault();scale=Math.max(.15,Math.min(3,scale*(e.deltaY<0?1.12:.89)));apply()},{passive:false});
    shell.addEventListener('pointerdown',e=>{if(e.target.closest('.cad-fiber-controls'))return;dragging=true;start={x:e.clientX-panX,y:e.clientY-panY};shell.setPointerCapture(e.pointerId)});
    shell.addEventListener('pointermove',e=>{if(dragging){panX=e.clientX-start.x;panY=e.clientY-start.y;apply()}});
    shell.addEventListener('pointerup',()=>dragging=false);
    shell.querySelector('[data-cad-action="zoom-in"]').onclick=()=>{scale=Math.min(3,scale*1.2);apply()};
    shell.querySelector('[data-cad-action="zoom-out"]').onclick=()=>{scale=Math.max(.15,scale/1.2);apply()};
    shell.querySelector('[data-cad-action="fit"]').onclick=fit;
    render(); setTimeout(fit,0);
}
function topologyRenderer(shell) {
    const data = JSON.parse(shell.dataset.topologyGraph || '{"odfs":[],"cabinets":[]}');
    const stage = shell.querySelector('.topology-graph-stage');
    const minimap = shell.querySelector('.topology-minimap');
    const expanded = new Set();
    let scale = 1, panX = 0, panY = 0, dragging = false, start = null;
    const nodeW = 116, nodeH = 42, laneGap = 210, columnGap = 175;
    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    function graph() {
        const nodes = [], edges = [];
        data.odfs.forEach((odf, odfIndex) => {
            let roots = data.branches.filter(branch => Number(branch.odf_id) === Number(odf.id) && (!branch.parent_id || branch.from_cabinet_id))
                .sort((a,b)=>Number(Boolean(a.from_cabinet_id))-Number(Boolean(b.from_cabinet_id)) || a.order-b.order);
            const unassigned = data.cabinets.filter(c => Number(c.odf_id) === Number(odf.id) && !c.branch_id);
            if (unassigned.length) roots.push({ id:`unassigned-${odf.id}`, name:'Neraspoređeni ODO', code:'?', type:'secondary', synthetic:true });
            const baseX=80+odfIndex*1800;
            const laneState={next:0, anchorBranches:{}};
            roots.forEach(branch=>addBranchLane(branch,baseX+220,`odf-${odf.id}`,nodes,edges,unassigned,laneState));
            nodes.push({ id:`odf-${odf.id}`, type:'odf', x:baseX, y:80+Math.max(0,laneState.next-1)*laneGap/2, label:odf.name, meta:`${odf.ports}P / ${odf.fibers}F` });
        });
        return {nodes, edges};
    }
    function addBranchLane(branch, x, parent, nodes, edges, unassigned=[], laneState={next:0}) {
        laneState.anchorBranches ||= {};
        let y=80+laneState.next*laneGap;
        laneState.next++;
        const anchorNode=branch.from_cabinet_id ? nodes.find(node=>node.id===`cab-${branch.from_cabinet_id}`) : null;
        if (anchorNode) {
            const anchorIndex=laneState.anchorBranches[branch.from_cabinet_id] || 0;
            laneState.anchorBranches[branch.from_cabinet_id]=anchorIndex+1;
            parent=anchorNode.id;
            x=anchorNode.x;
            y=anchorNode.y+95+(anchorIndex*95);
        }
        const branchNodeId=`branch-${branch.id}`;
        nodes.push({ id:branchNodeId, type:'branch', x, y, label:branch.name, meta:branch.code || branch.type });
        edges.push({ from:parent, to:branchNodeId, type:branch.from_cabinet_id ? 'cabinet-branch' : (branch.parent_id ? 'child' : '') });
        const cabinets=(branch.synthetic ? unassigned : data.cabinets.filter(c=>Number(c.branch_id)===Number(branch.id) && (!c.parent_id || Number(c.parent_id)===Number(branch.from_cabinet_id)))).sort((a,b)=>(a.branch_order||0)-(b.branch_order||0));
        let previous=branchNodeId;
        cabinets.forEach((cabinet,index)=>{addCabinet(cabinet,x+(index+1)*columnGap,y,previous,1,nodes,edges);previous=`cab-${cabinet.id}`;});
        data.branches.filter(item=>Number(item.parent_id)===Number(branch.id) && !item.from_cabinet_id).sort((a,b)=>a.order-b.order).forEach(child=>addBranchLane(child,x+columnGap,branchNodeId,nodes,edges,unassigned,laneState));
    }
    function addCabinet(cabinet, x, y, parent, side, nodes, edges) {
        const fiberLabel=cabinet.fiber_from ? (Number(cabinet.fiber_from)===Number(cabinet.fiber_to) ? `F${cabinet.fiber_from}` : `F${cabinet.fiber_from}-${cabinet.fiber_to}`) : 'F?';
        nodes.push({ id:`cab-${cabinet.id}`, type:'cabinet', x, y, label:cabinet.name, meta:`${cabinet.used}/${cabinet.capacity} / ${fiberLabel}`, cabinet });
        edges.push({ from:parent, to:`cab-${cabinet.id}`, type:cabinet.parent_id ? 'child' : '' });
        data.cabinets.filter(c => Number(c.parent_id) === Number(cabinet.id) && Number(c.branch_id)===Number(cabinet.branch_id)).sort((a,b)=>(a.branch_order||0)-(b.branch_order||0)).forEach((child, index) => addCabinet(child, x + side * (index + 1) * columnGap, y, `cab-${cabinet.id}`, side, nodes, edges));
        if (expanded.has(cabinet.id)) cabinet.houses.forEach((house, index) => {
            const hx = x + (index % 4) * 82, hy = y + 64 + Math.floor(index / 4) * 48;
            nodes.push({ id:`house-${house.id}`, type:'house', x:hx, y:hy, label:house.label, meta:'' });
            edges.push({ from:`cab-${cabinet.id}`, to:`house-${house.id}`, type:'drop' });
        });
    }
    function render() {
        const {nodes, edges} = graph();
        const byId = Object.fromEntries(nodes.map(node => [node.id, node]));
        const maxX = Math.max(1100, ...nodes.map(n => n.x + nodeW + 80)), maxY = Math.max(520, ...nodes.map(n => n.y + nodeH + 80));
        const edgeSvg = edges.map(edge => {
            const a=byId[edge.from], b=byId[edge.to]; if(!a||!b)return '';
            const ax=a.x+nodeW/2, ay=a.y+nodeH/2, bx=b.x+nodeW/2, by=b.y+nodeH/2, mid=(ax+bx)/2;
            return `<path class="topology-edge ${edge.type}" d="M${ax} ${ay} C${mid} ${ay},${mid} ${by},${bx} ${by}"/>`;
        }).join('');
        const nodeSvg = nodes.map(node => {
            const colors=node.type==='odf'?['#eff6ff','#2563eb']:node.type==='branch'?['#f8fafc','#64748b']:node.type==='house'?['#fff7ed','#f97316']:['#f2faeb','#65a845'];
            return `<g class="topology-node" data-node-type="${node.type}" data-cabinet-id="${node.cabinet?.id||''}" transform="translate(${node.x},${node.y})"><rect width="${nodeW}" height="${nodeH}" rx="6" fill="${colors[0]}" stroke="${colors[1]}"/><text x="${nodeW/2}" y="18" text-anchor="middle">${esc(node.label)}</text><text x="${nodeW/2}" y="33" text-anchor="middle" style="font-size:9px;fill:#64748b">${esc(node.meta)}</text></g>`;
        }).join('');
        stage.innerHTML=`<svg width="${maxX}" height="${maxY}">${edgeSvg}${nodeSvg}</svg>`;
        minimap.innerHTML=`<svg viewBox="0 0 ${maxX} ${maxY}">${edges.map(edge=>{const a=byId[edge.from],b=byId[edge.to];return a&&b?`<line x1="${a.x}" y1="${a.y}" x2="${b.x}" y2="${b.y}" stroke="#94a3b8" stroke-width="5"/>`:''}).join('')}${nodes.map(n=>`<rect x="${n.x}" y="${n.y}" width="35" height="18" fill="${n.type==='odf'?'#2563eb':n.type==='house'?'#f97316':'#65a845'}"/>`).join('')}</svg>`;
        stage.querySelectorAll('[data-node-type="cabinet"]').forEach(node => node.addEventListener('click', event => { event.stopPropagation(); const id=Number(node.dataset.cabinetId); expanded.has(id)?expanded.delete(id):expanded.add(id); render(); }));
        applyTransform();
    }
    function applyTransform(){ stage.style.transform=`translate(${panX}px,${panY}px) scale(${scale})`; }
    function fit(){ const svg=stage.querySelector('svg'); if(!svg)return; scale=Math.min(.95,(shell.clientWidth-40)/svg.width.baseVal.value,(shell.clientHeight-40)/svg.height.baseVal.value); panX=(shell.clientWidth-svg.width.baseVal.value*scale)/2; panY=(shell.clientHeight-svg.height.baseVal.value*scale)/2; applyTransform(); }
    shell.addEventListener('wheel', e=>{e.preventDefault(); scale=Math.max(.2,Math.min(2.5,scale*(e.deltaY<0?1.12:.89)));applyTransform();},{passive:false});
    shell.addEventListener('pointerdown',e=>{if(e.target.closest('.topology-node,.topology-controls'))return;dragging=true;start={x:e.clientX-panX,y:e.clientY-panY};shell.setPointerCapture(e.pointerId)});
    shell.addEventListener('pointermove',e=>{if(!dragging)return;panX=e.clientX-start.x;panY=e.clientY-start.y;applyTransform()});
    shell.addEventListener('pointerup',()=>dragging=false);
    shell.querySelector('[data-topology-action="zoom-in"]').onclick=()=>{scale=Math.min(2.5,scale*1.2);applyTransform()};
    shell.querySelector('[data-topology-action="zoom-out"]').onclick=()=>{scale=Math.max(.2,scale/1.2);applyTransform()};
    shell.querySelector('[data-topology-action="fit"]').onclick=fit;
    shell.querySelector('[data-topology-action="collapse"]').onclick=()=>{expanded.clear();render();fit()};
    render(); requestAnimationFrame(fit);
}
document.querySelectorAll('[data-topology-graph]').forEach(topologyRenderer);
document.querySelectorAll('[data-cad-fiber]').forEach(cadFiberRenderer);
document.querySelectorAll('[data-trace-house]').forEach(button => {
    button.addEventListener('click', () => {
        document.querySelectorAll('[data-trace-house]').forEach(item => item.classList.remove('active'));
        button.classList.add('active');
        const project = button.closest('.schema-project');
        const output = project?.querySelector('[data-trace-output]');
        if (!output) return;
        const parentStep = button.dataset.parentCabinet
            ? `<div class="trace-step"><b>${button.dataset.parentCabinet}</b><span>Roditeljski FTTH ormaric / uzete niti za izvedeni ODO</span></div>`
            : '';
        output.innerHTML = `
            <div class="trace-step"><b>${button.dataset.odfName}</b><span>ODF OUT ${button.dataset.out} / patch panel</span></div>
            <div class="trace-step"><b>Magistralni kabl</b><span>SM FO vlakna ${button.dataset.fiberRange || '?'} prema FTTH ormaricu</span></div>
            ${parentStep}
            <div class="trace-step"><b>${button.dataset.cabinetName}</b><span>FTTH ormaric / splitter blok</span></div>
            <div class="trace-step"><b>Splitter ${button.dataset.splitter} / P${button.dataset.port}</b><span>1:4 izlaz prema korisniku</span></div>
            <div class="trace-step"><b>${button.dataset.houseLabel}</b><span>Kuca / krajnja tacka</span></div>
        `;
        localStorage.setItem('ftthTraceHouseId', button.dataset.traceHouse);
    });
});
</script>
@endsection
