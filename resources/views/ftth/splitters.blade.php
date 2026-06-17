@extends('ftth.layout')
@section('title', 'Splitteri')
@section('subtitle', 'Pregled zauzeća splittera i raspoloživih portova po ODO ormariću.')
@section('content')

@php
    $capacity = $cabinets->sum(fn ($cabinet) => $cabinet->capacity);
    $used = $cabinets->sum('houses_count');
@endphp

<section class="mb-3 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div>
                <div class="stat-label">ODO ormarići</div>
                <div class="stat-value">{{ $cabinets->count() }}</div>
            </div>
            <div class="stat-icon blue">
                <svg viewBox="0 0 20 20" fill="currentColor"><path d="M4 3a2 2 0 100 4h12a2 2 0 100-4H4zM3 8h14v7a2 2 0 01-2 2H5a2 2 0 01-2-2V8zm5 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z"/></svg>
            </div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div>
                <div class="stat-label">Splitteri 1:4</div>
                <div class="stat-value">{{ $cabinets->sum('splitter_count') }}</div>
            </div>
            <div class="stat-icon violet">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 4a1 1 0 011-1h4a1 1 0 010 2H6.414l2.293 2.293a1 1 0 01-1.414 1.414L5 6.414V8a1 1 0 01-2 0V4zm9 1a1 1 0 010-2h4a1 1 0 011 1v4a1 1 0 01-2 0V6.414l-2.293 2.293a1 1 0 11-1.414-1.414L13.586 5H12zm-9 7a1 1 0 012 0v1.586l2.293-2.293a1 1 0 111.414 1.414L6.414 15H8a1 1 0 010 2H4a1 1 0 01-1-1v-4zm13-1a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 010-2h1.586l-2.293-2.293a1 1 0 111.414-1.414L15 13.586V12a1 1 0 011-1z" clip-rule="evenodd"/></svg>
            </div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div>
                <div class="stat-label">Ukupno portova</div>
                <div class="stat-value">{{ $capacity }}</div>
            </div>
            <div class="stat-icon sky">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 3a1 1 0 000 2v8a2 2 0 002 2h2.586l-1.293 1.293a1 1 0 101.414 1.414L10 15.414l2.293 2.293a1 1 0 001.414-1.414L12.414 15H15a2 2 0 002-2V5a1 1 0 100-2H3zm11 4a1 1 0 10-2 0v4a1 1 0 102 0V7zm-3 1a1 1 0 10-2 0v3a1 1 0 102 0V8zM8 9a1 1 0 00-2 0v2a1 1 0 102 0V9z" clip-rule="evenodd"/></svg>
            </div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div>
                <div class="stat-label">Slobodni portovi</div>
                <div class="stat-value">{{ max($capacity - $used, 0) }}</div>
            </div>
            <div class="stat-icon green">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v2H7a1 1 0 100 2h2v2a1 1 0 102 0v-2h2a1 1 0 100-2h-2V7z" clip-rule="evenodd"/></svg>
            </div>
        </div>
    </article>
</section>

<div class="app-table-card">
    <div class="tbl-card-header">
        <h2>Kapacitet po ODO-u</h2>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead>
                <tr><th>ODO</th><th>Projekat</th><th>ODF</th><th>Splitteri</th><th>Kuće</th><th>Portovi</th><th>Zauzeće</th></tr>
            </thead>
            <tbody>
                @forelse($cabinets as $cabinet)
                    @php
                        $usedPorts = $cabinet->houses_count;
                        $percent = $cabinet->capacity ? min(round($usedPorts / $cabinet->capacity * 100), 100) : 0;
                    @endphp
                    <tr>
                        <td class="font-semibold">{{ $cabinet->name }}</td>
                        <td>{{ $cabinet->project->name }}</td>
                        <td>{{ $cabinet->odf->name ?? '—' }}</td>
                        <td>{{ $cabinet->splitter_count }} × 1:4</td>
                        <td>{{ $usedPorts }}</td>
                        <td>
                            <span class="ftth-badge {{ $usedPorts >= $cabinet->capacity ? 'red' : ($percent >= 80 ? 'amber' : 'green') }}">
                                <span class="ftth-badge-dot"></span>
                                {{ $usedPorts }}/{{ $cabinet->capacity }}
                            </span>
                        </td>
                        <td class="min-w-44">
                            <div class="flex items-center gap-2">
                                <div class="h-1.5 w-24 rounded-full bg-slate-100">
                                    <div class="h-1.5 rounded-full transition-all {{ $percent > 90 ? 'bg-red-500' : ($percent > 70 ? 'bg-amber-400' : 'bg-emerald-500') }}" style="width:{{ $percent }}%"></div>
                                </div>
                                <b class="text-xs font-bold text-slate-700">{{ $percent }}%</b>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">@include('ftth.partials.empty-state', ['title' => 'Nema ormarića', 'message' => 'Dodaj ODO ormarić da vidite pregled splittera.'])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
