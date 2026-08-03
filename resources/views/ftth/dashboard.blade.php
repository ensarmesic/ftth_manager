@extends('ftth.layout')
@section('title', 'Kontrolni centar')
@section('subtitle', 'Operativni pregled FTTH infrastrukture i projektnih rizika.')
@section('content')

<section class="ops-toolbar">
    <div class="ops-toolbar-copy"><span class="ops-live"><i></i> Sistem aktivan</span><p>Posljednje stanje · {{ now()->format('d.m.Y. H:i') }}</p></div>
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
