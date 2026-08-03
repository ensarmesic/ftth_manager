@extends('ftth.layout')
@section('title', 'Izvještaji')
@section('subtitle', 'Pregled kapaciteta, trasa i validacije projekata')
@section('content')

{{-- KPI STRIP --}}
<div class="report-kpi-grid mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ([
        ['Projekti',         $totals['projects'],                         '#7c3aed', '#f5f3ff', '<path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>'],
        ['Kuće',             $totals['houses'],                           '#1d4ed8', '#eff6ff', '<path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"/>'],
        ['Ormarići',         $totals['cabinets'],                         '#0369a1', '#f0f9ff', '<path d="M4 3a2 2 0 100 4h12a2 2 0 100-4H4zM3 8h14v7a2 2 0 01-2 2H5a2 2 0 01-2-2V8zm5 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z"/>'],
        ['Slobodni portovi', $totals['free_ports'],                       '#b45309', '#fffbeb', '<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v2H7a1 1 0 100 2h2v2a1 1 0 102 0v-2h2a1 1 0 100-2h-2V7z" clip-rule="evenodd"/>'],
        ['Mikrocijevi',      number_format($totals['duct']).' m',         '#0f766e', '#f0fdfa', '<path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zM8 7a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zM14 4a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z"/>'],
        ['Optički kabl',     number_format($totals['fiber']).' m',        '#1d4ed8', '#eff6ff', '<path fill-rule="evenodd" d="M12.395 2.553a1 1 0 00-1.45-.385c-.345.23-.614.558-.822.88-.214.33-.403.713-.57 1.116-.334.804-.614 1.768-.84 2.734a31.365 31.365 0 00-.613 3.58 2.64 2.64 0 01-.945-1.067c-.328-.68-.398-1.534-.398-2.654A1 1 0 005.05 6.05 6.981 6.981 0 003 11a7 7 0 1011.95-4.95c-.592-.591-.98-.985-1.348-1.467-.363-.476-.724-1.063-1.207-2.03z" clip-rule="evenodd"/>'],
    ] as [$label, $value, $accent, $bg, $path])
    <div class="report-kpi-card flex items-center gap-4 rounded-2xl border border-slate-200 bg-white px-5 py-4 shadow-sm">
        <div class="report-kpi-icon flex h-11 w-11 shrink-0 items-center justify-center rounded-xl" style="background:{{ $bg }}">
            <svg viewBox="0 0 20 20" fill="{{ $accent }}" class="h-5 w-5">{!! $path !!}</svg>
        </div>
        <div>
            <div class="text-xs font-medium text-slate-400">{{ $label }}</div>
            <div class="report-kpi-value mt-0.5 text-xl font-bold text-slate-900 leading-none">{{ $value }}</div>
        </div>
    </div>
    @endforeach
</div>

{{-- PROJEKTI --}}
<div class="grid gap-4">
@forelse ($projects as $project)
@php
    $usedPorts   = $project->cabinets->sum('houses_count');
    $capacity    = $project->cabinets->sum(fn ($c) => $c->capacity);
    $freePorts   = max($capacity - $usedPorts, 0);
    $utilPct     = $capacity > 0 ? min(round($usedPorts / $capacity * 100), 100) : 0;
    $utilColor   = $utilPct >= 90 ? '#ef4444' : ($utilPct >= 70 ? '#f59e0b' : '#22c55e');
    $materialRoutes = $project->routes->where('route_type', '!=', 'trench');
    $duct1410    = $materialRoutes->where('microduct_type', '14/10')->sum(fn ($r) => $r->duct_length_m * $r->microduct_count);
    $duct108     = $materialRoutes->where('microduct_type', '10/8')->sum(fn ($r) => $r->duct_length_m * $r->microduct_count);
    $physTrench  = $project->routes->sum(fn ($r) => $r->trenchLengthForReport());
    $insight     = $projectInsights[$project->id] ?? ['validation' => [], 'materials' => []];
    $validation  = collect($insight['validation']);
    $materials   = $insight['materials'];
    $errorCount  = $validation->where('level', 'error')->count();
    $warningCount = $validation->where('level', 'warning')->count();
    $statusColors = ['aktivan' => ['#16a34a','#dcfce7'], 'završen' => ['#2563eb','#dbeafe'], 'planiranje' => ['#9333ea','#f3e8ff']];
    [$statusFg, $statusBg] = $statusColors[strtolower($project->status)] ?? ['#64748b','#f1f5f9'];
@endphp

<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">

    {{-- Header --}}
    <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-base font-bold text-slate-900">{{ $project->name }}</h2>
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold" style="background:{{ $statusBg }};color:{{ $statusFg }}">{{ ucfirst($project->status) }}</span>
                @if($errorCount > 0)
                    <span class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-semibold text-red-700">
                        <span class="h-1.5 w-1.5 rounded-full bg-red-500"></span>{{ $errorCount }} grešaka
                    </span>
                @elseif($warningCount > 0)
                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700">
                        <span class="h-1.5 w-1.5 rounded-full bg-amber-400"></span>{{ $warningCount }} upozorenja
                    </span>
                @else
                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>OK
                    </span>
                @endif
            </div>
            <p class="mt-0.5 text-xs text-slate-400">{{ $project->code }} &bull; {{ $project->location }}</p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <div class="text-right">
                <div class="text-xs font-semibold text-slate-900">{{ $usedPorts }}/{{ $capacity }} portova</div>
                <div class="text-[10px] text-slate-400">{{ $utilPct }}% popunjeno</div>
            </div>
            <a href="{{ route('reports.project-appendix', $project) }}" class="btn-secondary text-xs">Prilog 3 →</a>
        </div>
    </div>

    {{-- Port utilization bar --}}
    <div class="h-1.5 bg-slate-100">
        <div class="h-full transition-all" style="width:{{ $utilPct }}%;background:{{ $utilColor }}"></div>
    </div>

    {{-- Stats row --}}
    <div class="grid grid-cols-2 divide-x divide-y divide-slate-100 border-b border-slate-100 sm:grid-cols-4 sm:divide-y-0">
        @foreach ([
            ['ODF',      $project->odfs_count,     '#0891b2'],
            ['Ormarići', $project->cabinets_count,  '#059669'],
            ['Kuće',     $project->houses_count,    '#7c3aed'],
            ['Slobodno', $freePorts,                $freePorts > 0 ? '#16a34a' : '#dc2626'],
        ] as [$lbl, $val, $clr])
        <div class="px-4 py-3">
            <div class="text-[10px] font-bold uppercase tracking-wide text-slate-400">{{ $lbl }}</div>
            <div class="mt-0.5 text-lg font-bold" style="color:{{ $clr }}">{{ $val }}</div>
        </div>
        @endforeach
    </div>

    {{-- Body: Validation + Materials --}}
    <div class="grid gap-4 p-4 md:grid-cols-2">

        {{-- Validation --}}
        <div>
            <div class="mb-2 text-[11px] font-bold uppercase tracking-wide text-slate-400">Projektna validacija</div>
            <div class="grid gap-1.5">
                @forelse($validation->take(6) as $item)
                @php $lvl = $item['level']; $dot = $lvl === 'error' ? '#ef4444' : ($lvl === 'warning' ? '#f59e0b' : '#22c55e'); $bg = $lvl === 'error' ? '#fef2f2' : ($lvl === 'warning' ? '#fffbeb' : '#f0fdf4'); $border = $lvl === 'error' ? '#fecaca' : ($lvl === 'warning' ? '#fde68a' : '#bbf7d0'); @endphp
                <div class="flex items-start gap-2.5 rounded-lg border px-3 py-2 text-xs" style="background:{{ $bg }};border-color:{{ $border }}">
                    <span class="mt-1 h-2 w-2 shrink-0 rounded-full" style="background:{{ $dot }}"></span>
                    <div class="min-w-0">
                        <div class="font-medium text-slate-800">{{ $item['message'] }}</div>
                        @if(!empty($item['recommendation']))<div class="mt-0.5 text-slate-500">{{ $item['recommendation'] }}</div>@endif
                    </div>
                </div>
                @empty
                <div class="flex items-center gap-2.5 rounded-lg border border-emerald-100 bg-emerald-50 px-3 py-2 text-xs text-emerald-700">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    Projekat prošao sve provjere.
                </div>
                @endforelse
            </div>
        </div>

        {{-- Materijalni obračun --}}
        <div>
            <div class="mb-2 text-[11px] font-bold uppercase tracking-wide text-slate-400">Materijalni obračun</div>
            <div class="grid grid-cols-2 gap-1.5">
                @foreach ([
                    ['Mikrocijev 14/10', number_format($materials['microduct_14_10_m'] ?? 0).' m', '#0891b2'],
                    ['Mikrocijev 10/8',  number_format($materials['microduct_10_8_m'] ?? 0).' m',  '#0369a1'],
                    ['Optika 4 niti',    number_format($materials['fiber_4_m'] ?? 0).' m',          '#7c3aed'],
                    ['Optika 12 niti',   number_format($materials['fiber_12_m'] ?? 0).' m',         '#6d28d9'],
                    ['Splitteri',        number_format($materials['splitter_count'] ?? 0).' kom',    '#059669'],
                    ['Neklas. trasa',    number_format($materials['unclassified_routes'] ?? 0),      '#64748b'],
                ] as [$k, $v, $c])
                <div class="flex items-center justify-between rounded-lg border border-slate-100 bg-slate-50 px-2.5 py-1.5 text-xs">
                    <span class="text-slate-500 truncate mr-1">{{ $k }}</span>
                    <b class="shrink-0" style="color:{{ $c }}">{{ $v }}</b>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Trase --}}
    @if($project->routes->where('route_type', '!=', 'trench')->count())
    <details class="border-t border-slate-100">
        <summary class="flex cursor-pointer select-none items-center justify-between px-5 py-3 text-xs font-bold uppercase tracking-wide text-slate-500 hover:bg-slate-50">
            <span>Pregled trasa</span>
            <div class="flex items-center gap-2">
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-600">{{ $project->routes->where('route_type', '!=', 'trench')->count() }} trasa</span>
                <span class="text-slate-300">▸</span>
            </div>
        </summary>
        <div class="px-4 pb-4">
            <div class="overflow-x-auto rounded-xl border border-slate-100" tabindex="0" aria-label="Tabela trasa; povucite vodoravno za ostale kolone">
                <table class="w-full min-w-160 text-xs">
                    <thead>
                        <tr class="border-b border-slate-100 bg-slate-50 text-[10px] font-bold uppercase tracking-wide text-slate-400">
                            <th class="px-3 py-2 text-left">Trasa</th>
                            <th class="px-3 py-2 text-left">Polaganje</th>
                            <th class="px-3 py-2 text-left">Mikrocijev</th>
                            <th class="px-3 py-2 text-right">Dužina</th>
                            <th class="px-3 py-2 text-right">Kabl</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @foreach($project->routes->where('route_type', '!=', 'trench') as $route)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-3 py-2 font-semibold text-slate-800">{{ $route->name }}</td>
                            <td class="px-3 py-2 text-slate-500">{{ $route->installation_type ?: '—' }}</td>
                            <td class="px-3 py-2 text-slate-500">{{ $route->microduct_type ?: '—' }}{{ $route->microduct_count > 1 ? ' ×'.$route->microduct_count : '' }}</td>
                            <td class="px-3 py-2 text-right font-semibold text-slate-800">{{ number_format($route->duct_length_m) }} m</td>
                            <td class="px-3 py-2 text-right text-slate-500">{{ $route->fiber_count }} niti / {{ number_format($route->fiber_length_m) }} m</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-slate-400">
                <span>Rov: <b class="text-slate-600">{{ number_format($physTrench) }} m</b></span>
                <span>Mikrocijevi: <b class="text-slate-600">{{ number_format($duct1410 + $duct108) }} m</b></span>
                <span>Optički kabl: <b class="text-slate-600">{{ number_format($project->routes->sum('fiber_length_m')) }} m</b></span>
                <span>Rezerva +10%: <b class="text-slate-600">{{ number_format(ceil($project->routes->sum('fiber_length_m') * 1.1)) }} m</b></span>
            </div>
        </div>
    </details>
    @endif


</div>
@empty
    @include('ftth.partials.empty-state', ['title' => 'Nema projekata', 'message' => 'Izvještaji će se prikazati kada dodate prvi projekat.'])
@endforelse
</div>

@endsection
