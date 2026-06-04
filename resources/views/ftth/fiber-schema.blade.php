@extends('ftth.layout')

@section('title', 'FTTH Topologija')
@section('subtitle', 'Tehnicka fiber sema: ODF, magistralni kabl, FTTH ormarici, splitteri i kuce.')

@section('content')
<style>
    .schema-page { display: grid; gap: .75rem; }
    .schema-project { overflow: hidden; border: 1px solid #dbe7f3; border-radius: .75rem; background: #fff; box-shadow: 0 12px 30px rgb(16 24 40 / .06); }
    .schema-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem; border-bottom: 1px solid #dfeaf5; background: #f8fbff; padding: .7rem .85rem; }
    .schema-stats { display: flex; flex-wrap: wrap; gap: .35rem; }
    .schema-chip { border-radius: 999px; background: #edf6ff; padding: .2rem .5rem; color: #005f96; font-size: .68rem; font-weight: 800; }
    .schema-shell { display: grid; gap: .65rem; padding: .65rem; }
    @media (min-width: 1180px) { .schema-shell { grid-template-columns: minmax(0, 1fr) 260px; } }
    .schema-board { min-width: 0; overflow: hidden; border: 1px solid #dbe7f3; border-radius: .75rem; background:
        linear-gradient(#e8eef6 1px, transparent 1px),
        linear-gradient(90deg, #e8eef6 1px, transparent 1px),
        #f9fbfe; background-size: 28px 28px; padding: .65rem; }
    .schema-legend { display: flex; flex-wrap: wrap; gap: .35rem; margin-bottom: .55rem; }
    .legend-item { display: inline-flex; align-items: center; gap: .3rem; border: 1px solid #dbe7f3; border-radius: 999px; background: rgb(255 255 255 / .9); padding: .22rem .45rem; color: #475467; font-size: .63rem; font-weight: 800; }
    .legend-swatch { width: .72rem; height: .72rem; border-radius: .18rem; background: #2684c2; }
    .legend-swatch.odf { border: 2px solid #2684c2; background: #eaf6ff; }
    .legend-swatch.ftth { border: 2px solid #65a845; background: #f2faeb; }
    .legend-swatch.used { border: 1px solid #91c6eb; background: #eaf6ff; }
    .legend-swatch.free { border: 1px dashed #aab7c4; background: #fff; }
    .board-labels { display: grid; grid-template-columns: minmax(110px, 145px) minmax(22px, 34px) minmax(0, 1fr); gap: .45rem; margin: 0 0 .35rem; color: #667085; font-size: .58rem; font-weight: 950; letter-spacing: 0; text-transform: uppercase; }
    .board-labels span { border-bottom: 1px solid #d8e4f0; padding: 0 0 .22rem; }
    .schema-row { display: grid; grid-template-columns: minmax(110px, 145px) minmax(22px, 34px) minmax(0, 1fr); gap: .45rem; align-items: stretch; }
    .odf-rack { display: grid; grid-template-rows: auto 1fr; min-height: 150px; border: 2px solid #2684c2; border-radius: .45rem; background: #eaf6ff; box-shadow: inset 0 0 0 1px #fff; }
    .odf-rack header { display: flex; align-items: center; justify-content: space-between; gap: .35rem; border-bottom: 1px solid #b8d7ef; padding: .4rem .5rem; color: #004f7d; font-size: .72rem; font-weight: 900; }
    .odf-meta { display: grid; gap: .2rem; padding: .45rem .5rem; color: #334155; font-size: .66rem; line-height: 1.15; }
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
    .cabinet-title { display: flex; justify-content: space-between; gap: .4rem; color: #172033; font-size: .72rem; font-weight: 900; }
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
    .splitter-label { border: 1px solid #cddbea; border-radius: .35rem; background: #fff; padding: .28rem .35rem; color: #334155; font-size: .62rem; font-weight: 850; text-align: center; }
    .port { position: relative; min-height: 28px; border: 1px solid #d8e4f0; border-left: 4px solid #2684c2; border-radius: .35rem; background: #fff; padding: .22rem .3rem .22rem .42rem; color: #1f2a3a; font-size: clamp(.58rem, .62vw, .68rem); font-weight: 760; text-align: left; overflow: hidden; }
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

        <div class="schema-shell">
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
                                    <div class="mt-1 text-[10px] font-bold text-slate-500">{{ $cabinet->splitter_count }} x 1:4</div>
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
                                                <button type="button" class="port" title="S{{ $splitter }} / P{{ $absolutePort }} -> {{ $house->label }}" data-trace-house="{{ $house->id }}" data-house-label="{{ $house->label }}" data-cabinet-name="{{ $cabinet->name }}" data-odf-name="{{ $odf->name }}" data-splitter="{{ $splitter }}" data-port="{{ $absolutePort }}" data-out="{{ $cabinetOrdinal }}">
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
                                                    <div class="mt-1 text-[10px] font-bold text-slate-500">{{ $childCabinet->splitter_count }} x 1:4</div>
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
                                                                <button type="button" class="port" title="S{{ $childSplitter }} / P{{ $childAbsolutePort }} -> {{ $childHouse->label }}" data-trace-house="{{ $childHouse->id }}" data-house-label="{{ $childHouse->label }}" data-cabinet-name="{{ $childCabinet->name }}" data-parent-cabinet="{{ $cabinet->name }}" data-odf-name="{{ $odf->name }}" data-splitter="{{ $childSplitter }}" data-port="{{ $childAbsolutePort }}" data-out="{{ $cabinetOrdinal }}">
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
    </article>
@empty
    <div class="rounded-xl border border-slate-200 bg-white p-8 text-center text-slate-500">Nema projekata.</div>
@endforelse
</section>

<script>
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
            <div class="trace-step"><b>Magistralni kabl</b><span>SM FO feeder prema FTTH ormaricu</span></div>
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
