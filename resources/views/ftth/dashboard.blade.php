@extends('ftth.layout')
@section('title', 'Pregled')
@section('subtitle', 'Stanje projekata, mreže i otvorenih stavki.')
@section('own_heading', '1')
@section('content')
@php
    $n = fn ($value, int $decimals = 0) => number_format((float) $value, $decimals, ',', '.');
    $current = $projectCards->first();
    $hour = (int) now()->format('G');
    $greeting = $hour < 11 ? 'Dobro jutro' : ($hour < 18 ? 'Dobar dan' : 'Dobro veče');
    $firstName = \Illuminate\Support\Str::before(auth()->user()->name, ' ');
    $days = ['nedjelja', 'ponedjeljak', 'utorak', 'srijeda', 'četvrtak', 'petak', 'subota'];
    $issueLabel = fn (int $count) => $count.' '.($count === 1 ? 'problem' : 'problema');
    // Readable "what happened" for the activity feed.
    $subject = function (?string $route): string {
        $route = (string) $route;
        foreach ([
            'map-notes' => 'napomena na mapi', 'fiber' => 'fiber šema', 'pole-branches' => 'krakovi stubova', 'survey-points' => 'terenske tačke',
            'map.plan' => 'plan na mapi', 'map.draft' => 'nacrt mape', 'snapshots' => 'verzija projekta', 'backup' => 'sigurnosna kopija',
            'cabinets' => 'ODO ormarić', 'houses' => 'kuća', 'routes' => 'trasa', 'odfs' => 'ODF', 'branches' => 'krak', 'appendix' => 'prilog projekta',
            'users' => 'korisnik', 'sessions' => 'sesija', 'gis' => 'GIS sloj', 'settings' => 'postavke', 'materials' => 'materijal', 'projects' => 'projekat',
        ] as $needle => $label) {
            if (str_contains($route, $needle)) {
                return $label;
            }
        }

        return $route !== '' ? $route : 'izmjena';
    };
    $verb = fn (string $method) => ['POST' => 'Novo', 'PUT' => 'Izmjena', 'PATCH' => 'Izmjena', 'DELETE' => 'Brisanje'][$method] ?? $method;
    $capacityTone = $stats['capacity_percent'] >= 90 ? 'is-high' : ($stats['capacity_percent'] >= 70 ? 'is-mid' : 'is-low');
    $ring = 2 * M_PI * 42;
@endphp

<div class="db">
    {{-- Zaglavlje --}}
    <header class="db-head">
        <div>
            <p>{{ ucfirst($days[(int) now()->format('w')]) }}, {{ now()->format('d.m.Y.') }}</p>
            <h1>{{ $greeting }}, {{ $firstName }}</h1>
        </div>
        <div class="db-head-actions">
            <a href="{{ route('map.dashboard', $current ? ['project' => $current['model']->id] : []) }}" class="db-btn">Otvori mapu</a>
            @can('project.edit')<a href="{{ route('projects.index') }}" class="db-btn db-btn-primary">+ Novi projekat</a>@endcan
        </div>
    </header>

    {{-- Nastavi rad + stanje mreže --}}
    <section class="db-top">
        <article class="db-resume">
            @if($current)
                @php $p = $current['model']; @endphp
                <div class="db-resume-head">
                    <span class="db-avatar">{{ mb_strtoupper(mb_substr($p->name, 0, 2)) }}</span>
                    <div class="db-resume-title">
                        <small>Nastavi rad · izmijenjeno {{ $p->updated_at?->diffForHumans() }}</small>
                        <h2>{{ $p->name }}</h2>
                        <p>{{ $p->code }}{{ $p->location ? ' · '.$p->location : '' }}</p>
                    </div>
                    @if($current['errors'])<span class="db-pill is-bad">{{ $issueLabel($current['issues']) }}</span>
                    @elseif($current['issues'])<span class="db-pill is-warn">{{ $issueLabel($current['issues']) }}</span>
                    @else<span class="db-pill is-good">Uredno</span>@endif
                </div>
                <div class="db-resume-stats">
                    <div><b>{{ $n($p->odfs_count) }}</b><span>ODF</span></div>
                    <div><b>{{ $n($p->cabinets_count) }}</b><span>ODO</span></div>
                    <div><b>{{ $n($p->houses_count) }}</b><span>kuća · {{ $current['connected_percent'] }}% vezano</span></div>
                    <div><b>{{ $n($current['route_km'], 2) }}</b><span>km trasa</span></div>
                    <div><b>{{ $current['used'] }}/{{ $current['capacity'] }}</b><span>portova · {{ $current['utilization'] }}%</span></div>
                </div>
                <nav class="db-resume-links">
                    <a href="{{ route('map.dashboard', ['project' => $p->id]) }}" class="is-main">Mapa</a>
                    <a href="{{ route('fiber-schema.index', ['project' => $p->id]) }}">Fiber šema</a>
                    <a href="{{ route('reports.index', ['project' => $p->id]) }}">Izvještaj</a>
                    <a href="{{ route('project-check.index', ['project' => $p->id]) }}">Provjera</a>
                    <a href="{{ route('projects.show', $p) }}">Detalji projekta</a>
                </nav>
            @else
                <div class="db-empty-start">
                    <h2>Kreiraj prvi FTTH projekat</h2>
                    <p>Projekat okuplja ODF, ODO ormariće, kuće i trase. Nakon toga ga crtaš na mapi.</p>
                    <a href="{{ route('projects.index') }}" class="db-btn db-btn-primary">+ Novi projekat</a>
                </div>
            @endif
        </article>

        <article class="db-capacity">
            <h3>Stanje mreže</h3>
            <div class="db-ring-wrap">
                <svg viewBox="0 0 100 100" class="db-ring {{ $capacityTone }}" role="img" aria-label="Popunjenost portova {{ $stats['capacity_percent'] }}%">
                    <circle cx="50" cy="50" r="42" class="track"/>
                    <circle cx="50" cy="50" r="42" class="value" stroke-dasharray="{{ round($ring * $stats['capacity_percent'] / 100, 2) }} {{ round($ring, 2) }}"/>
                </svg>
                <div class="db-ring-label"><b>{{ $stats['capacity_percent'] }}%</b><span>portova zauzeto</span></div>
            </div>
            <dl>
                <div><dt>Zauzeto / ukupno</dt><dd>{{ $n($stats['used_ports']) }} / {{ $n($stats['total_ports']) }}</dd></div>
                <div><dt>Slobodnih portova</dt><dd>{{ $n($stats['free_ports']) }}</dd></div>
                <div><dt>Kuće vezane na ODO</dt><dd>{{ $stats['connected_percent'] }}%</dd></div>
            </dl>
        </article>
    </section>

    {{-- Brojevi --}}
    <section class="db-kpis">
        <div><span>Projekti</span><b>{{ $n($stats['projects']) }}</b></div>
        <div><span>ODF centri</span><b>{{ $n($stats['odfs']) }}</b></div>
        <div><span>ODO ormarići</span><b>{{ $n($stats['cabinets']) }}</b></div>
        <div><span>Kuće</span><b>{{ $n($stats['houses']) }}</b></div>
        <div><span>Trase</span><b>{{ $n($stats['route_km'], 2) }} <em>km</em></b></div>
    </section>

    {{-- Projekti + pažnja --}}
    <section class="db-grid">
        <article class="db-card">
            <header><h3>Projekti</h3><a href="{{ route('projects.index') }}">Svi projekti →</a></header>
            <div class="db-projects">
                @forelse($projectCards->take(8) as $item)
                    @php $project = $item['model']; @endphp
                    <a href="{{ route('map.dashboard', ['project' => $project->id]) }}" class="db-project">
                        <span class="db-avatar is-small">{{ mb_strtoupper(mb_substr($project->name, 0, 2)) }}</span>
                        <span class="db-project-name"><b>{{ $project->name }}</b><small>{{ $project->code }}{{ $project->location ? ' · '.$project->location : '' }}</small></span>
                        <span class="db-project-infra">{{ $n($project->odfs_count) }} ODF · {{ $n($project->cabinets_count) }} ODO · {{ $n($project->houses_count) }} kuća</span>
                        <span class="db-project-cap"><small>{{ $item['used'] }} / {{ $item['capacity'] }} portova</small><i><b style="width:{{ $item['utilization'] }}%"></b></i></span>
                        <span class="db-project-state">
                            @if($item['errors'])<span class="db-pill is-bad">{{ $issueLabel($item['issues']) }}</span>
                            @elseif($item['issues'])<span class="db-pill is-warn">{{ $issueLabel($item['issues']) }}</span>
                            @else<span class="db-pill is-good">Uredno</span>@endif
                        </span>
                    </a>
                @empty
                    <p class="db-muted">Nema kreiranih projekata.</p>
                @endforelse
            </div>
        </article>

        <div class="db-side">
            <article class="db-card">
                <header><h3>Zahtijeva pažnju</h3><a href="{{ route('project-check.index') }}">Provjera →</a></header>
                <div class="db-list">
                    @forelse($attentionProjects as $item)
                        <a href="{{ route('project-check.index', ['project' => $item['model']->id]) }}">
                            <i class="{{ $item['errors'] ? 'is-bad' : 'is-warn' }}"></i>
                            <span><b>{{ $item['model']->name }}</b><small>{{ $item['errors'] ? $item['errors'].' grešaka · ' : '' }}{{ $item['warnings'] }} upozorenja</small></span>
                            <em>›</em>
                        </a>
                    @empty
                        <p class="db-ok">✓ Nijedan projekat nema grešaka ni upozorenja.</p>
                    @endforelse
                </div>
            </article>

            <article class="db-card">
                <header><h3>Napomene na mapi</h3>@if($openNotesTotal)<span class="db-count">{{ $openNotesTotal }} {{ $openNotesTotal % 10 === 1 && $openNotesTotal % 100 !== 11 ? 'otvorena' : (in_array($openNotesTotal % 10, [2, 3, 4], true) && ! in_array($openNotesTotal % 100, [12, 13, 14], true) ? 'otvorene' : 'otvorenih') }}</span>@endif</header>
                <div class="db-list">
                    @forelse($openNotes as $note)
                        <a href="{{ route('map.dashboard', ['project' => $note->project_id]) }}">
                            <i class="is-note"></i>
                            <span><b>{{ \Illuminate\Support\Str::limit($note->body, 70) }}</b><small>{{ $note->project?->name }} · {{ $note->user?->name ?? 'nepoznat' }} · {{ $note->created_at->diffForHumans() }}</small></span>
                            <em>›</em>
                        </a>
                    @empty
                        <p class="db-muted">Nema otvorenih napomena. Napomene se pišu na mapi, dugmetom „Napomene“.</p>
                    @endforelse
                </div>
            </article>
        </div>
    </section>

    {{-- Aktivnost + verzije --}}
    <section class="db-grid">
        <article class="db-card">
            <header><h3>Posljednje izmjene</h3>@can('settings.manage')<a href="{{ route('settings.index') }}#audit">Sve izmjene →</a>@endcan</header>
            <ul class="db-feed">
                @forelse($recentActivity as $activity)
                    <li>
                        <span class="db-verb db-verb-{{ strtolower($activity->method) }}">{{ $verb($activity->method) }}</span>
                        <span class="db-feed-text"><b>{{ ucfirst($subject($activity->route_name ?: $activity->path)) }}</b>@if($activity->project)<small> · {{ $activity->project->name }}</small>@endif</span>
                        <span class="db-feed-meta">{{ $activity->user?->name ?? 'Sistem' }} · {{ $activity->created_at->diffForHumans() }}</span>
                    </li>
                @empty
                    <li class="db-muted">Još nema zabilježenih izmjena.</li>
                @endforelse
            </ul>
        </article>

        <article class="db-card">
            <header><h3>Sigurne verzije</h3></header>
            <div class="db-list">
                @forelse($latestSnapshots as $snapshot)
                    <a href="{{ route('map.dashboard', ['project' => $snapshot->project_id]) }}">
                        <i class="is-safe"></i>
                        <span><b>{{ $snapshot->label }}</b><small>{{ $snapshot->project?->name ?? 'Projekat' }} · {{ $snapshot->created_at->diffForHumans() }}</small></span>
                        <em>›</em>
                    </a>
                @empty
                    <p class="db-muted">Još nema sačuvanih verzija. Verzija se pravi na mapi, dugmetom „Verzije“.</p>
                @endforelse
            </div>
        </article>
    </section>

    <footer class="db-footer">FTTH Manager · verzija {{ config('app.version') }}</footer>
</div>
@endsection
