<!DOCTYPE html>
<html lang="bs">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Fiber Shema – {{ $project->name }}</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9pt; color: #1a1a2e; background: #fff; }
    .page-header { border-bottom: 3px solid #1d4ed8; padding: 10px 14px 8px; margin-bottom: 12px; }
    .page-header h1 { font-size: 14pt; font-weight: 900; color: #1d4ed8; }
    .page-header .meta { font-size: 8pt; color: #555; margin-top: 2px; }
    .page-header .stats { margin-top: 6px; display: inline-block; }
    .chip { display: inline-block; border: 1px solid #bcd4f0; border-radius: 999px; background: #edf6ff; padding: 1px 7px; color: #005f96; font-size: 7.5pt; font-weight: 800; margin-right: 3px; }
    .section { margin-bottom: 16px; }
    .odf-title { background: #1d4ed8; color: #fff; font-size: 10pt; font-weight: 900; padding: 5px 10px; border-radius: 5px 5px 0 0; }
    .odf-meta { background: #eaf3ff; border: 1px solid #b8d7ef; border-top: none; padding: 4px 10px 5px; font-size: 7.5pt; color: #334; margin-bottom: 8px; border-radius: 0 0 4px 4px; }
    .cabinet-block { border: 1.5px solid #65a845; border-radius: 6px; margin-bottom: 8px; page-break-inside: avoid; }
    .cabinet-block.warn { border-color: #d99a15; }
    .cabinet-block.full { border-color: #dc2626; }
    .cabinet-header { background: #f2faeb; padding: 5px 8px 4px; border-radius: 4px 4px 0 0; display: table; width: 100%; }
    .cabinet-block.warn .cabinet-header { background: #fff8e8; }
    .cabinet-block.full .cabinet-header { background: #fff2f2; }
    .cabinet-name { font-size: 9pt; font-weight: 900; color: #1a3a0a; display: table-cell; }
    .cabinet-info { font-size: 7.5pt; color: #555; display: table-cell; text-align: right; }
    .util-bar-wrap { background: #dfe7ef; height: 4px; border-radius: 2px; margin: 2px 8px 0; }
    .util-bar-fill { height: 4px; border-radius: 2px; background: #65a845; }
    .cabinet-block.warn .util-bar-fill { background: #d99a15; }
    .cabinet-block.full .util-bar-fill { background: #dc2626; }
    .splitter-table { width: 100%; border-collapse: collapse; margin-top: 4px; }
    .splitter-table th { background: #e8f4e8; font-size: 7pt; font-weight: 900; color: #2d5a1b; padding: 3px 5px; border: 1px solid #c8e0c0; text-align: center; }
    .splitter-table td { font-size: 7.5pt; padding: 2px 5px; border: 1px solid #e2eed8; vertical-align: middle; }
    .splitter-label-cell { background: #f5fbf0; font-weight: 800; color: #3a6b25; text-align: center; width: 52px; }
    .port-used { background: #eaf6ff; color: #004f7d; border-left: 3px solid #2684c2; }
    .port-free { background: #fafafa; color: #9aa6b2; border-left: 3px solid #d1dce6; font-style: italic; }
    .port-num { color: #888; font-size: 6.5pt; font-weight: 700; margin-right: 2px; }
    .fiber-tag { display: inline-block; background: #f3e8ff; border: 1px solid #d8b4fe; border-radius: 3px; color: #6d28d9; font-size: 6.5pt; font-weight: 900; padding: 0 4px; margin-left: 4px; }
    .color-legend { margin: -4px 14px 10px; border: 1px solid #dbe5ef; border-radius: 5px; padding: 5px 7px; }
    .color-item { display: inline-block; margin-right: 7px; font-size: 6pt; color: #475569; }
    .color-dot { display: inline-block; width: 8px; height: 8px; margin-right: 2px; border: 1px solid #64748b; border-radius: 50%; vertical-align: middle; }
    .child-cabinet-block { margin-left: 18px; margin-top: 6px; border: 1.5px dashed #2f8f5b; border-radius: 6px; }
    .child-cabinet-block .cabinet-header { background: #f0fff7; border-radius: 4px 4px 0 0; }
    .child-label { font-size: 7pt; color: #2f8f5b; font-weight: 900; background: #e6fff2; border: 1px solid #a5d6bb; border-radius: 3px; padding: 0 4px; margin-right: 4px; }
    .branch-section { margin-bottom: 10px; }
    .branch-title { border-bottom: 2px solid #1d4ed8; padding: 3px 2px 3px; margin-bottom: 6px; display: table; width: 100%; }
    .branch-title-name { font-size: 8.5pt; font-weight: 900; color: #1d4ed8; display: table-cell; }
    .branch-title-fiber { font-size: 7.5pt; font-weight: 900; color: #1d4ed8; display: table-cell; text-align: right; background: #eaf3ff; border: 1px solid #b8d7ef; border-radius: 3px; padding: 1px 6px; }
    .reserve-box { margin-top: 8px; border: 1.5px solid #16a34a; border-radius: 6px; background: #f0fff4; padding: 7px 12px; }
    .reserve-title { font-size: 8.5pt; font-weight: 900; color: #15803d; }
    .reserve-meta { font-size: 7.5pt; color: #555; margin-top: 2px; }
    .unlinked-box { margin-top: 8px; border: 1.5px solid #f59e0b; border-radius: 6px; background: #fffbeb; padding: 7px 12px; }
    .unlinked-title { font-size: 8pt; font-weight: 900; color: #92400e; }
    .unlinked-list { margin-top: 4px; font-size: 7.5pt; color: #78350f; }
    .page-break { page-break-before: always; }
    .footer { margin-top: 18px; border-top: 1px solid #ccc; padding-top: 6px; font-size: 7pt; color: #888; text-align: right; }
    table { width: 100%; }
</style>
</head>
<body>

<div class="page-header">
    <h1>Fiber Shema – {{ $project->name }}</h1>
    <div class="meta">
        Kod: {{ $project->code }}
        @if($project->location) &nbsp;/&nbsp; Lokacija: {{ $project->location }} @endif
        @if($project->investor) &nbsp;/&nbsp; Investitor: {{ $project->investor }} @endif
        &nbsp;/&nbsp; Generisano: {{ now()->format('d.m.Y') }}
    </div>
    <div class="stats">
        <span class="chip">{{ $project->odfs->count() }} ODF</span>
        <span class="chip">{{ $allCabinets->count() }} FTTH ormarici</span>
        <span class="chip">{{ $totalHouses }}/{{ $totalCapacity }} kuca</span>
        <span class="chip">{{ $projectUtilization }}% popunjenost</span>
        @if($usedFiberTo > 0)
            <span class="chip">Koristeno: F1–F{{ $usedFiberTo }}</span>
            <span class="chip">Rezerva: F{{ $reserveFrom }}–{{ $reserveTo }} ({{ $reserveTo - $reserveFrom + 1 }} niti)</span>
        @endif
    </div>
</div>
<div class="color-legend">
    @php
        $pdfFibersPerTube = str_ends_with($project->fiber_layout ?? '6x24', 'x12') ? 12 : 24;
        $pdfTubeCount = (int) str($project->fiber_layout ?? '6x24')->before('x')->value();
    @endphp
    <b style="font-size:6.5pt">COLOR CODE {{ $pdfTubeCount * $pdfFibersPerTube }}F · {{ $pdfTubeCount }} × {{ $pdfFibersPerTube }} · {{ ($project->fiber_color_standard ?? 'telcordia') === 'din_vde' ? 'DIN/VDE' : 'TIA-598/Telcordia' }}:</b>
    @foreach(\App\Support\FiberColorCode::paletteFor($project->fiber_color_standard ?? 'telcordia') as $position => $color)
        <span class="color-item"><i class="color-dot" style="background:{{ $color['hex'] }}"></i>{{ $position }} {{ $color['name'] }}</span>
    @endforeach
</div>

@forelse($project->odfs as $odf)
    <div class="section">
        <div class="odf-title">ODF: {{ $odf->name }}</div>
        <div class="odf-meta">
            {{ $odf->address ?: 'Bez adrese' }}
            &nbsp;·&nbsp; Kapacitet: {{ $odf->fiber_capacity }} vlakana
            &nbsp;·&nbsp; {{ $odf->port_count }}P
            &nbsp;·&nbsp; {{ $odf->cabinets->count() }} izlaza
        </div>

        @php
            $pdfBranches = $project->branches
                ->where('type', 'secondary')
                ->where('odf_id', $odf->id)
                ->sortBy(fn($b) => sprintf('%06d|%s', (int)($b->sort_order ?? 0), (string)$b->name));
            $pdfHasCabinets = $pdfBranches->flatMap->cabinets->isNotEmpty();
        @endphp
        @if($pdfHasCabinets)
            @foreach($pdfBranches as $pdfBranch)
                @php
                    $pdfBranchCabs = $pdfBranch->cabinets
                        ->sortBy(fn($c) => sprintf('%06d|%s', (int)($c->branch_order ?? 0), (string)$c->name));
                    $pbfMin = PHP_INT_MAX; $pbfMax = 0;
                    foreach ($pdfBranchCabs as $bc) {
                        if (isset($fiberAllocations[$bc->id])) {
                            $pbfMin = min($pbfMin, $fiberAllocations[$bc->id]['from']);
                            $pbfMax = max($pbfMax, $fiberAllocations[$bc->id]['to']);
                        }
                    }
                    $pbfStr = $pbfMax > 0 ? ($pbfMin === $pbfMax ? "F{$pbfMin}" : "F{$pbfMin}–F{$pbfMax}") : '?';
                @endphp
                @if($pdfBranchCabs->isNotEmpty())
                <div class="branch-section">
                    <div class="branch-title">
                        <span class="branch-title-name">{{ $pdfBranch->name }}</span>
                        <span class="branch-title-fiber">{{ $pbfStr }}</span>
                    </div>
                    @foreach($pdfBranchCabs as $cabinet)
                        @php
                            $houses = $cabinet->houses->values();
                            $capacity = max($cabinet->capacity, 12);
                            $used = $cabinet->houses_count ?? $cabinet->houses->count();
                            $utilization = min(100, round($used / max($capacity, 1) * 100));
                            $state = $utilization >= 100 ? 'full' : ($utilization >= 80 ? 'warn' : '');
                            $fiberRange = $fiberAllocations[$cabinet->id] ?? null;
                            $fiberLabel = $fiberRange ? ($fiberRange['from'] === $fiberRange['to'] ? (string) $fiberRange['from'] : $fiberRange['from'].'-'.$fiberRange['to']) : '?';
                            $activeSplitters = $neededSplitters($cabinet);
                            $portsPerSplitter = max(1, (int) $cabinet->ports_per_splitter);
                            $splitterRatio = '1:' . $portsPerSplitter;
                        @endphp
                        <div class="cabinet-block {{ $state }}">
                            <div class="cabinet-header">
                                <span class="cabinet-name">{{ $cabinet->name }}</span>
                                <span class="cabinet-info">
                                    {{ $used }}/{{ $capacity }} kuca
                                    &nbsp;·&nbsp; {{ $activeSplitters }}× {{ $splitterRatio }}
                                    &nbsp;·&nbsp; <span class="fiber-tag">F {{ $fiberLabel }}</span>
                                    &nbsp;·&nbsp; {{ $utilization }}%
                                    @if($cabinet->address) &nbsp;·&nbsp; {{ $cabinet->address }} @endif
                                </span>
                            </div>
                            <div class="util-bar-wrap"><div class="util-bar-fill" style="width:{{ $utilization }}%"></div></div>

                            <table class="splitter-table">
                                <thead>
                                    <tr>
                                        <th style="width:52px">Splitter</th>
                                        @for($p = 1; $p <= $portsPerSplitter; $p++)
                                            <th>Port {{ $p }}</th>
                                        @endfor
                                    </tr>
                                </thead>
                                <tbody>
                                    @for($splitter = 1; $splitter <= max($activeSplitters, 1); $splitter++)
                                        <tr>
                                            <td class="splitter-label-cell">S{{ $splitter }} {{ $splitterRatio }}</td>
                                            @for($port = 1; $port <= $portsPerSplitter; $port++)
                                                @php
                                                    $absolutePort = ($splitter - 1) * $portsPerSplitter + $port;
                                                    $house = $houses->get($absolutePort - 1);
                                                @endphp
                                                @if($house)
                                                    <td class="port-used"><span class="port-num">P{{ $absolutePort }}</span>{{ $house->label }}</td>
                                                @else
                                                    <td class="port-free"><span class="port-num">P{{ $absolutePort }}</span>Slobodno</td>
                                                @endif
                                            @endfor
                                        </tr>
                                    @endfor
                                </tbody>
                            </table>

                            @if($cabinet->childCabinets->isNotEmpty())
                                @foreach($cabinet->childCabinets as $childCabinet)
                                    @php
                                        $childHouses = $childCabinet->houses->values();
                                        $childCapacity = max($childCabinet->capacity, 12);
                                        $childUsed = $childCabinet->houses_count ?? $childCabinet->houses->count();
                                        $childUtilization = min(100, round($childUsed / max($childCapacity, 1) * 100));
                                        $childState = $childUtilization >= 100 ? 'full' : ($childUtilization >= 80 ? 'warn' : '');
                                        $childFiberRange = $fiberAllocations[$childCabinet->id] ?? null;
                                        $childFiberLabel = $childFiberRange ? ($childFiberRange['from'] === $childFiberRange['to'] ? (string) $childFiberRange['from'] : $childFiberRange['from'].'-'.$childFiberRange['to']) : '?';
                                        $childActiveSplitters = $neededSplitters($childCabinet);
                                        $childPortsPerSplitter = max(1, (int) $childCabinet->ports_per_splitter);
                                        $childSplitterRatio = '1:' . $childPortsPerSplitter;
                                    @endphp
                                    <div class="child-cabinet-block {{ $childState }}">
                                        <div class="cabinet-header">
                                            <span class="cabinet-name">
                                                <span class="child-label">IZ {{ $cabinet->name }}</span>
                                                {{ $childCabinet->name }}
                                            </span>
                                            <span class="cabinet-info">
                                                {{ $childUsed }}/{{ $childCapacity }} kuca
                                                &nbsp;·&nbsp; {{ $childActiveSplitters }}× {{ $childSplitterRatio }}
                                                &nbsp;·&nbsp; <span class="fiber-tag">F {{ $childFiberLabel }}</span>
                                                &nbsp;·&nbsp; {{ $childUtilization }}%
                                            </span>
                                        </div>
                                        <div class="util-bar-wrap"><div class="util-bar-fill" style="width:{{ $childUtilization }}%"></div></div>

                                        <table class="splitter-table">
                                            <thead>
                                                <tr>
                                                    <th style="width:52px">Splitter</th>
                                                    @for($p = 1; $p <= $childPortsPerSplitter; $p++)
                                                        <th>Port {{ $p }}</th>
                                                    @endfor
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @for($childSplitter = 1; $childSplitter <= max($childActiveSplitters, 1); $childSplitter++)
                                                    <tr>
                                                        <td class="splitter-label-cell">S{{ $childSplitter }} {{ $childSplitterRatio }}</td>
                                                        @for($childPort = 1; $childPort <= $childPortsPerSplitter; $childPort++)
                                                            @php
                                                                $childAbsolutePort = ($childSplitter - 1) * $childPortsPerSplitter + $childPort;
                                                                $childHouse = $childHouses->get($childAbsolutePort - 1);
                                                            @endphp
                                                            @if($childHouse)
                                                                <td class="port-used"><span class="port-num">P{{ $childAbsolutePort }}</span>{{ $childHouse->label }}</td>
                                                            @else
                                                                <td class="port-free"><span class="port-num">P{{ $childAbsolutePort }}</span>Slobodno</td>
                                                            @endif
                                                        @endfor
                                                    </tr>
                                                @endfor
                                            </tbody>
                                        </table>
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    @endforeach
                </div>
                @endif
            @endforeach
        @else
            <p style="color:#888;font-size:8pt;padding:6px 10px">ODF jos nema povezane FTTH ormarice u granama.</p>
        @endif
    </div>
@empty
    <p style="color:#888;padding:10px">Projekat nema ODF lokaciju.</p>
@endforelse

@if($project->houses->isNotEmpty())
    <div class="unlinked-box">
        <div class="unlinked-title">Nepovezane kuce ({{ $project->houses->count() }})</div>
        <div class="unlinked-list">
            @foreach($project->houses as $house)
                {{ $house->label }}@if($house->address) ({{ $house->address }})@endif{{ $loop->last ? '' : ',' }}
            @endforeach
        </div>
    </div>
@endif

@if($usedFiberTo > 0)
    <div class="reserve-box">
        <div class="reserve-title">Rezerva vlakana: F{{ $reserveFrom }}–F{{ $reserveTo }}</div>
        <div class="reserve-meta">{{ $reserveTo - $reserveFrom + 1 }} slobodnih niti &nbsp;·&nbsp; Ukupno koristeno: F1–F{{ $usedFiberTo }} ({{ $usedFiberTo }} niti)</div>
    </div>
@endif

<div class="footer">
    {{ $project->name }} &nbsp;/&nbsp; {{ $project->code }} &nbsp;/&nbsp; FTTH Manager &nbsp;/&nbsp; {{ now()->format('d.m.Y H:i') }}
</div>

</body>
</html>
