@extends('ftth.layout')

@section('title', 'Izvještaji')
@section('subtitle', 'Pregled projekta, kapaciteta, trasa i materijala bez PDF/Excel izvoza za sada.')

@section('content')
<section class="mb-5 rounded-md border border-slate-200 bg-white p-4 shadow-sm">
    <label class="grid gap-1 text-sm sm:max-w-sm">
        <span>Izbor projekta</span>
        <select class="rounded-md border border-slate-300 px-3 py-2">
            <option>Svi projekti</option>
            @foreach($projects as $project)
                <option>{{ $project->name }}</option>
            @endforeach
        </select>
    </label>
</section>

<section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ([
        'Projekti' => $totals['projects'],
        'Kuće / priključci' => $totals['houses'],
        'Korisnici' => $totals['subscribers'],
        'Ormarići' => $totals['cabinets'],
        'Slobodni portovi' => $totals['free_ports'],
        'Mikrocijevi' => number_format($totals['duct']).' m',
        'Optički kabl' => number_format($totals['fiber']).' m',
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
            $insight = $projectInsights[$project->id] ?? ['validation' => [], 'materials' => []];
            $validation = collect($insight['validation']);
            $materials = $insight['materials'];
        @endphp
        <article class="rounded-md border border-zinc-200 bg-white shadow-sm">
            <div class="border-b border-zinc-200 px-5 py-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold">{{ $project->name }}</h2>
                        <p class="text-sm text-zinc-500">{{ $project->code }} / {{ $project->location }} / {{ $project->status }}</p>
                    </div>
                    <div class="rounded-md bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800">
                        {{ $usedPorts }}/{{ $capacity }} portova
                    </div>
                </div>
            </div>
            <div class="grid gap-4 p-5 md:grid-cols-5">
                <div><div class="text-sm text-zinc-500">ODF</div><div class="text-xl font-semibold">{{ $project->odfs_count }}</div></div>
                <div><div class="text-sm text-zinc-500">Ormarići</div><div class="text-xl font-semibold">{{ $project->cabinets_count }}</div></div>
                <div><div class="text-sm text-zinc-500">Slobodno</div><div class="text-xl font-semibold">{{ $freePorts }}</div></div>
                <div><div class="text-sm text-zinc-500">Kuće</div><div class="text-xl font-semibold">{{ $project->houses_count }}</div></div>
                <div><div class="text-sm text-zinc-500">Materijal</div><div class="text-xl font-semibold">{{ number_format($projectMaterialCost, 2) }} KM</div></div>
            </div>
            <div class="border-t border-zinc-100 px-5 py-4 text-sm text-zinc-600">
                Trase: {{ number_format($project->routes->sum('duct_length_m')) }} m mikrocijevi,
                {{ number_format($project->routes->sum('fiber_length_m')) }} m optičkog kabla.
                Preporučena rezerva za kabl: {{ number_format(ceil($project->routes->sum('fiber_length_m') * 1.1)) }} m.
            </div>
            <div class="grid gap-4 border-t border-zinc-100 p-5 md:grid-cols-2">
                <div>
                    <h3 class="font-semibold">Projektna validacija</h3>
                    <div class="mt-2 grid gap-2 text-sm">
                        @foreach($validation->take(5) as $item)
                            <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                <b class="uppercase text-xs {{ $item['level'] === 'error' ? 'text-red-700' : ($item['level'] === 'warning' ? 'text-amber-700' : 'text-slate-500') }}">{{ $item['level'] }}</b>
                                <div>{{ $item['message'] }}</div>
                                <div class="text-xs text-slate-500">{{ $item['recommendation'] }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div>
                    <h3 class="font-semibold">Materijalni obračun</h3>
                    <div class="mt-2 grid grid-cols-2 gap-2 text-sm text-zinc-600">
                        <div>Mikrocijev 14/10: <b>{{ number_format($materials['microduct_14_10_m'] ?? 0) }} m</b></div>
                        <div>Mikrocijev 10/8: <b>{{ number_format($materials['microduct_10_8_m'] ?? 0) }} m</b></div>
                        <div>Optika 4 niti: <b>{{ number_format($materials['fiber_4_m'] ?? 0) }} m</b></div>
                        <div>Optika 12 niti: <b>{{ number_format($materials['fiber_12_m'] ?? 0) }} m</b></div>
                        <div>Splitteri: <b>{{ number_format($materials['splitter_count'] ?? 0) }}</b></div>
                        <div>Neklasifikovane trase: <b>{{ number_format($materials['unclassified_routes'] ?? 0) }}</b></div>
                    </div>
                </div>
            </div>
            <div class="grid gap-4 border-t border-zinc-100 p-5 md:grid-cols-2">
                <div>
                    <h3 class="font-semibold">Tehnički opis</h3>
                    <p class="mt-2 text-sm text-zinc-600">Investitor: {{ $project->investor ?: '-' }}<br>{{ $project->description ?: 'FTTH projekat sa ODF tačkama, ODO ormarićima, korisnicima i trasama.' }}</p>
                </div>
                <div>
                    <h3 class="font-semibold">Specifikacija mreže</h3>
                    <div class="mt-2 text-sm text-zinc-600">Mikrocijev 14/10: {{ number_format($duct1410) }} m</div>
                    <div class="text-sm text-zinc-600">Mikrocijev 10/8: {{ number_format($duct108) }} m</div>
                    @foreach ([4, 12, 24, 48] as $fibers)
                        <div class="text-sm text-zinc-600">Optički kabl {{ $fibers }} niti: {{ number_format($project->routes->where('fiber_count', $fibers)->sum('fiber_length_m')) }} m</div>
                    @endforeach
                </div>
            </div>
            <div class="border-t border-zinc-100 p-5">
                <h3 class="font-semibold">Pregled trasa</h3>
                <div class="mt-2 overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead><tr><th>Trasa</th><th>Polaganje</th><th>Mikrocijev</th><th>Dužina</th><th>Kabl</th></tr></thead>
                        <tbody>
                            @forelse($project->routes as $route)
                                <tr><td>{{ $route->name }}</td><td>{{ $route->installation_type }}</td><td>{{ $route->microduct_type }} x {{ $route->microduct_count }}</td><td>{{ $route->duct_length_m }} m</td><td>{{ $route->fiber_count }} niti / {{ $route->fiber_length_m }} m</td></tr>
                            @empty
                                <tr><td colspan="5" class="py-4 text-center text-slate-500">Nema trasa za ovaj projekat.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </article>
    @empty
        @include('ftth.partials.empty-state', ['title' => 'Nema projekata', 'message' => 'Izvještaji će se prikazati kada dodate prvi projekat.'])
    @endforelse
</section>
@endsection
