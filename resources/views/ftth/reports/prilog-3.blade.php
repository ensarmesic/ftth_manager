<!doctype html>
<html lang="bs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Prilog 3 - {{ $project->name }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 10mm 11mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #e5e7eb;
            color: #1f1f1f;
            font-family: "Times New Roman", Times, serif;
            font-size: 15px;
            line-height: 1.34;
        }

        .toolbar {
            display: flex;
            gap: 10px;
            justify-content: center;
            padding: 16px;
            font-family: Arial, sans-serif;
        }

        .toolbar a,
        .toolbar button {
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #fff;
            color: #0f172a;
            cursor: pointer;
            font: 700 13px Arial, sans-serif;
            padding: 9px 13px;
            text-decoration: none;
        }

        .toolbar button {
            background: #0f172a;
            color: #fff;
        }

        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto 24px;
            background: #fff;
            padding: 10mm 11mm;
            box-shadow: 0 20px 70px rgba(15, 23, 42, .22);
        }

        h1 {
            font-size: 21px;
            margin: 0 0 7px;
            text-transform: uppercase;
        }

        h2 {
            font-size: 17px;
            margin: 13px 0 15px;
        }

        h3 {
            font-size: 17px;
            margin: 16px 0 11px;
            text-transform: uppercase;
        }

        .project-meta {
            border-bottom: 1px solid #666;
            color: #444;
            font-family: Arial, sans-serif;
            font-size: 10px;
            margin-bottom: 13px;
            padding-bottom: 6px;
        }

        .line-item {
            align-items: baseline;
            display: flex;
            gap: 5px;
            margin: 9.2px 0;
            white-space: nowrap;
        }

        .line-no {
            flex: 0 0 auto;
        }

        .line-label {
            flex: 0 1 auto;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .dots {
            border-bottom: 2px dotted #222;
            flex: 1 1 auto;
            min-width: 42px;
            transform: translateY(-4px);
        }

        .qty {
            flex: 0 0 auto;
            min-width: 106px;
            text-align: right;
        }

        .qty.strong {
            font-style: italic;
            font-weight: 700;
        }

        .formula {
            margin: 10px 0 5px;
        }

        .formula.underlined {
            font-weight: 700;
            text-decoration: underline;
        }

        .divider {
            border-top: 1px solid #555;
            margin: 21px 0 13px;
        }

        .muted {
            color: #555;
            font-size: 11px;
            margin-top: 10px;
        }

        @media print {
            body {
                background: #fff;
            }

            .toolbar {
                display: none;
            }

            .page {
                box-shadow: none;
                margin: 0;
                min-height: auto;
                padding: 0;
                width: auto;
            }

            .muted {
                display: none;
            }
        }
    </style>
</head>
<body>
@php
    $fmt = fn ($value) => number_format((float) $value, 2, ',', '.');
@endphp

<div class="toolbar">
    <a href="{{ route('reports.index') }}">Nazad na izvjestaje</a>
    <button type="button" onclick="window.print()">Print dokument</button>
</div>

<main class="page">
    <h1>PRILOG 3</h1>
    <div class="project-meta">
        Projekat: <b>{{ $project->name }}</b>
        @if($project->code) | Sifra: {{ $project->code }} @endif
        @if($project->location) | Lokacija: {{ $project->location }} @endif
        | Generisano: {{ now()->format('d.m.Y') }}
    </div>

    <h2>Segmenti kablovske kanalizacije</h2>

    @foreach($segments as $index => $item)
        <div class="line-item">
            <span class="line-no">{{ $index + 1 }}.</span>
            <span class="line-label">{{ $item['label'] }}</span>
            <span class="dots"></span>
            <span class="qty {{ $item['strong'] ?? false ? 'strong' : '' }}">{{ $fmt($item['quantity']) }} {{ $item['unit'] }}</span>
        </div>
    @endforeach

    <div class="divider"></div>

    <p class="formula">
        {{ count($segments) + 1 }}. Korisnicke instalacije cca 50 m po korisniku X {{ $subscriberCount }} = {{ $fmt($userInstall) }} metara
    </p>
    <p class="formula underlined">
        {{ count($segments) + 2 }}. Korisnicka optika (50*{{ $subscriberCount }})+10%={{ $fmt($userFiber) }} metara
    </p>

    <h3>REKAPITULACIJA</h3>

    @foreach($recap as $index => $item)
        <div class="line-item">
            <span class="line-no">{{ $index + 1 }}.</span>
            <span class="line-label">{{ $item['label'] }}</span>
            <span class="dots"></span>
            <span class="qty">{{ $item['prefix'] ?? '' }}{{ $fmt($item['quantity']) }} {{ $item['unit'] }}</span>
        </div>
    @endforeach

    <p class="muted">
        Napomena: Dokument je generisan iz unesenih FTTH podataka projekta. Stavke koje nisu posebno unesene prikazane su kao 0,00.
    </p>
</main>
</body>
</html>
