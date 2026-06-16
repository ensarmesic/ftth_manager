<!doctype html>
<html lang="bs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $project->name }} — Tehnički izvještaj</title>
    <style>
        @page { margin: 2cm 1.8cm; }
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; color: #0f172a; font: 11px/1.5 Arial, Helvetica, sans-serif; background: #fff; }
        main { padding: 28px 32px; max-width: 1000px; margin: 0 auto; }

        /* Toolbar */
        .toolbar { margin-bottom: 24px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .btn { border: 1.5px solid #0f172a; background: #0f172a; color: #fff; padding: 7px 14px; text-decoration: none; font: 800 11px/1 system-ui; cursor: pointer; }
        .btn.secondary { background: #fff; color: #0f172a; }
        .toolbar-date { margin-left: auto; font-size: 10px; color: #64748b; }

        /* Document header */
        .doc-header { display: grid; grid-template-columns: 1fr auto; gap: 20px; align-items: end; border-bottom: 2.5px solid #0f172a; padding-bottom: 14px; margin-bottom: 20px; }
        .doc-title { margin: 0 0 4px; font-size: 22px; font-weight: 900; line-height: 1.1; }
        .doc-meta { margin: 0; font-size: 11px; color: #475569; }
        .doc-code { font: 900 13px/1 ui-monospace, monospace; border: 2px solid #0f172a; padding: 5px 10px; white-space: nowrap; }
        .doc-status { font: 700 10px/1.4 system-ui; padding: 2px 6px; border: 1px solid currentColor; margin-top: 6px; display: inline-block; }
        .status-planning { color: #1d4ed8; }
        .status-active { color: #15803d; }
        .status-paused { color: #92400e; }
        .status-completed { color: #166534; }

        /* Stats grid */
        .stats-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 8px; margin-bottom: 22px; }
        .stat-box { border: 1px solid #e2e8f0; padding: 10px 12px; }
        .stat-box b { display: block; font-size: 9px; font-weight: 900; text-transform: uppercase; letter-spacing: .06em; color: #64748b; margin-bottom: 4px; }
        .stat-box span { display: block; font-size: 18px; font-weight: 900; line-height: 1; }
        .stat-box small { display: block; font-size: 9px; color: #64748b; margin-top: 3px; }

        /* Section headings */
        h2 { margin: 22px 0 10px; font-size: 11px; font-weight: 900; text-transform: uppercase; letter-spacing: .08em; border-bottom: 2px solid #0f172a; padding-bottom: 5px; display: flex; align-items: center; gap: 8px; }
        h2 .badge { font-size: 9px; background: #f1f5f9; border: 1px solid #cbd5e1; padding: 1px 5px; font-weight: 700; letter-spacing: 0; }

        /* Tables */
        table { width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 4px; }
        th { background: #f1f5f9; font: 900 9px/1.4 system-ui; text-transform: uppercase; letter-spacing: .04em; color: #475569; border: 1px solid #e2e8f0; padding: 5px 7px; text-align: left; white-space: nowrap; }
        td { border: 1px solid #e2e8f0; padding: 5px 7px; vertical-align: top; }
        tr:nth-child(even) td { background: #f8fafc; }

        /* Route type colors */
        .rt { font: 800 9px/1.3 ui-monospace, monospace; padding: 1px 4px; }
        .rt-trench { background: #f1f5f9; color: #334155; }
        .rt-backbone, .rt-feeder { background: #eff6ff; color: #1e40af; }
        .rt-distribution { background: #f0fdf4; color: #166534; }
        .rt-drop { background: #fff7ed; color: #c2410c; }

        /* Materials 2-col */
        .mat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .mat-section { border: 1px solid #e2e8f0; }
        .mat-section h3 { margin: 0; padding: 6px 9px; font-size: 9px; font-weight: 900; text-transform: uppercase; letter-spacing: .06em; background: #f1f5f9; color: #475569; border-bottom: 1px solid #e2e8f0; }
        .mat-row { display: grid; grid-template-columns: 1fr auto; gap: 6px; padding: 4px 9px; font-size: 10px; border-bottom: 1px solid #f1f5f9; }
        .mat-row:last-child { border-bottom: 0; }
        .mat-row .val { font-weight: 900; font-variant-numeric: tabular-nums; }

        /* Validation */
        .v-error { color: #991b1b; font-weight: 900; }
        .v-warning { color: #92400e; font-weight: 700; }
        .v-info { color: #1e40af; }
        .v-ok { color: #166534; font-weight: 700; }
        .validation-summary { display: flex; gap: 16px; font-size: 10px; margin-bottom: 8px; }
        .validation-summary span { font-weight: 700; }

        /* Print */
        @media print {
            .toolbar { display: none !important; }
            main { padding: 0; }
            h2 { break-after: avoid; margin-top: 16px; }
            tr { break-inside: avoid; }
            .stats-grid, .mat-grid { break-inside: avoid; }
            .doc-header { break-inside: avoid; }
        }
    </style>
</head>
<body>
<main>

    <div class="toolbar">
        <button class="btn" onclick="window.print()">Štampaj / Spremi PDF</button>
        <a class="btn secondary" href="{{ route('map.dashboard', ['project' => $project->id]) }}">Mapa projekta</a>
        <a class="btn secondary" href="{{ route('projects.geojson', $project) }}">GeoJSON</a>
        <a class="btn secondary" href="{{ route('projects.dxf', $project) }}">DXF</a>
        <span class="toolbar-date">Generisano: {{ now()->format('d.m.Y \u\ H:i') }}</span>
    </div>

    {{-- Header --}}
    <div class="doc-header">
        <div>
            <h1 class="doc-title">{{ $project->name }}</h1>
            <p class="doc-meta">
                {{ $project->location }}
                @if($project->investor) · Investitor: {{ $project->investor }}@endif
                @if($project->start_date) · Početak: {{ \Carbon\Carbon::parse($project->start_date)->format('d.m.Y') }}@endif
                @if($project->deadline) · Rok: {{ \Carbon\Carbon::parse($project->deadline)->format('d.m.Y') }}@endif
            </p>
            <span class="doc-status status-{{ $project->status }}">{{ strtoupper($project->status) }}</span>
        </div>
        <div class="doc-code">{{ $project->code }}</div>
    </div>

    {{-- Stats --}}
    <div class="stats-grid">
        <div class="stat-box">
            <b>ODF</b>
            <span>{{ $project->odfs->count() }}</span>
            <small>optičkih razdjela</small>
        </div>
        <div class="stat-box">
            <b>ODO ormarića</b>
            <span>{{ $project->cabinets->count() }}</span>
            <small>splittera: {{ $project->cabinets->sum('splitter_count') }}</small>
        </div>
        <div class="stat-box">
            <b>Kuca / priključaka</b>
            <span>{{ $project->houses->count() }}</span>
            <small>bez ODO: {{ $project->houses->whereNull('cabinet_id')->count() }}</small>
        </div>
        <div class="stat-box">
            <b>Trase</b>
            <span>{{ $project->routes->count() }}</span>
            <small>drop: {{ $project->routes->where('route_type', 'drop')->count() }}</small>
        </div>
        <div class="stat-box">
            <b>Dužina trasa</b>
            <span>{{ number_format($project->routes->sum('duct_length_m')) }}<small style="font-size:13px;font-weight:700"> m</small></span>
            <small>mikrocijev ukupno</small>
        </div>
        <div class="stat-box">
            <b>Optički kabel</b>
            <span>{{ number_format($project->routes->where('route_type','!=','trench')->sum('fiber_length_m')) }}<small style="font-size:13px;font-weight:700"> m</small></span>
            <small>bez rovova</small>
        </div>
    </div>

    {{-- Materials --}}
    <h2>Materijalni obračun <span class="badge">+10% rezerva</span></h2>
    <div class="mat-grid">
        <div class="mat-section">
            <h3>Mikrocijev</h3>
            @php
                $mic1410 = $project->routes->where('microduct_type','14/10')->sum(fn($r) => $r->duct_length_m * $r->microduct_count);
                $mic1008 = $project->routes->where('microduct_type','10/8')->sum(fn($r) => $r->duct_length_m * $r->microduct_count);
                $res = fn($v) => number_format((int) ceil($v * 1.1));
            @endphp
            <div class="mat-row"><span>14/10 mm</span><span class="val">{{ $res($mic1410) }} m</span></div>
            <div class="mat-row"><span>10/8 mm</span><span class="val">{{ $res($mic1008) }} m</span></div>
            @foreach($project->routes->whereNotNull('microduct_type')->groupBy('microduct_type') as $type => $group)
                @if(!in_array($type, ['14/10','10/8']))
                    <div class="mat-row"><span>{{ $type }} mm</span><span class="val">{{ $res($group->sum(fn($r) => $r->duct_length_m * $r->microduct_count)) }} m</span></div>
                @endif
            @endforeach
        </div>
        <div class="mat-section">
            <h3>Optički kabel</h3>
            @foreach($project->routes->where('route_type','!=','trench')->whereNotNull('fiber_count')->groupBy('fiber_count') as $count => $group)
                <div class="mat-row"><span>{{ $count }}F kabel</span><span class="val">{{ $res($group->sum('fiber_length_m')) }} m</span></div>
            @endforeach
        </div>
        <div class="mat-section">
            <h3>Rovovi i zaštitne cijevi</h3>
            @php
                $trenchLen = $project->routes->where('route_type','trench')->sum('duct_length_m');
                $trenchDucts = $project->routes->where('route_type','trench')->groupBy('microduct_type');
            @endphp
            <div class="mat-row"><span>Rov dužina</span><span class="val">{{ number_format($trenchLen) }} m</span></div>
            @foreach($trenchDucts->filter(fn($g,$t) => filled($t)) as $type => $group)
                <div class="mat-row"><span>Zaštitna cijev {{ $type }}</span><span class="val">{{ $res($group->sum(fn($r) => $r->duct_length_m * ($r->microduct_count ?: 1))) }} m</span></div>
            @endforeach
        </div>
        <div class="mat-section">
            <h3>Oprema</h3>
            <div class="mat-row"><span>ODF optički razdjel</span><span class="val">{{ $project->odfs->count() }} kom</span></div>
            <div class="mat-row"><span>ODO ormarić</span><span class="val">{{ $project->cabinets->count() }} kom</span></div>
            <div class="mat-row"><span>Splitter 1×4</span><span class="val">{{ $project->cabinets->sum('splitter_count') }} kom</span></div>
            @if(isset($materials['estimated_value']) && $materials['estimated_value'] > 0)
                <div class="mat-row"><span>Procjenjena vrijednost</span><span class="val">{{ number_format((float)$materials['estimated_value'], 2) }} KM</span></div>
            @endif
        </div>
    </div>

    {{-- Routes --}}
    <h2>Trase <span class="badge">{{ $project->routes->count() }}</span></h2>
    <table>
        <thead>
            <tr>
                <th>Naziv</th>
                <th>Tip</th>
                <th>Dužina</th>
                <th>Kabel</th>
                <th>Mikrocijev</th>
                <th>Polaganje</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach($project->routes->sortBy('route_type')->sortBy('name') as $route)
                <tr>
                    <td>{{ $route->name }}</td>
                    <td><span class="rt rt-{{ $route->route_type }}">{{ strtoupper($route->route_type) }}</span></td>
                    <td style="text-align:right;font-variant-numeric:tabular-nums">{{ number_format((int)$route->duct_length_m) }} m</td>
                    <td>{{ $route->fiber_count ? $route->fiber_count.'F' : '-' }}</td>
                    <td>{{ $route->microduct_type ?: '-' }} @if($route->microduct_count && $route->microduct_type)×{{ $route->microduct_count }}@endif</td>
                    <td>{{ $route->installation_type === 'underground' ? 'Podz.' : ($route->installation_type === 'aerial' ? 'Vazduh.' : ($route->installation_type ?: '-')) }}</td>
                    <td>{{ $route->status ?: '-' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Cabinets --}}
    <h2>ODO ormarići <span class="badge">{{ $project->cabinets->count() }}</span></h2>
    <table>
        <thead>
            <tr>
                <th>Naziv</th>
                <th>ODF</th>
                <th>Kuce</th>
                <th>Kapacitet</th>
                <th>Splitteri</th>
                <th>Iskorištenost</th>
                <th>Koordinate</th>
            </tr>
        </thead>
        <tbody>
            @foreach($project->cabinets->sortBy('name') as $cabinet)
                @php $hc = $cabinet->houses->count(); @endphp
                <tr>
                    <td>{{ $cabinet->name }}</td>
                    <td>{{ $cabinet->odf?->name ?? '-' }}</td>
                    <td style="text-align:right">{{ $hc }}</td>
                    <td style="text-align:right">{{ $cabinet->capacity }}</td>
                    <td style="text-align:right">{{ $cabinet->splitter_count }}</td>
                    <td style="text-align:right">{{ $cabinet->capacity > 0 ? round($hc / $cabinet->capacity * 100) : 0 }}%</td>
                    <td style="font-size:9px;font-variant-numeric:tabular-nums">{{ $cabinet->latitude ? round($cabinet->latitude,5).','.round($cabinet->longitude,5) : '-' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- ODF --}}
    @if($project->odfs->count())
    <h2>ODF <span class="badge">{{ $project->odfs->count() }}</span></h2>
    <table>
        <thead>
            <tr>
                <th>Naziv</th>
                <th>Adresa</th>
                <th>Kapacitet vlakana</th>
                <th>Portova</th>
                <th>Ormarića</th>
                <th>Koordinate</th>
            </tr>
        </thead>
        <tbody>
            @foreach($project->odfs as $odf)
                <tr>
                    <td>{{ $odf->name }}</td>
                    <td>{{ $odf->address ?? '-' }}</td>
                    <td style="text-align:right">{{ $odf->fiber_capacity ?? '-' }}</td>
                    <td style="text-align:right">{{ $odf->port_count ?? '-' }}</td>
                    <td style="text-align:right">{{ $odf->cabinets->count() }}</td>
                    <td style="font-size:9px;font-variant-numeric:tabular-nums">{{ $odf->latitude ? round($odf->latitude,5).','.round($odf->longitude,5) : '-' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    {{-- Houses --}}
    <h2>Kuce / Priključci <span class="badge">{{ $project->houses->count() }}</span></h2>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Oznaka</th>
                <th>Adresa</th>
                <th>ODO ormarić</th>
                <th>Status</th>
                <th>Koordinate</th>
            </tr>
        </thead>
        <tbody>
            @foreach($project->houses->sortBy('label') as $index => $house)
                <tr>
                    <td style="text-align:right;color:#64748b">{{ $index + 1 }}</td>
                    <td><b>{{ $house->label }}</b></td>
                    <td>{{ $house->address ?? '-' }}</td>
                    <td>{{ $house->cabinet?->name ?? '—' }}</td>
                    <td>{{ $house->status ?? '-' }}</td>
                    <td style="font-size:9px;font-variant-numeric:tabular-nums">{{ $house->latitude ? round($house->latitude,5).','.round($house->longitude,5) : '-' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Validation --}}
    @php
        $errCount = $validationItems->where('level','error')->count();
        $warnCount = $validationItems->where('level','warning')->count();
    @endphp
    <h2>
        Provjera projekta
        <span class="badge">{{ $errCount }} greški</span>
        <span class="badge">{{ $warnCount }} upozorenja</span>
    </h2>
    @if($validationItems->where('level','ok')->count() === $validationItems->count())
        <p style="color:#166534;font-weight:700">✓ Projekat nema otvorenih upozorenja.</p>
    @else
        <table>
            <thead>
                <tr><th>Nivo</th><th>Element</th><th>Poruka</th><th>Preporuka</th></tr>
            </thead>
            <tbody>
                @foreach($validationItems->whereIn('level',['error','warning','info']) as $item)
                    <tr>
                        <td class="v-{{ $item['level'] }}">{{ strtoupper($item['level']) }}</td>
                        <td style="font-size:9px;color:#64748b">{{ $item['element_type'] }} #{{ $item['element_id'] }}</td>
                        <td>{{ $item['message'] }}</td>
                        <td style="color:#475569">{{ $item['recommendation'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

</main>
</body>
</html>
