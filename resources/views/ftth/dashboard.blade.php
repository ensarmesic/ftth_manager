@extends('ftth.layout')
@section('title', 'Kontrolni centar')
@section('subtitle', 'Operativni pregled FTTH infrastrukture i projektnih rizika.')
@section('content')

<section class="ops-toolbar ops-hero">
    <div class="ops-hero-copy">
        <span class="ops-live"><i></i> FTTH projektni prostor</span>
        <h2>Od prve tačke do kompletne <em>optičke mreže.</em></h2>
        <p>Projektujte trase, rasporedite ODF i ODO elemente i pripremite tehničku dokumentaciju na jednom mjestu.</p>
        <div class="ops-hero-capabilities" aria-label="Mogućnosti aplikacije">
            <span><i></i> GIS projektovanje</span>
            <span><i></i> Automatsko rutiranje</span>
            <span><i></i> Tehnička dokumentacija</span>
        </div>
        <small>Podaci osvježeni {{ now()->format('d.m.Y. \u H:i') }}</small>
    </div>
    <div class="ops-hero-visual" aria-hidden="true">
        <svg viewBox="0 0 470 190" role="img">
            <defs>
                <linearGradient id="fiberLine" x1="0" x2="1"><stop stop-color="#16b8e7"/><stop offset="1" stop-color="#20b77a"/></linearGradient>
                <filter id="fiberGlow"><feGaussianBlur stdDeviation="3" result="blur"/><feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge></filter>
            </defs>
            <path class="fiber-route route-a" d="M50 92 C115 92 112 42 190 42 S274 70 330 70 L417 35"/>
            <path class="fiber-route route-b" d="M50 92 C118 92 130 139 205 139 L300 139 L417 105"/>
            <path class="fiber-route route-c" d="M190 42 C230 42 247 98 300 98 L417 157"/>
            <g class="fiber-node node-odf" transform="translate(50 92)"><circle r="20"/><circle r="5"/><text y="35">ODF</text></g>
            <g class="fiber-node node-odo" transform="translate(190 42)"><circle r="15"/><circle r="4"/><text y="29">ODO 01</text></g>
            <g class="fiber-node node-odo" transform="translate(205 139)"><circle r="15"/><circle r="4"/><text y="29">ODO 02</text></g>
            <g class="fiber-home" transform="translate(417 35)"><path d="M-12 1 0-10 12 1v13H3V6h-6v8h-9z"/><text y="29">HP</text></g>
            <g class="fiber-home" transform="translate(417 105)"><path d="M-12 1 0-10 12 1v13H3V6h-6v8h-9z"/><text y="29">HP</text></g>
            <g class="fiber-home" transform="translate(417 157)"><path d="M-12 1 0-10 12 1v13H3V6h-6v8h-9z"/><text y="29">HP</text></g>
            <circle class="fiber-pulse p1" r="4"><animateMotion dur="4s" repeatCount="indefinite" path="M50 92 C115 92 112 42 190 42 S274 70 330 70 L417 35"/></circle>
            <circle class="fiber-pulse p2" r="4"><animateMotion dur="5s" repeatCount="indefinite" path="M50 92 C118 92 130 139 205 139 L300 139 L417 105"/></circle>
        </svg>
        <span class="ops-hero-visual-label">INTELIGENTNO FTTH PROJEKTOVANJE</span>
    </div>
    <div class="ops-toolbar-actions">
        <a href="{{ route('project-check.index') }}" class="ops-button secondary">Provjera projekta</a>
        <a href="{{ route('projects.index') }}" class="ops-button primary">Novi projekat <span>+</span></a>
    </div>
</section>

<section class="ops-overview">
    @php($current = $projectCards->first())
    <article class="ops-resume-card">
        @if($current)
            <div class="ops-resume-top"><span>AKTIVNI PROJEKAT</span><small>Posljednje uređivan</small></div>
            <div class="ops-resume-project">
                <div class="ops-resume-mark">{{ strtoupper(substr($current['model']->name, 0, 2)) }}</div>
                <div><h3>{{ $current['model']->name }}</h3><p>{{ $current['model']->code }} · {{ $current['model']->location }}</p></div>
            </div>
            <div class="ops-resume-meta">
                <span><b>{{ $current['model']->odfs_count }}</b> ODF</span>
                <span><b>{{ $current['model']->cabinets_count }}</b> ODO</span>
                <span><b>{{ $current['model']->houses_count }}</b> kuća</span>
                @if($current['issues'] > 0)<span class="attention"><b>{{ $current['issues'] }}</b> otvoreno</span>@endif
            </div>
            <div class="ops-resume-actions">
                <a class="primary" href="{{ route('map.dashboard', ['project' => $current['model']->id]) }}">Otvori na mapi <b>→</b></a>
                <a href="{{ route('projects.show', $current['model']) }}">Pregled projekta</a>
            </div>
        @else
            <div class="ops-resume-empty"><span>PRVI KORAK</span><h3>Kreiraj prvi FTTH projekat</h3><a href="{{ route('projects.index') }}">Novi projekat →</a></div>
        @endif
    </article>

    <article class="ops-stat-card">
        <span class="ops-stat-icon blue">ODF</span><div><small>Centralni čvorovi</small><strong>{{ $stats['odfs'] }}</strong><span>aktivnih lokacija</span></div>
    </article>
    <article class="ops-stat-card">
        <span class="ops-stat-icon green">ODO</span><div><small>Distribucijski ormarići</small><strong>{{ $stats['cabinets'] }}</strong><span>u mreži</span></div>
    </article>
    <article class="ops-stat-card">
        <span class="ops-stat-icon violet">HP</span><div><small>Kućni priključci</small><strong>{{ number_format($stats['houses']) }}</strong><span>{{ $stats['connected_percent'] }}% dodijeljeno</span></div>
    </article>
    <article class="ops-stat-card">
        <span class="ops-stat-icon amber">KM</span><div><small>Projektovane trase</small><strong>{{ number_format($stats['route_km'], 2) }}</strong><span>kilometara mreže</span></div>
    </article>
</section>

<section class="ops-workspace">
    <article class="ops-table-card">
        <header class="ops-section-head">
            <div><span>PROJEKTNI PORTFELJ</span><h3>Operativno stanje projekata</h3><p>Kapacitet, infrastruktura i tehničke nepravilnosti.</p></div>
            <a href="{{ route('projects.index') }}">Prikaži sve projekte</a>
        </header>
        <div class="ops-table-wrap">
            <table class="ops-project-table">
                <thead><tr><th>Projekat</th><th>Infrastruktura</th><th>Kapacitet</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @forelse($projectCards as $item)
                    @php($project = $item['model'])
                    <tr>
                        <td data-label="Projekat"><div class="ops-project-name"><i class="{{ $project->status }}"></i><div><a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a><span>{{ $project->code }} · {{ $project->location }}</span></div></div></td>
                        <td data-label="Infrastruktura"><div class="ops-infra"><span><b>{{ $project->odfs_count }}</b> ODF</span><span><b>{{ $project->cabinets_count }}</b> ODO</span><span><b>{{ $project->houses_count }}</b> kuća</span></div></td>
                        <td data-label="Kapacitet"><div class="ops-capacity"><div><span>{{ $item['used'] }} / {{ $item['capacity'] }} portova</span><b>{{ $item['utilization'] }}%</b></div><div><i style="width:{{ $item['utilization'] }}%"></i></div></div></td>
                        <td data-label="Status">@if($item['issues'] > 0)<span class="ops-state warning">{{ $item['issues'] }} {{ $item['issues'] === 1 ? 'problem' : 'problema' }}</span>@else<span class="ops-state ok">Uredno</span>@endif</td>
                        <td data-label="Mapa"><a class="ops-open" href="{{ route('map.dashboard', ['project' => $project->id]) }}" aria-label="Otvori projekat na mapi">→</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5">@include('ftth.partials.empty-state', ['title' => 'Nema projekata', 'message' => 'Kreiraj prvi FTTH projekat i započni planiranje mreže.'])</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </article>

    <aside class="ops-rail">
        <article class="ops-alert-card">
            <header><div><span>ZAHTIJEVA PAŽNJU</span><h3>Otvorene stavke</h3></div><b>{{ $attentionProjects->sum('issues') }}</b></header>
            <div class="ops-alert-list">
                @forelse($attentionProjects as $item)
                    @php($project = $item['model'])
                    <a href="{{ route('project-check.index') }}"><i></i><div><strong>{{ $project->name }}</strong><span>{{ $item['issues'] }} otvorenih tehničkih stavki</span></div><b>→</b></a>
                @empty
                    <div class="ops-all-clear"><b>✓</b><span>Nema otvorenih nepravilnosti</span></div>
                @endforelse
            </div>
            <a class="ops-rail-link" href="{{ route('project-check.index') }}">Otvori kontrolu kvaliteta <span>→</span></a>
        </article>

        <article class="ops-modules-card">
            <header><span>BRZI PRISTUP</span><h3>Mrežni moduli</h3></header>
            <nav>
                <a href="{{ route('odfs.index') }}"><span>01</span><div><b>ODF čvorovi</b><small>Centralna infrastruktura</small></div><i>→</i></a>
                <a href="{{ route('cabinets.index') }}"><span>02</span><div><b>ODO ormarići</b><small>Kapacitet i distribucija</small></div><i>→</i></a>
                <a href="{{ route('fiber-schema.index') }}"><span>03</span><div><b>Fiber šema</b><small>Topologija mreže</small></div><i>→</i></a>
                <a href="{{ route('reports.index') }}"><span>04</span><div><b>Izvještaji</b><small>Analitika i materijali</small></div><i>→</i></a>
            </nav>
        </article>
    </aside>
</section>
@endsection
