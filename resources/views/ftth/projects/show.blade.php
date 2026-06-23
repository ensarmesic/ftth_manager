@extends('ftth.layout')
@section('title', $project->name)
@section('subtitle', $project->code . ' · ' . ($project->location ?? ''))
@section('content')

@php
    $statusLabels = ['planning' => 'Planiranje', 'active' => 'Aktivan', 'paused' => 'Pauziran', 'completed' => 'Završen'];
    $statusColors = ['active' => 'green', 'completed' => 'violet', 'planning' => 'amber', 'paused' => 'slate'];
    $statusColor  = $statusColors[$project->status] ?? 'sky';

    $valErrors   = $validationItems->where('level', 'error');
    $valWarnings = $validationItems->where('level', 'warning');
    $valOks      = $validationItems->where('level', 'ok');

    $capacity   = $project->cabinets->sum(fn ($c) => $c->capacity);
    $usedPorts  = $project->cabinets->sum('houses_count');
    $freePorts  = max($capacity - $usedPorts, 0);
    $utilPct    = $capacity > 0 ? min(round($usedPorts / $capacity * 100), 100) : 0;
    $utilColor  = $utilPct >= 90 ? '#ef4444' : ($utilPct >= 70 ? '#f59e0b' : '#22c55e');

    $routeTypeLabels = ['backbone' => 'Backbone', 'feeder' => 'Feeder', 'distribution' => 'Distribucija', 'drop' => 'Drop', 'trench' => 'Glavni rov'];
@endphp

{{-- NAVIGACIJA NAZAD --}}
<div class="mb-4 flex items-center gap-2 text-sm text-slate-400">
    <a href="{{ route('projects.index') }}" class="hover:text-slate-700">Projekti</a>
    <span>/</span>
    <span class="text-slate-700 font-medium">{{ $project->name }}</span>
</div>

{{-- HEADER PROJEKTA --}}
<div class="mb-5 flex flex-wrap items-start justify-between gap-4 rounded-2xl border border-slate-200 bg-white px-6 py-5 shadow-sm">
    <div>
        <div class="flex flex-wrap items-center gap-2 mb-1">
            <h1 class="text-xl font-bold text-slate-900">{{ $project->name }}</h1>
            <span class="ftth-badge {{ $statusColor }}"><span class="ftth-badge-dot"></span>{{ $statusLabels[$project->status] ?? $project->status }}</span>
            @if($valErrors->count() > 0)
                <span class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700">
                    <span class="h-1.5 w-1.5 rounded-full bg-red-500"></span>{{ $valErrors->count() }} grešaka
                </span>
            @elseif($valWarnings->count() > 0)
                <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700">
                    <span class="h-1.5 w-1.5 rounded-full bg-amber-400"></span>{{ $valWarnings->count() }} upozorenja
                </span>
            @else
                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>OK
                </span>
            @endif
        </div>
        <div class="text-sm text-slate-400 space-x-3">
            @if($project->investor)<span>Investitor: <span class="text-slate-600">{{ $project->investor }}</span></span>@endif
            @if($project->start_date)<span>Početak: <span class="text-slate-600">{{ $project->start_date }}</span></span>@endif
            @if($project->deadline)<span>Rok: <span class="text-slate-600">{{ $project->deadline }}</span></span>@endif
        </div>
        @if($project->description)
            <p class="mt-2 text-sm text-slate-500">{{ $project->description }}</p>
        @endif
    </div>
    <div class="flex flex-wrap gap-2 shrink-0">
        <a href="{{ route('map.dashboard', ['project' => $project->id]) }}" class="tbl-btn" style="background:#eff6ff;color:#1e40af;border-color:#bfdbfe">
            <svg viewBox="0 0 16 16" fill="currentColor" class="w-3 h-3"><path fill-rule="evenodd" d="M8.879.879a2.25 2.25 0 00-1.757 0L.879 3.697A2.25 2.25 0 000 5.754v4.492a2.25 2.25 0 00.879 1.757l6.243 2.818a2.25 2.25 0 001.757 0l6.243-2.818A2.25 2.25 0 0016 10.246V5.754a2.25 2.25 0 00-.879-1.757L8.879.879z" clip-rule="evenodd"/></svg>
            Mapa
        </a>
        <a href="{{ route('projects.print', $project->id) }}" target="_blank" class="tbl-btn" style="background:#f0fdf4;color:#14532d;border-color:#bbf7d0">
            <svg viewBox="0 0 16 16" fill="currentColor" class="w-3 h-3"><path d="M3.75 1A1.75 1.75 0 002 2.75v1.5H1.75a.75.75 0 000 1.5H2v5.5c0 .966.784 1.75 1.75 1.75h8.5A1.75 1.75 0 0014 11.25v-5.5h.25a.75.75 0 000-1.5H14v-1.5A1.75 1.75 0 0012.25 1h-8.5zm0 1.5h8.5a.25.25 0 01.25.25V4.25H3.5V2.75a.25.25 0 01.25-.25zM3.5 11.25v-5.5h9v5.5a.25.25 0 01-.25.25h-8.5a.25.25 0 01-.25-.25zM6 8a.75.75 0 000 1.5h4a.75.75 0 000-1.5H6z"/></svg>
            Print
        </a>
        <a href="{{ route('reports.project-appendix', $project->id) }}" target="_blank" class="tbl-btn" style="background:#faf5ff;color:#6b21a8;border-color:#e9d5ff">
            <svg viewBox="0 0 16 16" fill="currentColor" class="w-3 h-3"><path fill-rule="evenodd" d="M4 2a2 2 0 012-2h4.586A2 2 0 0112 .586L15.414 4A2 2 0 0116 5.414V14a2 2 0 01-2 2H4a2 2 0 01-2-2V4a2 2 0 012-2zm4.5 7.25a.75.75 0 000 1.5h3.25a.75.75 0 000-1.5H8.5zm-3 0a.75.75 0 000 1.5h.5a.75.75 0 000-1.5h-.5zm.75-3.25a.75.75 0 01.75-.75h5.25a.75.75 0 010 1.5H7a.75.75 0 01-.75-.75zm-2-2A.75.75 0 015.5 3h5.25a.75.75 0 010 1.5H5.5A.75.75 0 014.75 4z" clip-rule="evenodd"/></svg>
            Prilog 3
        </a>
        <a href="{{ route('projects.fiber-schema-pdf', $project->id) }}" class="tbl-btn" style="background:#fff7ed;color:#9a3412;border-color:#fed7aa">
            <svg viewBox="0 0 16 16" fill="currentColor" class="w-3 h-3"><path d="M1 3a1 1 0 000 2h1.25v6.75a.75.75 0 001.5 0V5h9.5v6.75a.75.75 0 001.5 0V5H16a1 1 0 100-2H1z"/></svg>
            Fiber sema
        </a>
        <a href="#" data-dxf-export="{{ route('projects.dxf', $project->id) }}" class="tbl-btn dxf-export-btn" style="background:#fef3c7;color:#92400e;border-color:#fde68a">
            <svg viewBox="0 0 16 16" fill="currentColor" class="w-3 h-3"><path d="M7.47 10.78a.75.75 0 001.06 0l3.75-3.75a.75.75 0 00-1.06-1.06L8.75 8.44V1.75a.75.75 0 00-1.5 0v6.69L4.78 5.97a.75.75 0 00-1.06 1.06l3.75 3.75zM3.75 13a.75.75 0 000 1.5h8.5a.75.75 0 000-1.5h-8.5z"/></svg>
            DXF
        </a>
    </div>
</div>

{{-- KPI STRIP --}}
<div class="mb-5 grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
    @foreach([
        ['ODF-ovi',    $project->odfs->count(),     'sky',    '<path fill-rule="evenodd" d="M2 5a2 2 0 012-2h12a2 2 0 012 2v2a2 2 0 01-2 2H4a2 2 0 01-2-2V5zm14 1a1 1 0 11-2 0 1 1 0 012 0zM2 13a2 2 0 012-2h12a2 2 0 012 2v2a2 2 0 01-2 2H4a2 2 0 01-2-2v-2zm14 1a1 1 0 11-2 0 1 1 0 012 0z" clip-rule="evenodd"/>'],
        ['ODO ormarići', $project->cabinets->count(), 'blue', '<path d="M4 3a2 2 0 100 4h12a2 2 0 100-4H4zM3 8h14v7a2 2 0 01-2 2H5a2 2 0 01-2-2V8zm5 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z"/>'],
        ['Kuće',        $project->houses->count(),   'violet','<path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"/>'],
        ['Trase',       $cableRoutes->count(),       'green', '<path fill-rule="evenodd" d="M12.316 3.051a1 1 0 01.633 1.265l-4 12a1 1 0 11-1.898-.632l4-12a1 1 0 011.265-.633zM5.707 6.293a1 1 0 010 1.414L3.414 10l2.293 2.293a1 1 0 11-1.414 1.414l-3-3a1 1 0 010-1.414l3-3a1 1 0 011.414 0zm8.586 0a1 1 0 011.414 0l3 3a1 1 0 010 1.414l-3 3a1 1 0 11-1.414-1.414L16.586 10l-2.293-2.293a1 1 0 010-1.414z" clip-rule="evenodd"/>'],
        ['Krakovi',     $project->branches->count(), 'amber', '<path fill-rule="evenodd" d="M5 3a2 2 0 00-2 2v1a2 2 0 002 2h1v3H5a2 2 0 00-2 2v1a2 2 0 002 2h1v1a1 1 0 102 0v-1h1a2 2 0 002-2v-1a2 2 0 00-2-2h-1V8h1a2 2 0 002-2V5a2 2 0 00-2-2H5zm0 2h4v1H5V5zm0 7h4v1H5v-1zm5-4h1v3h-1V8z" clip-rule="evenodd"/>'],
        ['Slobodni portovi', $freePorts,             'green', '<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v2H7a1 1 0 100 2h2v2a1 1 0 102 0v-2h2a1 1 0 100-2h-2V7z" clip-rule="evenodd"/>'],
    ] as [$label, $value, $color, $icon])
    <article class="stat-card">
        <div class="flex items-start justify-between gap-2">
            <div><div class="stat-label">{{ $label }}</div><div class="stat-value">{{ $value }}</div></div>
            <div class="stat-icon {{ $color }}"><svg viewBox="0 0 20 20" fill="currentColor">{!! $icon !!}</svg></div>
        </div>
    </article>
    @endforeach
</div>

<div class="grid gap-5 lg:grid-cols-3">

    {{-- LIJEVA KOLONA: Validacija + ODF kapacitet --}}
    <div class="space-y-5">

        {{-- VALIDACIJA --}}
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3.5">
                <h2 class="text-sm font-semibold text-slate-700">Validacija projekta</h2>
                <div class="flex gap-1.5">
                    @if($valErrors->count())<span class="rounded-full bg-red-50 px-2 py-0.5 text-xs font-bold text-red-600">{{ $valErrors->count() }} grešaka</span>@endif
                    @if($valWarnings->count())<span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-bold text-amber-600">{{ $valWarnings->count() }} upozorenja</span>@endif
                    @if(!$valErrors->count() && !$valWarnings->count())<span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-bold text-emerald-600">OK</span>@endif
                </div>
            </div>
            <div class="divide-y divide-slate-50 max-h-80 overflow-y-auto">
                @forelse($validationItems->whereIn('level', ['error', 'warning', 'info']) as $item)
                @php
                    $vColors = ['error' => ['bg-red-50','text-red-700','text-red-500'], 'warning' => ['bg-amber-50','text-amber-700','text-amber-500'], 'info' => ['bg-slate-50','text-slate-600','text-slate-400']];
                    [$vBg, $vText, $vSub] = $vColors[$item['level']] ?? ['bg-slate-50','text-slate-600','text-slate-400'];
                @endphp
                <div class="px-4 py-3 {{ $vBg }}">
                    <div class="flex items-start gap-2">
                        <span class="mt-0.5 shrink-0 text-[10px] font-bold uppercase {{ $vText }} opacity-60">{{ $item['level'] }}</span>
                        <div>
                            <div class="text-xs font-medium {{ $vText }}">{{ $item['message'] }}</div>
                            @if($item['recommendation'])<div class="text-[11px] {{ $vSub }} mt-0.5">{{ $item['recommendation'] }}</div>@endif
                        </div>
                    </div>
                </div>
                @empty
                <div class="px-5 py-4 text-sm text-slate-400">Nema validacijskih stavki.</div>
                @endforelse
            </div>
        </div>

        {{-- ODF KAPACITET --}}
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-3.5">
                <h2 class="text-sm font-semibold text-slate-700">Kapacitet ODF-a</h2>
            </div>
            <div class="divide-y divide-slate-50">
                @forelse($odfCapacity as $row)
                @php
                    $pct = $row['total'] > 0 ? min(100, round($row['used'] / $row['total'] * 100)) : 0;
                    $barColor = $pct > 100 ? '#ef4444' : ($pct >= 85 ? '#f59e0b' : '#22c55e');
                @endphp
                <div class="px-5 py-3">
                    <div class="mb-1.5 flex items-center justify-between">
                        <span class="text-xs font-medium text-slate-700">{{ $row['odf']->name }}</span>
                        <span class="text-xs text-slate-400">{{ $row['used'] }}/{{ $row['total'] }} vlakana</span>
                    </div>
                    <div class="h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full transition-all" style="width:{{ min($pct, 100) }}%;background:{{ $barColor }}"></div>
                    </div>
                    <div class="mt-1 text-[11px] text-slate-400">{{ $pct }}% iskorištenosti · {{ $row['odf']->address }}</div>
                </div>
                @empty
                <div class="px-5 py-4 text-sm text-slate-400">Projekat nema ODF-ova.</div>
                @endforelse
            </div>
        </div>

    </div>

    {{-- SREDNJA KOLONA: Trase + Krakovi --}}
    <div class="space-y-5">

        {{-- TRASE PO TIPU --}}
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-3.5">
                <h2 class="text-sm font-semibold text-slate-700">Trase</h2>
            </div>
            @if($cableRoutes->count())
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead><tr class="border-b border-slate-100">
                        <th class="px-4 py-2.5 font-semibold text-slate-500">Tip</th>
                        <th class="px-4 py-2.5 font-semibold text-slate-500 text-right">Kom.</th>
                        <th class="px-4 py-2.5 font-semibold text-slate-500 text-right">Dužina (m)</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-50">
                        @foreach($cableRoutes->groupBy('route_type') as $type => $group)
                        <tr>
                            <td class="px-4 py-2.5 font-medium text-slate-700">{{ $routeTypeLabels[$type] ?? $type }}</td>
                            <td class="px-4 py-2.5 text-right text-slate-500">{{ $group->count() }}</td>
                            <td class="px-4 py-2.5 text-right text-slate-700 font-medium">{{ number_format($group->sum('duct_length_m')) }}</td>
                        </tr>
                        @endforeach
                        @if($trenchRoutes->count())
                        <tr class="bg-slate-50">
                            <td class="px-4 py-2.5 text-slate-400 italic">Glavni rovovi</td>
                            <td class="px-4 py-2.5 text-right text-slate-400">{{ $trenchRoutes->count() }}</td>
                            <td class="px-4 py-2.5 text-right text-slate-400">{{ number_format($trenchRoutes->sum('duct_length_m')) }}</td>
                        </tr>
                        @endif
                        <tr class="border-t border-slate-200 font-semibold bg-slate-50">
                            <td class="px-4 py-2.5 text-slate-700">Ukupno kablovi</td>
                            <td class="px-4 py-2.5 text-right text-slate-700">{{ $cableRoutes->count() }}</td>
                            <td class="px-4 py-2.5 text-right text-slate-800">{{ number_format($cableRoutes->sum('duct_length_m')) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            @else
            <div class="px-5 py-4 text-sm text-slate-400">Projekat nema trasa.</div>
            @endif
        </div>

        {{-- KRAKOVI --}}
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-3.5">
                <h2 class="text-sm font-semibold text-slate-700">Krakovi mreže</h2>
            </div>
            @if($project->branches->count())
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead><tr class="border-b border-slate-100">
                        <th class="px-4 py-2.5 font-semibold text-slate-500">Naziv</th>
                        <th class="px-4 py-2.5 font-semibold text-slate-500">Tip</th>
                        <th class="px-4 py-2.5 font-semibold text-slate-500 text-right">ODO</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-50">
                        @foreach($project->branches as $branch)
                        @php
                            $typeLabels = ['primary' => 'Primarni', 'secondary' => 'Sekundarni', 'rov' => 'Glavni rov'];
                            $typeBadge  = ['primary' => 'violet', 'secondary' => 'blue', 'rov' => 'amber'];
                        @endphp
                        <tr>
                            <td class="px-4 py-2.5 font-medium text-slate-700">{{ $branch->name }}</td>
                            <td class="px-4 py-2.5">
                                <span class="ftth-badge {{ $typeBadge[$branch->type] ?? 'slate' }}"><span class="ftth-badge-dot"></span>{{ $typeLabels[$branch->type] ?? $branch->type }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-right text-slate-500">{{ $branch->cabinets_count }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <div class="px-5 py-4 text-sm text-slate-400">Projekat nema krakova.</div>
            @endif
        </div>

    </div>

    {{-- DESNA KOLONA: Materijali + Popunjenost ODO --}}
    <div class="space-y-5">

        {{-- MATERIJALI --}}
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-3.5">
                <h2 class="text-sm font-semibold text-slate-700">Materijali (sa {{ $materials['reserve_percent'] }}% rezerve)</h2>
            </div>
            <div class="divide-y divide-slate-50">
                @foreach([
                    ['Mikrocijev 14/10', $materials['microduct_14_10_m'], 'm'],
                    ['Mikrocijev 10/8',  $materials['microduct_10_8_m'],  'm'],
                    ['Optika 4 niti',   $materials['fiber_4_m'],         'm'],
                    ['Optika 12 niti',  $materials['fiber_12_m'],        'm'],
                    ['Optika 24 niti',  $materials['fiber_24_m'],        'm'],
                    ['Optika 48 niti',  $materials['fiber_48_m'],        'm'],
                ] as [$label, $base, $unit])
                @if($base > 0)
                @php $withRes = (int) ceil($base * (1 + $materials['reserve_percent'] / 100)); @endphp
                <div class="flex items-center justify-between px-5 py-2.5">
                    <span class="text-xs text-slate-600">{{ $label }}</span>
                    <div class="text-right">
                        <span class="text-xs font-semibold text-slate-800">{{ number_format($withRes) }} {{ $unit }}</span>
                        <span class="ml-1 text-[11px] text-slate-400">({{ number_format($base) }} + rez.)</span>
                    </div>
                </div>
                @endif
                @endforeach
                <div class="flex items-center justify-between bg-slate-50 px-5 py-2.5">
                    <span class="text-xs font-semibold text-slate-600">ODF-ovi</span>
                    <span class="text-xs font-bold text-slate-800">{{ $materials['odf_count'] }} kom.</span>
                </div>
                <div class="flex items-center justify-between px-5 py-2.5">
                    <span class="text-xs font-semibold text-slate-600">ODO ormarići</span>
                    <span class="text-xs font-bold text-slate-800">{{ $materials['odo_count'] }} kom.</span>
                </div>
                <div class="flex items-center justify-between bg-slate-50 px-5 py-2.5">
                    <span class="text-xs font-semibold text-slate-600">Splitteri 1/4</span>
                    <span class="text-xs font-bold text-slate-800">{{ $materials['splitter_count'] }} kom.</span>
                </div>
                @if($materials['estimated_value'] > 0)
                <div class="flex items-center justify-between px-5 py-2.5 border-t border-slate-200">
                    <span class="text-xs font-semibold text-slate-600">Procjenjena vrijednost</span>
                    <span class="text-xs font-bold text-slate-800">{{ number_format($materials['estimated_value'], 2) }} KM</span>
                </div>
                @endif
            </div>
        </div>

        {{-- POPUNJENOST ODO --}}
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-3.5">
                <h2 class="text-sm font-semibold text-slate-700">Popunjenost ODO ormarića</h2>
            </div>
            <div class="px-5 py-4 space-y-3">
                <div class="flex items-end justify-between">
                    <div>
                        <div class="text-2xl font-bold text-slate-900">{{ $utilPct }}%</div>
                        <div class="text-xs text-slate-400 mt-0.5">{{ $usedPorts }}/{{ $capacity }} portova</div>
                    </div>
                    <div class="text-right text-xs text-slate-400">
                        <div>{{ $freePorts }} slobodnih portova</div>
                        <div>{{ $project->cabinets->count() }} ormarića</div>
                    </div>
                </div>
                <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full rounded-full transition-all" style="width:{{ $utilPct }}%;background:{{ $utilColor }}"></div>
                </div>
            </div>
            @if($project->cabinets->count())
            <div class="border-t border-slate-100 divide-y divide-slate-50 max-h-56 overflow-y-auto">
                @foreach($project->cabinets->sortBy('name') as $cab)
                @php
                    $cabPct = $cab->capacity > 0 ? min(100, round($cab->houses_count / $cab->capacity * 100)) : 0;
                    $cabColor = $cabPct >= 90 ? '#ef4444' : ($cabPct >= 70 ? '#f59e0b' : '#22c55e');
                @endphp
                <div class="flex items-center gap-3 px-5 py-2">
                    <div class="min-w-0 flex-1">
                        <div class="text-xs font-medium text-slate-700 truncate">{{ $cab->name }}</div>
                        <div class="mt-0.5 h-1 w-full overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full" style="width:{{ $cabPct }}%;background:{{ $cabColor }}"></div>
                        </div>
                    </div>
                    <span class="shrink-0 text-[11px] text-slate-400">{{ $cab->houses_count }}/{{ $cab->capacity }}</span>
                </div>
                @endforeach
            </div>
            @endif
        </div>

    </div>

</div>

@push('scripts')
<script>
(function () {
    const DB = 'ftth_dxf_v1', ST = 'layers', VER = 1;
    let _db = null;
    function openDb() {
        if (_db) return Promise.resolve(_db);
        return new Promise((res, rej) => {
            const r = indexedDB.open(DB, VER);
            r.onupgradeneeded = e => { if (!e.target.result.objectStoreNames.contains(ST)) e.target.result.createObjectStore(ST, { keyPath: 'dbId' }); };
            r.onsuccess = e => { _db = e.target.result; res(_db); };
            r.onerror   = e => rej(e.target.error);
        });
    }
    async function getExportLayers() {
        try {
            const db  = await openDb();
            const all = await new Promise((res, rej) => { const tx = db.transaction(ST, 'readonly'); const rq = tx.objectStore(ST).getAll(); rq.onsuccess = e => res(e.target.result || []); rq.onerror = e => rej(e.target.error); });
            const result = []; let missingKey = 0;
            for (const s of all) { const ck = s.cacheKey || s.geojson?._cache_key || null; if (ck) result.push({ cache_key: ck, color: s.color }); else if (s.geojson?.features?.length) missingKey++; }
            if (missingKey > 0) alert(`${missingKey} DXF podloga nema cache ključ — mora se ponovo importovati.`);
            if (result.length === 0 && missingKey === 0) alert('Nema sačuvanih DXF podloga u browseru.');
            return result;
        } catch { return []; }
    }
    document.addEventListener('click', async function (e) {
        const btn = e.target.closest('.dxf-export-btn');
        if (!btn) return;
        e.preventDefault();
        const url = btn.getAttribute('data-dxf-export');
        if (!url) return;
        const orig = btn.innerHTML;
        btn.textContent = 'Pripremam…'; btn.style.pointerEvents = 'none';
        try {
            const bgLayers = await getExportLayers();
            btn.textContent = bgLayers.length > 0 ? `Export (${bgLayers.length} DXF)…` : 'Export (bez podloge)…';
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
            const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/octet-stream,application/dxf,*/*' }, body: JSON.stringify({ background_layers: bgLayers }) });
            if (!res.ok) { let msg = 'HTTP ' + res.status; try { const j = await res.json(); if (j.error) msg = j.error; } catch {} throw new Error(msg); }
            const blob = await res.blob();
            const a = document.createElement('a');
            const cd = res.headers.get('Content-Disposition') ?? '';
            a.download = cd.match(/filename[^;=\n]*=["']?([^"'\n]+)/i)?.[1] ?? 'export.dxf';
            a.href = URL.createObjectURL(blob);
            document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(a.href);
        } catch (err) { alert('Greška pri DXF exportu: ' + err.message); }
        finally { btn.innerHTML = orig; btn.style.pointerEvents = ''; }
    });
})();
</script>
@endpush

@endsection
