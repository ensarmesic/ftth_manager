<!doctype html>
<html lang="bs">
<head>
    <meta charset="utf-8">
    <title>Kontrola geodetskog TXT uvoza</title>
    <style>
        @page { margin: 18mm 14mm 15mm; }
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 9px; }
        h1 { margin: 0; color: #0f766e; font-size: 18px; }
        .muted { color: #64748b; }
        .header { border-bottom: 2px solid #0f766e; padding-bottom: 9px; margin-bottom: 10px; }
        .summary { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 0 -6px 12px; }
        .summary td { width: 16.66%; border: 1px solid #dbeafe; background: #f8fafc; padding: 7px; }
        .summary b { display: block; color: #0f766e; font-size: 14px; }
        .quality-ok { color: #047857; }
        .quality-bad { color: #b91c1c; }
        h2 { margin: 12px 0 5px; font-size: 11px; color: #334155; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: #0f3b53; color: white; text-align: left; padding: 5px; }
        table.data td { border-bottom: 1px solid #e2e8f0; padding: 4px 5px; vertical-align: top; }
        table.data tr:nth-child(even) td { background: #f8fafc; }
        .badge { display: inline-block; border-radius: 8px; padding: 1px 5px; font-size: 7px; font-weight: bold; }
        .badge-ok { color: #047857; background: #d1fae5; }
        .badge-bad { color: #b91c1c; background: #fee2e2; }
        .footer { margin-top: 10px; border-top: 1px solid #cbd5e1; padding-top: 6px; color: #64748b; font-size: 7px; }
    </style>
</head>
<body>
    @php
        $qualityErrors = $preview['quality']['errors'] ?? [];
        $completeRoutes = $preview['quality']['complete_drop_routes'] ?? 0;
        $comparison = $preview['saved_comparison'] ?? [];
    @endphp
    <div class="header">
        <h1>Kontrola geodetskog TXT uvoza</h1>
        <div><b>{{ $project->code ?: $project->name }}</b> &middot; {{ $preview['filename'] ?? 'TXT fajl' }}</div>
        <div class="muted">Generisano: {{ now()->format('d.m.Y. H:i') }} &middot; Otisak fajla: {{ $preview['preview_meta']['file_fingerprint'] ?? '-' }}</div>
    </div>

    <table class="summary"><tr>
        <td><b>{{ $preview['total_points'] ?? 0 }}</b>ta&#269;aka</td>
        <td><b>{{ $preview['trench_network_count'] ?? 0 }}</b>mre&#382;a rova</td>
        <td><b>{{ count($preview['trench_runs'] ?? []) }}</b>dionica rova</td>
        <td><b>{{ count($preview['ducts'] ?? []) }}</b>mikrocijevi</td>
        <td><b>{{ $completeRoutes }}</b>dokazanih drop ruta</td>
        <td><b class="{{ count($qualityErrors) ? 'quality-bad' : 'quality-ok' }}">{{ count($qualityErrors) }}</b>gre&#353;aka</td>
    </tr></table>

    <div class="{{ count($qualityErrors) ? 'quality-bad' : 'quality-ok' }}">
        <b>{{ count($qualityErrors) ? 'UVOZ BLOKIRAN' : 'KONTROLA PROŠLA' }}</b>
        @if(count($qualityErrors)) &mdash; {{ implode(' | ', $qualityErrors) }} @endif
        &middot; Stanje u projektu: {{ ($comparison['is_saved'] ?? false) ? 'isti fajl je već sačuvan' : 'fajl još nije sačuvan' }}.
    </div>

    <h2>Dionice koordinatnog grafa rova</h2>
    <table class="data">
        <thead><tr><th>#</th><th>Oznaka</th><th>Ta&#269;ke</th><th>Du&#382;ina</th></tr></thead>
        <tbody>
        @forelse($preview['trench_runs'] ?? [] as $run)
            <tr><td>{{ $loop->iteration }}</td><td>{{ $run['code'] ?? '-' }}</td><td>{{ $run['points'] ?? 0 }}</td><td>{{ round((float) ($run['length_m'] ?? 0), 1) }} m</td></tr>
        @empty
            <tr><td colspan="4">Nema dionica rova.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>Mikrocijevi i dokaz trase</h2>
    <table class="data">
        <thead><tr><th>#</th><th>Oznaka</th><th>Tip</th><th>Du&#382;ina</th><th>Ciljni ZO</th><th>Status</th><th>Dokaz</th></tr></thead>
        <tbody>
        @forelse($preview['ducts'] ?? [] as $duct)
            @php
                $complete = ($duct['routing_status'] ?? null) === 'complete';
                $evidence = match ($duct['validation_source'] ?? null) {
                    'strict_network_graph' => 'strogi mrežni graf',
                    'surveyed_trench_route' => 'snimljeni rov',
                    default => 'nije dokazano',
                };
            @endphp
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $duct['label'] ?? '-' }}</td>
                <td>{{ $duct['route_type'] ?? '-' }}</td>
                <td>{{ round((float) ($duct['length_m'] ?? 0), 1) }} m</td>
                <td>{{ $duct['target_zo'] ?? $duct['matched_cabinet_name'] ?? '-' }}</td>
                <td><span class="badge {{ $complete ? 'badge-ok' : (($duct['routing_status'] ?? null) === 'unreachable' ? 'badge-bad' : '') }}">{{ $complete ? 'do ZO' : ($duct['routing_status'] ?? '-') }}</span></td>
                <td>{{ $evidence }}</td>
            </tr>
        @empty
            <tr><td colspan="7">Nema mikrocijevi.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="footer">Izvje&#353;taj je kontrolni pregled prije uvoza. Koordinate i geometrija nisu izmijenjene generisanjem ovog dokumenta.</div>
</body>
</html>
