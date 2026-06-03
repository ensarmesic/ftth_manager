@extends('ftth.layout')

@section('title', 'FTTH Topologija')
@section('subtitle', 'Topoloski prikaz mreze: ODF, FTTH ormarici, splitteri i kuce.')

@section('content')
<style>
    .topology-shell { display: grid; gap: 1rem; }
    .topology-project { border: 1px solid #e2e8f0; background: #fff; border-radius: .5rem; overflow: hidden; }
    .topology-head { display: flex; align-items: center; justify-content: space-between; gap: .75rem; border-bottom: 1px solid #e2e8f0; padding: 1rem 1.25rem; }
    .topology-body { display: grid; gap: 1rem; padding: 1rem; }
    .topology-grid { display: grid; gap: 1rem; }
    @media (min-width: 1100px) { .topology-grid { grid-template-columns: minmax(0, 1fr) 320px; } }
    .network-diagram { overflow-x: auto; padding-bottom: .25rem; }
    .odf-node { min-width: 780px; border: 1px solid #bfdbfe; background: #eff6ff; border-radius: .5rem; padding: 1rem; }
    .node-title { display: flex; align-items: center; justify-content: space-between; gap: .75rem; }
    .node-badge { border-radius: 999px; padding: .2rem .55rem; font-size: .72rem; font-weight: 800; }
    .odf-badge { background: #dbeafe; color: #1d4ed8; }
    .cabinet-row { position: relative; display: grid; gap: .75rem; margin-top: 1rem; padding-left: 2rem; }
    .cabinet-row::before { content: ""; position: absolute; left: .65rem; top: 0; bottom: 0; border-left: 2px solid #93c5fd; }
    .cabinet-card { position: relative; border: 1px solid #bbf7d0; background: #f0fdf4; border-radius: .5rem; padding: .85rem; }
    .cabinet-card::before { content: ""; position: absolute; left: -1.35rem; top: 1.3rem; width: 1.25rem; border-top: 2px solid #93c5fd; }
    .cabinet-card.warn { border-color: #fde68a; background: #fffbeb; }
    .cabinet-card.full { border-color: #fecaca; background: #fef2f2; }
    .capacity-bar { height: .42rem; overflow: hidden; border-radius: 999px; background: #e5e7eb; }
    .capacity-fill { height: 100%; background: #22c55e; }
    .cabinet-card.warn .capacity-fill { background: #f59e0b; }
    .cabinet-card.full .capacity-fill { background: #ef4444; }
    .house-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: .4rem; margin-top: .75rem; }
    .house-node { border: 1px solid #e2e8f0; background: #fff; border-radius: .375rem; padding: .5rem .6rem; text-align: left; font-size: .8rem; font-weight: 700; color: #334155; }
    .house-node:hover, .house-node.active { border-color: #2563eb; background: #dbeafe; color: #1e3a8a; }
    .trace-panel { border: 1px solid #e2e8f0; background: #f8fafc; border-radius: .5rem; padding: 1rem; align-self: start; }
    .trace-step { display: grid; gap: .2rem; border-left: 3px solid #2563eb; padding: .35rem 0 .35rem .75rem; }
    .trace-arrow { color: #64748b; font-weight: 900; padding-left: .25rem; }
</style>

<section class="topology-shell">
@forelse($projects as $project)
    <article class="topology-project">
        <div class="topology-head">
            <div>
                <h2 class="text-lg font-bold text-slate-950">{{ $project->name }}</h2>
                <p class="text-xs text-slate-500">{{ $project->code }} / {{ $project->location }}</p>
            </div>
            <a href="{{ route('map.dashboard') }}" class="rounded-md bg-blue-600 px-3 py-2 text-xs font-semibold text-white">Otvori mapu</a>
        </div>

        <div class="topology-body">
            <div class="topology-grid">
                <div class="network-diagram">
                @forelse($project->odfs as $odf)
                    <section class="odf-node">
                        <div class="node-title">
                            <div>
                                <h3 class="font-black text-blue-800">{{ $odf->name }}</h3>
                                <p class="text-xs text-slate-600">{{ $odf->address }} / {{ $odf->port_count }} portova / {{ $odf->fiber_capacity }} vlakana</p>
                            </div>
                            <span class="node-badge odf-badge">ODF</span>
                        </div>

                        <div class="cabinet-row">
                        @forelse($odf->cabinets as $cabinet)
                            @php
                                $used = $cabinet->houses_count;
                                $capacity = max($cabinet->capacity, 1);
                                $utilization = min(100, round(($used / $capacity) * 100));
                                $state = $utilization >= 100 ? 'full' : ($utilization >= 80 ? 'warn' : '');
                            @endphp
                            <section class="cabinet-card {{ $state }}" data-cabinet-id="{{ $cabinet->id }}">
                                <div class="node-title">
                                    <div>
                                        <h4 class="font-black text-slate-900">{{ $cabinet->name }}</h4>
                                        <p class="text-xs text-slate-600">{{ $used }} / {{ $capacity }} kuca · {{ $cabinet->splitter_count }} / {{ $cabinet->splitter_count }} splittera · {{ $utilization }}%</p>
                                    </div>
                                    <span class="node-badge">{{ $utilization }}%</span>
                                </div>
                                <div class="mt-2 capacity-bar"><div class="capacity-fill" style="width: {{ $utilization }}%"></div></div>
                                <div class="house-list">
                                @forelse($cabinet->houses as $house)
                                    <button type="button" class="house-node" data-trace-house="{{ $house->id }}" data-house-label="{{ $house->label }}" data-cabinet-name="{{ $cabinet->name }}" data-odf-name="{{ $odf->name }}">
                                        {{ $house->label }}
                                    </button>
                                @empty
                                    <div class="text-xs font-semibold text-slate-500">Nema povezanih kuca.</div>
                                @endforelse
                                </div>
                            </section>
                        @empty
                            <div class="rounded-md border border-dashed border-blue-200 bg-white p-3 text-sm text-slate-500">ODF jos nema povezane FTTH ormarice.</div>
                        @endforelse
                        </div>
                    </section>
                @empty
                    <div class="rounded-md border border-slate-200 bg-white p-5 text-sm text-slate-500">Projekat jos nema ODF lokaciju.</div>
                @endforelse
                </div>

                <aside class="trace-panel">
                    <h3 class="font-bold text-slate-950">Fiber tracing</h3>
                    <p class="mt-1 text-sm text-slate-500">Klikni kucu u topologiji za putanju do ODF-a.</p>
                    <div data-trace-output class="mt-4 grid gap-2 text-sm">
                        <div class="rounded-md bg-white p-3 text-slate-500">Nema odabrane kuce.</div>
                    </div>
                </aside>
            </div>
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
        const output = button.closest('.topology-grid')?.querySelector('[data-trace-output]');
        if (!output) return;
        output.innerHTML = `
            <div class="trace-step"><b>${button.dataset.houseLabel}</b><span>Kuca</span></div>
            <div class="trace-arrow">↓</div>
            <div class="trace-step"><b>${button.dataset.cabinetName}</b><span>FTTH ormaric</span></div>
            <div class="trace-arrow">↓</div>
            <div class="trace-step"><b>${button.dataset.odfName}</b><span>ODF</span></div>
        `;
        localStorage.setItem('ftthTraceHouseId', button.dataset.traceHouse);
    });
});
</script>
@endsection
