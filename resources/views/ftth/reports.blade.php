@extends('ftth.layout')
@section('title', 'Izvještaji')
@section('subtitle', 'Pregled projekta, kapaciteta, trasa i materijala.')
@section('content')

<section class="mb-3 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ([
        ['Projekti',            $totals['projects'],                          'violet', '<path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>'],
        ['Kuće / Priključci',   $totals['houses'],                            'blue',   '<path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"/>'],
        ['Korisnici',           $totals['subscribers'],                       'green',  '<path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/>'],
        ['Ormarići',            $totals['cabinets'],                          'sky',    '<path d="M4 3a2 2 0 100 4h12a2 2 0 100-4H4zM3 8h14v7a2 2 0 01-2 2H5a2 2 0 01-2-2V8zm5 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z"/>'],
        ['Slobodni portovi',    $totals['free_ports'],                        'amber',  '<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v2H7a1 1 0 100 2h2v2a1 1 0 102 0v-2h2a1 1 0 100-2h-2V7z" clip-rule="evenodd"/>'],
        ['Mikrocijevi',         number_format($totals['duct']).' m',          'teal',   '<path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zM8 7a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zM14 4a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z"/>'],
        ['Optički kabl',        number_format($totals['fiber']).' m',         'blue',   '<path fill-rule="evenodd" d="M12.395 2.553a1 1 0 00-1.45-.385c-.345.23-.614.558-.822.88-.214.33-.403.713-.57 1.116-.334.804-.614 1.768-.84 2.734a31.365 31.365 0 00-.613 3.58 2.64 2.64 0 01-.945-1.067c-.328-.68-.398-1.534-.398-2.654A1 1 0 005.05 6.05 6.981 6.981 0 003 11a7 7 0 1011.95-4.95c-.592-.591-.98-.985-1.348-1.467-.363-.476-.724-1.063-1.207-2.03z" clip-rule="evenodd"/>'],
        ['Materijal',           number_format($totals['materials_cost'], 2).' KM', 'green', '<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-13a1 1 0 10-2 0v.092a4.535 4.535 0 00-1.676.662C6.602 6.234 6 7.009 6 8c0 .99.602 1.765 1.324 2.246.48.32 1.054.545 1.676.662v1.941c-.391-.127-.68-.317-.843-.504a1 1 0 10-1.51 1.31c.562.649 1.413 1.076 2.353 1.253V15a1 1 0 102 0v-.092a4.535 4.535 0 001.676-.662C13.398 13.766 14 12.991 14 12c0-.99-.602-1.765-1.324-2.246A4.535 4.535 0 0011 9.092V7.151c.391.127.68.317.843.504a1 1 0 101.511-1.31c-.563-.649-1.413-1.076-2.354-1.253V5z" clip-rule="evenodd"/>'],
    ] as [$label, $value, $color, $path])
        <article class="stat-card">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="stat-label">{{ $label }}</div>
                    <div class="stat-value">{{ $value }}</div>
                </div>
                <div class="stat-icon {{ $color }}">
                    <svg viewBox="0 0 20 20" fill="currentColor">{!! $path !!}</svg>
                </div>
            </div>
        </article>
    @endforeach
</section>

<section class="grid gap-5">
    @forelse ($projects as $project)
        @php
            $usedPorts = $project->cabinets->sum('subscribers_count');
            $capacity = $project->cabinets->sum(fn ($cabinet) => $cabinet->capacity);
            $freePorts = max($capacity - $usedPorts, 0);
            $projectMaterialCost = $project->materials->sum(fn ($material) => $material->planned_quantity * $material->unit_price);
            $materialRoutes = $project->routes->where('route_type', '!=', 'trench');
            $duct1410 = $materialRoutes->where('microduct_type', '14/10')->sum(fn ($route) => $route->duct_length_m * $route->microduct_count);
            $duct108 = $materialRoutes->where('microduct_type', '10/8')->sum(fn ($route) => $route->duct_length_m * $route->microduct_count);
            $physicalTrench = $project->routes->sum(fn ($route) => $route->trenchLengthForReport());
            $insight = $projectInsights[$project->id] ?? ['validation' => [], 'materials' => []];
            $validation = collect($insight['validation']);
            $materials = $insight['materials'];
        @endphp
        <article class="app-table-card overflow-hidden">
            <div class="tbl-card-header">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-base font-bold text-slate-900">{{ $project->name }}</h2>
                        <p class="text-xs text-slate-500 mt-0.5">{{ $project->code }} &bull; {{ $project->location }} &bull; {{ $project->status }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="{{ route('reports.project-appendix', $project) }}" class="btn-secondary">Prilog 3</a>
                        <span class="ftth-badge {{ $usedPorts >= $capacity && $capacity > 0 ? 'red' : 'green' }}">
                            <span class="ftth-badge-dot"></span>
                            {{ $usedPorts }}/{{ $capacity }} portova
                        </span>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-px border-b border-slate-100 bg-slate-100 sm:grid-cols-5">
                @foreach ([['ODF', $project->odfs_count], ['Ormarići', $project->cabinets_count], ['Slobodno', $freePorts], ['Kuće', $project->houses_count], ['Materijal', number_format($projectMaterialCost, 2).' KM']] as [$lbl, $val])
                    <div class="bg-white px-3 py-1.5">
                        <div class="text-[10px] font-bold uppercase tracking-wide text-slate-400">{{ $lbl }}</div>
                        <div class="mt-0.5 text-sm font-bold text-slate-900">{{ $val }}</div>
                    </div>
                @endforeach
            </div>

            <div class="grid gap-3 p-3 md:grid-cols-2">
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-1.5">Projektna validacija</h3>
                    <div class="grid gap-2">
                        @forelse($validation->take(5) as $item)
                            <div class="check-item {{ $item['level'] === 'error' ? 'error' : ($item['level'] === 'warning' ? 'warn' : 'ok') }}">
                                <div class="check-item-dot"></div>
                                <div>
                                    <div>{{ $item['message'] }}</div>
                                    @if(!empty($item['recommendation']))<div class="text-xs opacity-70 mt-0.5">{{ $item['recommendation'] }}</div>@endif
                                </div>
                            </div>
                        @empty
                            <div class="check-item ok"><div class="check-item-dot"></div>Nema grešaka.</div>
                        @endforelse
                    </div>
                </div>
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-1.5">Materijalni obračun</h3>
                    <div class="grid grid-cols-2 gap-2 text-sm">
                        @foreach ([
                            ['Mikrocijev 14/10', number_format($materials['microduct_14_10_m'] ?? 0).' m'],
                            ['Mikrocijev 10/8',  number_format($materials['microduct_10_8_m'] ?? 0).' m'],
                            ['Optika 4 niti',    number_format($materials['fiber_4_m'] ?? 0).' m'],
                            ['Optika 12 niti',   number_format($materials['fiber_12_m'] ?? 0).' m'],
                            ['Splitteri',        number_format($materials['splitter_count'] ?? 0)],
                            ['Neklas. trasa',    number_format($materials['unclassified_routes'] ?? 0)],
                        ] as [$k, $v])
                            <div class="flex justify-between rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-xs">
                                <span class="text-slate-500">{{ $k }}</span>
                                <b class="text-slate-900">{{ $v }}</b>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="border-t border-slate-100 p-3">
                <h3 class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-1.5">Stavke za Prilog 3</h3>
                <form method="POST" action="{{ route('reports.appendix-items.store', $project) }}" class="mb-2 grid gap-2 rounded-xl border border-slate-200 bg-slate-50 p-2 md:grid-cols-[1.3fr_.7fr_1.5fr_auto]">
                    @csrf
                    <select name="type" class="ftth-input text-xs" required>
                        <option value="manhole">Prolazni sahtovi</option>
                        <option value="boring_fi_130">Podbusivanje raketom FI 130</option>
                    </select>
                    <input type="number" step="0.01" min="0" name="quantity" class="ftth-input text-xs" placeholder="Količina" required>
                    <input name="note" class="ftth-input text-xs" placeholder="Napomena">
                    <button class="btn-save text-xs py-2">Dodaj</button>
                </form>
                <div class="grid gap-2 sm:grid-cols-2">
                    @forelse($project->appendixItems as $item)
                        <div class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                            <div>
                                <b class="text-slate-800">{{ $item->type === 'manhole' ? 'Prolazni sahtovi' : 'Podbusivanje raketom FI 130' }}</b>
                                <span class="text-slate-500"> — {{ number_format($item->quantity, 2, ',', '.') }} {{ $item->unit }}</span>
                                @if($item->note)<div class="text-xs text-slate-400">{{ $item->note }}</div>@endif
                            </div>
                            <form method="POST" action="{{ route('reports.appendix-items.delete', $item) }}" data-confirm-delete="Obrisati stavku Priloga 3?">
                                @csrf @method('DELETE')
                                <button class="tbl-btn tbl-btn-del">
                                    <svg viewBox="0 0 16 16" fill="currentColor" class="w-3 h-3"><path d="M6.5 1.75a.25.25 0 01.25-.25h2.5a.25.25 0 01.25.25V3h-3V1.75zm4.5 0V3h2.25a.75.75 0 010 1.5H2.75a.75.75 0 010-1.5H5V1.75C5 .784 5.784 0 6.75 0h2.5C10.216 0 11 .784 11 1.75z"/></svg>
                                    Obriši
                                </button>
                            </form>
                        </div>
                    @empty
                        <div class="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 text-xs text-slate-400">Nema dodatnih stavki za Prilog 3.</div>
                    @endforelse
                </div>
            </div>

            <div class="border-t border-slate-100 p-3">
                <h3 class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-1.5">Pregled trasa</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr>
                                <th>Trasa</th>
                                <th>Polaganje</th>
                                <th>Mikrocijev</th>
                                <th>Dužina</th>
                                <th>Kabl</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($project->routes as $route)
                                <tr>
                                    <td class="font-medium">{{ $route->name }}</td>
                                    <td>{{ $route->installation_type }}</td>
                                    <td>{{ $route->microduct_type }} × {{ $route->microduct_count }}</td>
                                    <td class="font-semibold">{{ $route->duct_length_m }} m</td>
                                    <td>{{ $route->fiber_count }} niti / {{ $route->fiber_length_m }} m</td>
                                </tr>
                            @empty
                                <tr><td colspan="5">Nema trasa za ovaj projekat.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-2 text-xs text-slate-400">
                    Glavni rov: <b class="text-slate-600">{{ number_format($physicalTrench) }} m</b> &bull;
                    Mikrocijevi: <b class="text-slate-600">{{ number_format($duct1410 + $duct108) }} m</b> &bull;
                    Optički kabl: <b class="text-slate-600">{{ number_format($project->routes->sum('fiber_length_m')) }} m</b> &bull;
                    Rezerva kabla (+10%): <b class="text-slate-600">{{ number_format(ceil($project->routes->sum('fiber_length_m') * 1.1)) }} m</b>
                </div>
            </div>
        </article>
    @empty
        @include('ftth.partials.empty-state', ['title' => 'Nema projekata', 'message' => 'Izvještaji će se prikazati kada dodate prvi projekat.'])
    @endforelse
</section>
@endsection
