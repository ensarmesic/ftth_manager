@extends('ftth.layout')
@section('title', 'Provjera projekta')
@section('subtitle', 'Automatska kontrola veza, kapaciteta i tehničkih podataka projekta.')
@section('content')

@php
    $allChecks = $projects->map(function($project) {
        $checks = collect();
        if (!$project->odfs_count) $checks->push(['error', 'Nedostaje ODF lokacija za projekat.', route('map.dashboard', ['project' => $project->id]), 'Dodaj na mapi']);
        $project->cabinets->whereNull('odf_id')->each(fn ($c) => $checks->push(['warning', "{$c->name} nema povezani ODF.", route('map.dashboard', ['project' => $project->id, 'focus_type' => 'cabinet', 'focus_id' => $c->id]), 'Prikaži na mapi']));
        $project->cabinets->filter(fn ($c) => $c->houses_count > $c->capacity)->each(fn ($c) => $checks->push(['error', "{$c->name} prelazi kapacitet ({$c->houses_count}/{$c->capacity}).", route('map.dashboard', ['project' => $project->id, 'focus_type' => 'cabinet', 'focus_id' => $c->id]), 'Prikaži na mapi']));
        $project->houses->whereNull('cabinet_id')->each(fn ($h) => $checks->push(['warning', "{$h->label} nema dodijeljeni ODO ormarić.", route('map.dashboard', ['project' => $project->id, 'focus_type' => 'house', 'focus_id' => $h->id]), 'Prikaži na mapi']));
        $project->routes->filter(fn ($r) => $r->route_type !== 'trench' && (!$r->microduct_type || !$r->fiber_count))->each(fn ($r) => $checks->push(['warning', "{$r->name} nema kompletne podatke o mikrocijevi ili kablu.", route('map.dashboard', ['project' => $project->id, 'focus_type' => 'route', 'focus_id' => $r->id]), 'Prikaži na mapi']));
        return ['project' => $project, 'checks' => $checks];
    });
    $totalErrors   = $allChecks->sum(fn($p) => $p['checks']->where(0, 'error')->count());
    $totalWarnings = $allChecks->sum(fn($p) => $p['checks']->where(0, 'warning')->count());
    $projectsOk    = $allChecks->filter(fn($p) => $p['checks']->isEmpty())->count();
@endphp

<section class="mb-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div><div class="stat-label">Projekata</div><div class="stat-value">{{ $projects->count() }}</div></div>
            <div class="stat-icon blue"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" clip-rule="evenodd"/></svg></div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div><div class="stat-label">Bez problema</div><div class="stat-value" style="color:#059669">{{ $projectsOk }}</div></div>
            <div class="stat-icon green"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg></div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div><div class="stat-label">Greške</div><div class="stat-value" style="color:#dc2626">{{ $totalErrors }}</div></div>
            <div class="stat-icon red"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg></div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div><div class="stat-label">Upozorenja</div><div class="stat-value" style="color:#d97706">{{ $totalWarnings }}</div></div>
            <div class="stat-icon amber"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg></div>
        </div>
    </article>
</section>

<section class="grid gap-4">
    @forelse($allChecks as $item)
        @php
            $project   = $item['project'];
            $checks    = $item['checks'];
            $errCnt    = $checks->where(0, 'error')->count();
            $warnCnt   = $checks->where(0, 'warning')->count();
        @endphp
        <article class="check-card" style="border-left:3px solid {{ $errCnt ? '#dc2626' : ($warnCnt ? '#f59e0b' : '#10b981') }}">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div class="flex items-center gap-3 min-w-0">
                    @if($checks->isEmpty())
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full" style="background:#d1fae5">
                            <svg viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5" style="color:#059669"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                        </div>
                    @elseif($errCnt)
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full" style="background:#fee2e2">
                            <svg viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5" style="color:#dc2626"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                        </div>
                    @else
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full" style="background:#fef3c7">
                            <svg viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5" style="color:#d97706"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                        </div>
                    @endif
                    <div class="min-w-0">
                        <h2 class="font-bold text-slate-900">{{ $project->name }}</h2>
                        <p class="text-xs text-slate-400 mt-0.5">{{ $project->code }}@if($project->location) &bull; {{ $project->location }}@endif</p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-1.5 shrink-0">
                    <a href="{{ route('map.dashboard', ['project' => $project->id]) }}" class="check-header-action">Mapa</a>
                    <a href="{{ route('projects.show', $project) }}" class="check-header-action">Pregled</a>
                    @if($errCnt)
                        <span class="ftth-badge red"><span class="ftth-badge-dot"></span>{{ $errCnt }} {{ $errCnt === 1 ? 'greška' : ($errCnt < 5 ? 'greške' : 'grešaka') }}</span>
                    @endif
                    @if($warnCnt)
                        <span class="ftth-badge amber"><span class="ftth-badge-dot"></span>{{ $warnCnt }} upozorenja</span>
                    @endif
                    @if($checks->isEmpty())
                        <span class="ftth-badge green"><span class="ftth-badge-dot"></span>Sve uredu</span>
                    @endif
                </div>
            </div>

            <div class="flex flex-wrap gap-x-6 gap-y-1 border-b border-slate-50 bg-slate-50/50 px-5 py-2.5">
                @foreach([
                    ['ODF', $project->odfs_count, $project->odfs_count ? '#059669' : '#dc2626'],
                    ['Ormarića', $project->cabinets_count, '#0ea5e9'],
                    ['Kuća', $project->houses_count, '#6366f1'],
                    ['Trasa', $project->routes->where('route_type', '!=', 'trench')->count(), '#8b5cf6'],
                ] as [$lbl, $cnt, $clr])
                    <div class="flex items-center gap-1.5 text-xs text-slate-500">
                        <span class="font-bold text-sm" style="color:{{ $clr }}">{{ $cnt }}</span>
                        <span>{{ $lbl }}</span>
                    </div>
                @endforeach
            </div>

            <div class="grid gap-2 p-5">
                @forelse($checks as [$level, $message, $action, $actionLabel])
                    <div class="check-item {{ $level === 'warning' ? 'warn' : $level }}">
                        <div class="check-item-dot"></div>
                        <span class="check-item-message">{{ $message }}</span>
                        <a class="check-item-action" href="{{ $action }}">{{ $actionLabel }} <b>→</b></a>
                    </div>
                @empty
                    <div class="check-item ok">
                        <div class="check-item-dot"></div>
                        <span>Nisu pronađene tehničke greške ni upozorenja. Projekat je tehnički ispravan.</span>
                    </div>
                @endforelse
            </div>
        </article>
    @empty
        @include('ftth.partials.empty-state', ['title' => 'Nema projekata', 'message' => 'Provjera projekata će biti dostupna kada dodate prvi projekat.'])
    @endforelse
</section>
@endsection
