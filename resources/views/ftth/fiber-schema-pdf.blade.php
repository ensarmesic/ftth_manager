<!DOCTYPE html>
<html lang="bs">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Fiber šema – {{ $project->name }}</title>
<style>
    @page { margin: 0; }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9pt; color: #0f172a; }
    .sheet { position: relative; width: {{ $sheet['width'] }}mm; height: {{ $sheet['height'] }}mm; overflow: hidden; }
    .sheet img { position: absolute; left: 0; top: 0; width: {{ $sheet['width'] }}mm; height: {{ $sheet['height'] }}mm; }
    .label { position: absolute; line-height: 1; white-space: nowrap; font-family: DejaVu Sans, sans-serif; }
    .page { page-break-before: always; padding: 20mm 15mm 15mm 25mm; width: {{ min($sheet['width'], 460) }}mm; }
    h1 { font-size: 16pt; color: #004f7d; }
    h2 { margin: 8mm 0 3mm; font-size: 12pt; color: #0f172a; }
    .meta { margin-top: 2mm; font-size: 9pt; color: #475569; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #f1f5f9; padding: 2mm; border: 0.3mm solid #cbd5e1; font-size: 8pt; text-align: left; }
    td { padding: 1.6mm 2mm; border: 0.3mm solid #e2e8f0; font-size: 8.5pt; vertical-align: top; }
    td.num { text-align: right; white-space: nowrap; }
    .ok { color: #166534; font-weight: bold; }
    .warning { color: #92400e; font-weight: bold; }
    .error { color: #b91c1c; font-weight: bold; }
    .estimate { color: #475569; font-weight: bold; }
    .issue { margin-top: 1.5mm; font-size: 9pt; }
    .footer { margin-top: 8mm; padding-top: 2mm; border-top: 0.3mm solid #cbd5e1; font-size: 8pt; color: #64748b; }
</style>
</head>
<body>
{{-- Geometry from the SVG; text placed on top in DejaVu Sans (has č, ć, đ), in the same mm coordinates.
     DejaVu cap height is ~0.73 em (shrunk 6 % to match Arial widths); dompdf puts the baseline ~1 em below the box top. --}}
<div class="sheet">
    <img src="data:image/svg+xml;base64,{{ base64_encode($sheetSvg) }}" alt="Fiber šema">
    @foreach($labels as $label)
        @php
            $size = $label['h'] / 0.73 * 0.94;
            $baseline = $label['y'] + match ($label['va']) { 'm' => $label['h'] / 2, 't' => $label['h'], default => 0 };
            $left = match ($label['ha']) { 'c' => $label['x'] - 60, 'r' => $label['x'] - 120, default => $label['x'] };
        @endphp
        <div class="label" style="left:{{ round($left, 2) }}mm;top:{{ round($baseline - 1.0 * $size, 2) }}mm;font-size:{{ round($size, 2) }}mm;color:{{ $label['color'] }};{{ $label['ha'] !== 'l' ? 'width:120mm;text-align:'.($label['ha'] === 'c' ? 'center' : 'right').';' : '' }}{{ $label['bold'] ? 'font-weight:bold;' : '' }}">{{ $label['s'] }}</div>
    @endforeach
</div>

<div class="page">
    <h1>Optički budžet i kontrola – {{ $project->name }}</h1>
    <p class="meta">{{ $plan['profile']['standard'] }} · {{ $plan['profile']['label'] }} · dozvoljeni ODN {{ $plan['profile']['min'] }}–{{ $plan['profile']['max'] }} dB · inženjerska margina {{ $plan['engineeringMargin'] }} dB · PLAN ID {{ $plan['signature'] }}
        @unless($plan['assumptionsConfirmed']) · <b>procjena: oprema (Tx/Rx) još nije potvrđena</b>@endunless</p>

    <h2>Dodjela vlakana po ODF-u</h2>
    <table>
        <thead><tr><th>ODF</th><th>Dodijeljeno</th><th>Najviše vlakno</th><th>Slobodan raspon</th><th>Kapacitet</th></tr></thead>
        <tbody>
        @foreach($plan['odfs'] as $odfPlan)
            <tr><td>{{ $odfPlan['name'] }}</td><td class="num">{{ $odfPlan['usedFibers'] }}</td><td class="num">{{ $odfPlan['usedTo'] ? 'F'.$odfPlan['usedTo'] : '—' }}</td><td>{{ $odfPlan['usedTo'] < $odfPlan['capacity'] ? 'F'.$odfPlan['reserveFrom'].'–F'.$odfPlan['reserveTo'] : 'nema' }}</td><td class="num">{{ $odfPlan['capacity'] }}F</td></tr>
        @endforeach
        </tbody>
    </table>

    <h2>Power budget po ODO ormariću (nepovoljniji smjer)</h2>
    <table>
        <thead><tr><th>ODO</th><th>Vlakna</th><th>Putanja</th><th>Splitteri</th><th>DS / US gubitak</th><th>ODN</th><th>+ margina</th><th>Rezerva</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($plan['connections'] as $connection)
            <tr>
                <td>{{ $connection['cabinet'] }}</td>
                <td>{{ $connection['fiber_from'] ? 'F'.$connection['fiber_from'].($connection['fiber_to'] !== $connection['fiber_from'] ? '–F'.$connection['fiber_to'] : '') : '—' }}</td>
                <td class="num">{{ number_format($connection['route_km'], 3, ',', '.') }} km</td>
                <td>{{ $connection['splitter_ratio'] }}</td>
                <td class="num">{{ $connection['downstream_loss_db'] }} / {{ $connection['upstream_loss_db'] }} dB</td>
                <td class="num">{{ $connection['loss_db'] }} dB</td>
                <td class="num">{{ $connection['design_loss_db'] }} dB</td>
                <td class="num">{{ $connection['headroom_db'] }} dB</td>
                <td class="{{ $connection['budget_status'] }}">{{ ['ok' => 'U redu', 'warning' => 'Provjeriti', 'error' => 'Van klase', 'estimate' => 'Procjena'][$connection['budget_status']] ?? $connection['budget_status'] }}</td>
            </tr>
        @empty
            <tr><td colspan="9">Nema ODO ormarića za proračun.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>Otvorene kontrolne stavke</h2>
    @forelse($plan['issues'] as $issue)
        <p class="issue {{ $issue['level'] === 'error' ? 'error' : 'warning' }}">{{ $issue['level'] === 'error' ? 'GREŠKA' : 'UPOZORENJE' }} · <span style="color:#0f172a;font-weight:normal">{{ $issue['message'] }}</span></p>
    @empty
        <p class="issue ok">Nema otvorenih stavki.</p>
    @endforelse

    <p class="footer">{{ $project->name }} · {{ $project->code }} · FTTH Manager v{{ config('app.version') }} · {{ now()->format('d.m.Y. H:i') }}</p>
</div>
</body>
</html>
