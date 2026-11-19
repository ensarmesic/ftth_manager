@extends('ftth.layout')
@section('title', 'Izvještaji')
@section('subtitle', $selectedProject ? 'Izvještaj projekta '.$selectedProject->name : 'Pregled svih projekata: obim mreže, kapacitet i stanje provjere')
@section('content')
@php
    $n = fn ($value, int $decimals = 0) => number_format((float) $value, $decimals, ',', '.');
    $m = fn ($value) => number_format((float) $value, 0, ',', '.').' m';
    $km = fn ($value) => number_format((float) $value / 1000, 2, ',', '.').' km';
    $tone = fn (int $percent) => $percent >= 100 ? 'is-full' : ($percent >= 85 ? 'is-high' : ($percent >= 60 ? 'is-mid' : 'is-low'));
    $statusLabels = ['planning' => 'Planiranje', 'active' => 'Aktivan', 'paused' => 'Pauziran', 'completed' => 'Završen'];
@endphp
<div class="reports-page">

    {{-- Izbor projekta --}}
    <section class="rp-toolbar">
        <form method="GET" class="rp-project-picker">
            <label for="rp-project">Izvještaj za</label>
            <select id="rp-project" name="project" data-auto-submit>
                <option value="">Svi projekti (pregled)</option>
                @foreach($projects as $project)
                    <option value="{{ $project->id }}" @selected($selectedProject?->id === $project->id)>{{ $project->name }} · {{ $project->code }}</option>
                @endforeach
            </select>
            <noscript><button type="submit" class="rp-btn">Prikaži</button></noscript>
        </form>
        <div class="rp-toolbar-actions">
            @if($selectedProject)
                <a href="{{ route('reports.index') }}" class="rp-btn">← Svi projekti</a>
                <a href="{{ route('map.dashboard', ['project' => $selectedProject->id]) }}" class="rp-btn">Otvori mapu</a>
            @endif
            <button type="button" class="rp-btn rp-btn-dark" data-rp-print>Štampaj</button>
        </div>
    </section>

@if(! $selectedProject)
    {{-- ═════════════ PREGLED SVIH PROJEKATA ═════════════ --}}
    @php $t = $overview['totals']; @endphp
    <section class="rp-kpis">
        <div class="rp-kpi"><small>Projekti</small><b>{{ $n($t['projects']) }}</b><span>u evidenciji</span></div>
        <div class="rp-kpi"><small>Kuće</small><b>{{ $n($t['houses']) }}</b><span>{{ $n($t['connected']) }} vezano na ODO</span></div>
        <div class="rp-kpi"><small>ODO ormarići</small><b>{{ $n($t['cabinets']) }}</b><span>{{ $n($t['capacity']) }} portova ukupno</span></div>
        <div class="rp-kpi"><small>Iskorištenost portova</small><b>{{ $t['capacity'] > 0 ? min(100, (int) round($t['connected'] / $t['capacity'] * 100)) : 0 }}%</b><span>{{ $n(max($t['capacity'] - $t['connected'], 0)) }} slobodno</span></div>
        <div class="rp-kpi"><small>Kablovske trase</small><b>{{ $km($t['cable_m']) }}</b><span>rov {{ $km($t['trench_m']) }}</span></div>
        <div class="rp-kpi {{ $t['errors'] ? 'is-bad' : ($t['warnings'] ? 'is-warn' : 'is-good') }}"><small>Provjera</small><b>{{ $t['errors'] ? $n($t['errors']).' grešaka' : ($t['warnings'] ? $n($t['warnings']).' upoz.' : 'Uredno') }}</b><span>{{ $t['errors'] && $t['warnings'] ? $n($t['warnings']).' upozorenja' : 'svi projekti' }}</span></div>
    </section>

    <section class="rp-card">
        <div class="rp-card-hd">
            <div><h2>Projekti</h2><p>Klikni projekat za puni izvještaj.</p></div>
        </div>
        @if(count($overview['rows']))
        <div class="rp-table-wrap">
            <table class="rp-table">
                <thead>
                    <tr>
                        <th>Projekat</th>
                        <th>Status</th>
                        <th class="num">ODF</th>
                        <th class="num">ODO</th>
                        <th class="num">Kuće</th>
                        <th>Vezano na ODO</th>
                        <th>Portovi</th>
                        <th class="num">Trase</th>
                        <th class="num">Rov</th>
                        <th>Provjera</th>
                        <th class="rp-no-print"></th>
                    </tr>
                </thead>
                <tbody>
                @foreach($overview['rows'] as $row)
                    @php $project = $row['project']; @endphp
                    <tr class="rp-row-link" data-href="{{ route('reports.index', ['project' => $project->id]) }}">
                        <td><a href="{{ route('reports.index', ['project' => $project->id]) }}" class="rp-project-name">{{ $project->name }}</a><small>{{ $project->code }}{{ $project->location ? ' · '.$project->location : '' }}</small></td>
                        <td>@include('ftth.partials.badge', ['value' => $project->status])</td>
                        <td class="num">{{ $n($row['odfs']) }}</td>
                        <td class="num">{{ $n($row['cabinets']) }}</td>
                        <td class="num">{{ $n($row['houses']) }}</td>
                        <td><div class="rp-meter {{ $tone($row['connected_percent']) }} rp-meter-plain"><i style="width:{{ $row['connected_percent'] }}%"></i></div><small>{{ $n($row['connected']) }} / {{ $n($row['houses']) }} · {{ $row['connected_percent'] }}%</small></td>
                        <td><div class="rp-meter {{ $tone($row['utilization']) }}"><i style="width:{{ $row['utilization'] }}%"></i></div><small>{{ $n($row['connected']) }} / {{ $n($row['capacity']) }} · {{ $row['utilization'] }}%</small></td>
                        <td class="num">{{ $km($row['cable_m']) }}</td>
                        <td class="num">{{ $km($row['trench_m']) }}</td>
                        <td>
                            @if($row['errors'])<span class="rp-pill is-bad">{{ $row['errors'] }} grešaka</span>@endif
                            @if($row['warnings'])<span class="rp-pill is-warn">{{ $row['warnings'] }} upoz.</span>@endif
                            @if(! $row['errors'] && ! $row['warnings'])<span class="rp-pill is-good">Uredno</span>@endif
                        </td>
                        <td class="rp-no-print"><a href="{{ route('reports.index', ['project' => $project->id]) }}" class="rp-link">Izvještaj →</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @else
            @include('ftth.partials.empty-state', ['title' => 'Nema projekata', 'message' => 'Izvještaji će se prikazati kada dodate prvi projekat.'])
        @endif
    </section>

@else
    {{-- ═════════════ IZVJEŠTAJ JEDNOG PROJEKTA ═════════════ --}}
    @php
        $s = $report['summary'];
        $health = $report['health'];
        $mat = $report['materials'];
        $aerial = $selectedProject->isAerial();
    @endphp

    <section class="rp-hero">
        <div class="rp-hero-main">
            <small>Izvještaj projekta</small>
            <h2>{{ $selectedProject->name }}</h2>
            <p>{{ $selectedProject->code }}{{ $selectedProject->location ? ' · '.$selectedProject->location : '' }}{{ $selectedProject->investor ? ' · Investitor: '.$selectedProject->investor : '' }}</p>
            <div class="rp-hero-tags">
                @include('ftth.partials.badge', ['value' => $selectedProject->status])
                <span class="rp-pill">{{ $aerial ? 'Nadzemna mreža' : 'Podzemna mreža' }}</span>
                @if($health['errors'])<span class="rp-pill is-bad">{{ $health['errors'] }} grešaka</span>@endif
                @if($health['warnings'])<span class="rp-pill is-warn">{{ $health['warnings'] }} upozorenja</span>@endif
                @if(! $health['issues'])<span class="rp-pill is-good">Provjera uredna</span>@endif
            </div>
        </div>
        <dl class="rp-hero-meta">
            <div><dt>Zadnja izmjena</dt><dd>{{ $report['activity']['last_change']?->format('d.m.Y. H:i') ?? '—' }}</dd></div>
            <div><dt>Izvještaj napravljen</dt><dd>{{ $report['activity']['generated_at']->format('d.m.Y. H:i') }}</dd></div>
            @if($selectedProject->deadline)<div><dt>Rok</dt><dd>{{ \Illuminate\Support\Carbon::parse($selectedProject->deadline)->format('d.m.Y.') }}</dd></div>@endif
        </dl>
    </section>

    <nav class="rp-sections rp-no-print" aria-label="Dijelovi izvještaja">
        <a href="#rp-sazetak">Sažetak</a>
        <a href="#rp-kapacitet">Kapacitet</a>
        <a href="#rp-trase">Trase</a>
        <a href="#rp-materijal">Materijal</a>
        <a href="#rp-provjera">Provjera @if($health['issues'])<span>{{ $health['issues'] }}</span>@endif</a>
        <a href="#rp-dokumenti">Dokumenti</a>
    </nav>

    {{-- Sažetak --}}
    <section id="rp-sazetak" class="rp-kpis">
        <div class="rp-kpi"><small>ODF</small><b>{{ $n($s['odfs']) }}</b><span>{{ $n($s['cabinets']) }} ODO ormarića</span></div>
        <div class="rp-kpi"><small>Kuće</small><b>{{ $n($s['houses']) }}</b><span>{{ $s['unassigned'] ? $n($s['unassigned']).' bez ODO-a' : 'sve vezane na ODO' }}</span></div>
        <div class="rp-kpi"><small>Iskorištenost portova</small><b>{{ $s['utilization'] }}%</b><span>{{ $n($s['used_ports']) }} / {{ $n($s['capacity']) }} · {{ $n($s['free_ports']) }} slobodno</span><div class="rp-meter {{ $tone($s['utilization']) }}"><i style="width:{{ $s['utilization'] }}%"></i></div></div>
        <div class="rp-kpi"><small>Splitteri</small><b>{{ $n($s['splitters']) }}</b><span>{{ $s['overfull_cabinets'] ? $s['overfull_cabinets'].' prepunih ormarića' : 'nema prepunih ormarića' }}</span></div>
        @unless($aerial)<div class="rp-kpi"><small>Glavni rov</small><b>{{ $m($s['trench_m']) }}</b><span>fizička dužina rova</span></div>@endunless
        <div class="rp-kpi"><small>Kablovske trase</small><b>{{ $m($s['cable_route_m']) }}</b><span>{{ $n($s['route_count']) }} trasa</span></div>
        <div class="rp-kpi"><small>Optički kabl</small><b>{{ $m($s['fiber_m']) }}</b><span>sa rezervom 10%: {{ $m($s['fiber_reserve_m']) }}</span></div>
        @if($report['survey_points'])<div class="rp-kpi"><small>Geodetske tačke</small><b>{{ $n($report['survey_points']) }}</b><span>iz terenskog snimka</span></div>@endif
    </section>

    {{-- Kapacitet --}}
    <section id="rp-kapacitet" class="rp-grid-2">
        <article class="rp-card">
            <div class="rp-card-hd"><div><h2>ODO ormarići</h2><p>Popunjenost portova, najpunije prvo.</p></div></div>
            @if(count($report['cabinets']))
            <div class="rp-table-wrap rp-scroll">
                <table class="rp-table">
                    <thead><tr><th>Ormarić</th><th>ODF</th><th class="num">Spl.</th><th class="num">Kuće</th><th class="num">Slob.</th><th>Popunjenost</th></tr></thead>
                    <tbody>
                    @foreach($report['cabinets'] as $cabinet)
                        <tr class="{{ $cabinet['overfull'] ? 'is-bad-row' : '' }}">
                            <td class="strong">{{ $cabinet['name'] }}</td>
                            <td>{{ $cabinet['odf'] ?: '—' }}</td>
                            <td class="num">{{ $cabinet['splitters'] }}</td>
                            <td class="num">{{ $cabinet['used'] }} / {{ $cabinet['capacity'] }}</td>
                            <td class="num">{{ $cabinet['free'] }}</td>
                            <td><div class="rp-meter {{ $tone($cabinet['utilization']) }}"><i style="width:{{ min(100, $cabinet['utilization']) }}%"></i></div><small>{{ $cabinet['utilization'] }}%{{ $cabinet['overfull'] ? ' · prepun' : '' }}</small></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @else
                <p class="rp-empty">Projekat još nema ODO ormarića.</p>
            @endif
        </article>

        <article class="rp-card">
            <div class="rp-card-hd"><div><h2>ODF centri</h2><p>Šta se napaja iz kojeg ODF-a.</p></div></div>
            @if(count($report['odfs']))
            <div class="rp-table-wrap">
                <table class="rp-table">
                    <thead><tr><th>ODF</th><th class="num">Portovi</th><th class="num">Vlakna</th><th class="num">ODO</th><th class="num">Kuće</th></tr></thead>
                    <tbody>
                    @foreach($report['odfs'] as $odf)
                        <tr>
                            <td class="strong">{{ $odf['name'] }}@if($odf['address'])<small>{{ $odf['address'] }}</small>@endif</td>
                            <td class="num">{{ $n($odf['ports']) }}</td>
                            <td class="num">{{ $n($odf['fibers']) }}</td>
                            <td class="num">{{ $n($odf['cabinets']) }}</td>
                            <td class="num">{{ $n($odf['houses']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @else
                <p class="rp-empty">Projekat još nema ODF.</p>
            @endif
            @if($s['unassigned'])
                <div class="rp-note is-warn"><b>{{ $n($s['unassigned']) }} kuća</b> nije vezano ni na jedan ODO ormarić. <a href="{{ route('houses.index', ['project' => $selectedProject->id]) }}">Otvori kuće</a></div>
            @endif
        </article>
    </section>

    {{-- Trase --}}
    <section id="rp-trase" class="rp-card">
        <div class="rp-card-hd"><div><h2>Trase po vrsti</h2><p>Dužine po vrsti trase i načinu polaganja.</p></div></div>
        @if(count($report['routes_by_type']))
        <div class="rp-table-wrap">
            <table class="rp-table">
                <thead><tr><th>Vrsta</th><th class="num">Broj</th><th class="num">Dužina</th><th class="num">Podzemno</th><th class="num">Nadzemno</th><th class="num">Optički kabl</th></tr></thead>
                <tbody>
                @foreach($report['routes_by_type'] as $type)
                    <tr>
                        <td class="strong">{{ $type['label'] }}</td>
                        <td class="num">{{ $n($type['count']) }}</td>
                        <td class="num strong">{{ $m($type['length_m']) }}</td>
                        <td class="num">{{ $type['underground_m'] ? $m($type['underground_m']) : '—' }}</td>
                        <td class="num">{{ $type['aerial_m'] ? $m($type['aerial_m']) : '—' }}</td>
                        <td class="num">{{ $type['type'] === 'trench' ? '—' : $m($type['fiber_m']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if(count($report['routes']))
        <details class="rp-details">
            <summary>Sve kablovske trase ({{ count($report['routes']) }})</summary>
            <div class="rp-table-wrap rp-scroll">
                <table class="rp-table">
                    <thead><tr><th>Trasa</th><th>Vrsta</th><th>Polaganje</th><th>Mikrocijev</th><th class="num">Dužina</th><th class="num">Niti</th><th class="num">Kabl</th></tr></thead>
                    <tbody>
                    @foreach($report['routes'] as $route)
                        <tr>
                            <td class="strong">{{ $route->name ?: 'Trasa #'.$route->id }}</td>
                            <td>{{ \App\Services\ProjectReportService::ROUTE_TYPES[$route->route_type] ?? $route->route_type }}</td>
                            <td>{{ ['aerial' => 'Nadzemno', 'underground' => 'Podzemno'][$route->installation_type] ?? ($route->installation_type ?: '—') }}</td>
                            <td>{{ $route->microduct_type ? $route->microduct_type.($route->microduct_count > 1 ? ' ×'.$route->microduct_count : '') : '—' }}</td>
                            <td class="num">{{ $m($route->duct_length_m) }}</td>
                            <td class="num">{{ $route->fiber_count ?: '—' }}</td>
                            <td class="num">{{ $route->fiber_length_m ? $m($route->fiber_length_m) : '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </details>
        @endif
        @else
            <p class="rp-empty">Projekat još nema trasa.</p>
        @endif
    </section>

    {{-- Materijal --}}
    <section id="rp-materijal" class="rp-grid-2">
        <article class="rp-card">
            <div class="rp-card-hd"><div><h2>Obračun iz mreže</h2><p>Izračunato iz nacrtanih trasa, uz rezervu {{ $mat['reserve_percent'] }}%.</p></div></div>
            @php
                $lengthRows = array_filter([
                    $aerial ? null : ['Mikrocijev 14/10', 'microduct_14_10_m'],
                    $aerial ? null : ['Mikrocijev 10/8', 'microduct_10_8_m'],
                    ['ADSS kabl (nadzemno)', 'adss_cable_m'],
                    ['Optički kabl 4 niti', 'fiber_4_m'],
                    ['Optički kabl 12 niti', 'fiber_12_m'],
                    ['Optički kabl 24 niti', 'fiber_24_m'],
                    ['Optički kabl 48 niti', 'fiber_48_m'],
                ], fn ($row) => $row && ($mat['lengths_with_reserve'][$row[1]]['base'] ?? 0) > 0);
            @endphp
            <div class="rp-table-wrap">
                <table class="rp-table">
                    <thead><tr><th>Stavka</th><th class="num">Iz mreže</th><th class="num">Rezerva</th><th class="num">Za nabavku</th></tr></thead>
                    <tbody>
                    @forelse($lengthRows as [$label, $key])
                        @php $row = $mat['lengths_with_reserve'][$key]; @endphp
                        <tr><td class="strong">{{ $label }}</td><td class="num">{{ $m($row['base']) }}</td><td class="num">+{{ $m($row['reserve']) }}</td><td class="num strong">{{ $m($row['total']) }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="rp-empty">Nema trasa s upisanim kablom ili mikrocijevima.</td></tr>
                    @endforelse
                    <tr><td class="strong">Splitteri</td><td class="num">{{ $n($mat['splitter_count']) }} kom</td><td class="num">—</td><td class="num strong">{{ $n($mat['splitter_count']) }} kom</td></tr>
                    </tbody>
                </table>
            </div>
            @if($mat['unclassified_routes'])
                <div class="rp-note is-warn"><b>{{ $mat['unclassified_routes'] }} trasa</b> nema potpune podatke o kablu ili mikrocijevima, pa obračun nije potpun. <a href="{{ route('routes.index', ['project' => $selectedProject->id]) }}">Otvori trase</a></div>
            @endif
        </article>

        <article class="rp-card">
            <div class="rp-card-hd"><div><h2>Planirani materijal i cijena</h2><p>Stavke upisane u materijal projekta.</p></div></div>
            @if(count($report['material_rows']))
            <div class="rp-table-wrap rp-scroll">
                <table class="rp-table">
                    <thead><tr><th>Materijal</th><th class="num">Plan</th><th class="num">Utrošeno</th><th class="num">Cijena</th><th class="num">Ukupno</th></tr></thead>
                    <tbody>
                    @foreach($report['material_rows'] as $row)
                        <tr>
                            <td class="strong">{{ $row['name'] }}</td>
                            <td class="num">{{ $n($row['planned'], 2) }} {{ $row['unit'] }}</td>
                            <td class="num">{{ $n($row['used'], 2) }}</td>
                            <td class="num">{{ $n($row['unit_price'], 2) }}</td>
                            <td class="num strong">{{ $n($row['total'], 2) }} KM</td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot><tr><td colspan="4">Ukupna procijenjena vrijednost</td><td class="num">{{ $n($report['material_total'], 2) }} KM</td></tr></tfoot>
                </table>
            </div>
            @else
                <p class="rp-empty">Materijal projekta još nije upisan. Količine iz mreže su u tabeli lijevo.</p>
            @endif
        </article>
    </section>

    {{-- Provjera --}}
    <section id="rp-provjera" class="rp-card">
        <div class="rp-card-hd">
            <div><h2>Provjera projekta</h2><p>{{ $health['errors'] }} grešaka · {{ $health['warnings'] }} upozorenja · {{ $health['infos'] }} napomena</p></div>
            <a href="{{ route('project-check.index', ['project' => $selectedProject->id]) }}" class="rp-btn rp-no-print">Otvori provjeru</a>
        </div>
        @if(count($health['items']))
            <ul class="rp-checks">
                @foreach(collect($health['items'])->take(25) as $item)
                    <li class="is-{{ $item['level'] }}">
                        <i></i>
                        <div><b>{{ $item['message'] }}</b>@if(! empty($item['recommendation']))<span>{{ $item['recommendation'] }}</span>@endif</div>
                    </li>
                @endforeach
            </ul>
            @if(count($health['items']) > 25)
                <p class="rp-empty">Prikazano prvih 25 od {{ count($health['items']) }} stavki. Sve su na stranici Provjera projekta.</p>
            @endif
        @else
            <div class="rp-note is-good">Projekat je prošao sve provjere.</div>
        @endif
    </section>

    {{-- Dokumenti --}}
    <section id="rp-dokumenti" class="rp-card rp-no-print">
        <div class="rp-card-hd"><div><h2>Dokumenti i izvozi</h2><p>Gotovi dokumenti za ovaj projekat.</p></div></div>
        <div class="rp-docs">
            <a href="{{ route('reports.project-appendix', $selectedProject) }}"><b>Prilog 3</b><span>Specifikacija elemenata i materijala</span></a>
            @can('project.export')
                <a href="{{ route('projects.print', $selectedProject) }}" target="_blank" rel="noopener"><b>Projekat za štampu</b><span>Pregled projekta spreman za PDF</span></a>
                <a href="{{ route('projects.fiber-schema-pdf', $selectedProject) }}"><b>Fiber šema (PDF)</b><span>Raspored vlakana po ormarićima</span></a>
                <a href="{{ route('projects.fiber-schema-dxf', $selectedProject) }}"><b>Fiber šema (DXF)</b><span>Za AutoCAD</span></a>
                <a href="{{ route('projects.fiber.csv', $selectedProject) }}"><b>Plan vlakana (CSV)</b><span>Tabela za Excel</span></a>
                <a href="{{ route('projects.geojson', $selectedProject) }}"><b>GeoJSON</b><span>Mreža za GIS alate</span></a>
                <a href="{{ route('projects.show', $selectedProject) }}"><b>DXF mreže</b><span>Izvoz na stranici projekta</span></a>
            @endcan
        </div>
    </section>
@endif
</div>

<script nonce="{{ Vite::cspNonce() }}">
    document.querySelector('[data-rp-print]')?.addEventListener('click', () => window.print());
    document.querySelectorAll('.rp-row-link').forEach((row) => {
        row.addEventListener('click', (event) => {
            if (event.target.closest('a, button')) return;
            window.location.href = row.dataset.href;
        });
    });
</script>
@endsection
