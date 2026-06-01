@extends('ftth.layout')

@section('title', 'Izvjestaji')
@section('subtitle', 'Pregled projekta, kapaciteta, trasa i materijala spreman za print.')

@section('content')
<div class="mb-5 flex justify-end">
    <button onclick="window.print()" class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-semibold text-white print:hidden">Print / PDF</button>
</div>

<section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ([
        'Projekti' => $totals['projects'],
        'Kuce / prikljucci' => $totals['houses'],
        'Korisnici' => $totals['subscribers'],
        'Ormarici' => $totals['cabinets'],
        'Slobodni portovi' => $totals['free_ports'],
        'Mikrocijevi' => number_format($totals['duct']).' m',
        'Opticki kabl' => number_format($totals['fiber']).' m',
        'Materijal' => number_format($totals['materials_cost'], 2).' KM',
    ] as $label => $value)
        <article class="rounded-md border border-zinc-200 bg-white p-4 shadow-sm">
            <div class="text-sm text-zinc-500">{{ $label }}</div>
            <div class="mt-2 text-2xl font-semibold">{{ $value }}</div>
        </article>
    @endforeach
</section>

<section class="mt-6 grid gap-5">
    @forelse ($projects as $project)
        @php
            $usedPorts = $project->cabinets->sum('subscribers_count');
            $capacity = $project->cabinets->sum(fn ($cabinet) => $cabinet->capacity);
            $freePorts = max($capacity - $usedPorts, 0);
            $projectMaterialCost = $project->materials->sum(fn ($material) => $material->planned_quantity * $material->unit_price);
            $duct1410 = $project->routes->where('microduct_type', '14/10')->sum(fn ($route) => $route->duct_length_m * $route->microduct_count);
            $duct108 = $project->routes->where('microduct_type', '10/8')->sum(fn ($route) => $route->duct_length_m * $route->microduct_count);
        @endphp
        <article class="rounded-md border border-zinc-200 bg-white shadow-sm">
            <div class="border-b border-zinc-200 px-5 py-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold">{{ $project->name }}</h2>
                        <p class="text-sm text-zinc-500">{{ $project->code }} · {{ $project->location }} · {{ $project->status }}</p>
                    </div>
                    <div class="rounded-md bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800">
                        {{ $usedPorts }}/{{ $capacity }} portova
                    </div>
                </div>
            </div>
            <div class="grid gap-4 p-5 md:grid-cols-5">
                <div><div class="text-sm text-zinc-500">ODF</div><div class="text-xl font-semibold">{{ $project->odfs_count }}</div></div>
                <div><div class="text-sm text-zinc-500">Ormarici</div><div class="text-xl font-semibold">{{ $project->cabinets_count }}</div></div>
                <div><div class="text-sm text-zinc-500">Slobodno</div><div class="text-xl font-semibold">{{ $freePorts }}</div></div>
                <div><div class="text-sm text-zinc-500">Kuce</div><div class="text-xl font-semibold">{{ $project->houses_count }}</div></div>
                <div><div class="text-sm text-zinc-500">Materijal</div><div class="text-xl font-semibold">{{ number_format($projectMaterialCost, 2) }} KM</div></div>
            </div>
            <div class="border-t border-zinc-100 px-5 py-4 text-sm text-zinc-600">
                Trase: {{ number_format($project->routes->sum('duct_length_m')) }} m mikrocijevi,
                {{ number_format($project->routes->sum('fiber_length_m')) }} m optickog kabla.
                Preporucena rezerva za kabl: {{ number_format(ceil($project->routes->sum('fiber_length_m') * 1.1)) }} m.
            </div>
            <div class="grid gap-4 border-t border-zinc-100 p-5 md:grid-cols-2">
                <div><h3 class="font-semibold">Tehnicki opis</h3><p class="mt-2 text-sm text-zinc-600">Investitor: {{ $project->investor ?: '-' }}<br>{{ $project->description ?: 'FTTH projekat sa ODF tackama, ODO ormaricima, korisnicima i trasama.' }}</p></div>
                <div><h3 class="font-semibold">Specifikacija mreze</h3><div class="mt-2 text-sm text-zinc-600">Mikrocijev 14/10: {{ number_format($duct1410) }} m</div><div class="text-sm text-zinc-600">Mikrocijev 10/8: {{ number_format($duct108) }} m</div>@foreach ([4, 12, 24, 48] as $fibers)<div class="text-sm text-zinc-600">Opticki kabl {{ $fibers }} niti: {{ number_format($project->routes->where('fiber_count', $fibers)->sum('fiber_length_m')) }} m</div>@endforeach</div>
            </div>
            <div class="border-t border-zinc-100 p-5">
                <h3 class="font-semibold">Pregled trasa</h3>
                <div class="mt-2 overflow-x-auto"><table class="w-full text-left text-xs"><thead><tr><th>Trasa</th><th>Polaganje</th><th>Mikrocijev</th><th>Duzina</th><th>Kabl</th></tr></thead><tbody>@foreach($project->routes as $route)<tr><td>{{ $route->name }}</td><td>{{ $route->installation_type }}</td><td>{{ $route->microduct_type }} x {{ $route->microduct_count }}</td><td>{{ $route->duct_length_m }} m</td><td>{{ $route->fiber_count }} niti / {{ $route->fiber_length_m }} m</td></tr>@endforeach</tbody></table></div>
            </div>
        </article>
    @empty
        <div class="rounded-md border border-zinc-200 bg-white p-8 text-center text-zinc-500">Nema projekata za izvjestaj.</div>
    @endforelse
</section>
@endsection
