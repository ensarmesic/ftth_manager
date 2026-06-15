<!doctype html>
<html lang="bs">
<head>
    <meta charset="utf-8">
    <title>{{ $project->name }} - FTTH print</title>
    <style>
        body { margin: 0; color: #111827; font: 12px/1.35 Arial, sans-serif; }
        main { padding: 24px; }
        h1 { margin: 0 0 4px; font-size: 22px; }
        h2 { margin: 22px 0 8px; font-size: 15px; border-bottom: 1px solid #111827; padding-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #cbd5e1; padding: 5px 6px; text-align: left; vertical-align: top; }
        th { background: #f1f5f9; }
        .meta { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-top: 14px; }
        .box { border: 1px solid #cbd5e1; padding: 8px; }
        .box b { display: block; font-size: 10px; text-transform: uppercase; color: #475569; }
        .toolbar { margin-bottom: 18px; }
        .toolbar button, .toolbar a { border: 1px solid #111827; background: white; color: #111827; padding: 7px 10px; text-decoration: none; font-weight: 700; }
        .level-error { color: #991b1b; font-weight: 700; }
        .level-warning { color: #92400e; font-weight: 700; }
        .level-ok { color: #166534; font-weight: 700; }
        @media print {
            .toolbar { display: none; }
            main { padding: 0; }
            h2 { break-after: avoid; }
            tr { break-inside: avoid; }
        }
    </style>
</head>
<body>
<main>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Print / PDF</button>
        <a href="{{ route('map.dashboard') }}">Nazad na mapu</a>
    </div>

    <h1>{{ $project->name }}</h1>
    <div>{{ $project->code }} | {{ $project->location }} | Status: {{ $project->status }}</div>

    <section class="meta">
        <div class="box"><b>ODF</b>{{ $project->odfs->count() }}</div>
        <div class="box"><b>FTTH</b>{{ $project->cabinets->count() }}</div>
        <div class="box"><b>Kuce</b>{{ $project->houses->count() }}</div>
        <div class="box"><b>Trase</b>{{ $project->routes->count() }}</div>
    </section>

    <h2>Materijalni sazetak</h2>
    <table>
        <tbody>
        @forelse($materials as $key => $value)
            <tr><th>{{ str_replace('_', ' ', $key) }}</th><td>{{ is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (is_numeric($value) ? round((float) $value, 2) : $value) }}</td></tr>
        @empty
            <tr><td>Nema obracuna materijala.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>Trase</h2>
    <table>
        <thead><tr><th>Naziv</th><th>Tip</th><th>Duzina</th><th>Kabal</th><th>Mikrocijev</th></tr></thead>
        <tbody>
        @foreach($project->routes as $route)
            <tr>
                <td>{{ $route->name }}</td>
                <td>{{ $route->route_type }}</td>
                <td>{{ (int) $route->duct_length_m }} m</td>
                <td>{{ $route->fiber_count ?: '-' }} F</td>
                <td>{{ $route->microduct_type ?: '-' }} x {{ $route->microduct_count }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <h2>FTTH ormarici</h2>
    <table>
        <thead><tr><th>Naziv</th><th>ODF</th><th>Kuce</th><th>Kapacitet</th><th>Koordinate</th></tr></thead>
        <tbody>
        @foreach($project->cabinets as $cabinet)
            <tr>
                <td>{{ $cabinet->name }}</td>
                <td>{{ $cabinet->odf?->name ?? '-' }}</td>
                <td>{{ $cabinet->houses->count() }}</td>
                <td>{{ $cabinet->capacity }}</td>
                <td>{{ $cabinet->latitude }}, {{ $cabinet->longitude }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <h2>Provjera projekta</h2>
    <table>
        <thead><tr><th>Nivo</th><th>Poruka</th><th>Preporuka</th></tr></thead>
        <tbody>
        @foreach($validationItems as $item)
            <tr>
                <td class="level-{{ $item['level'] }}">{{ strtoupper($item['level']) }}</td>
                <td>{{ $item['message'] }}</td>
                <td>{{ $item['recommendation'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</main>
</body>
</html>
