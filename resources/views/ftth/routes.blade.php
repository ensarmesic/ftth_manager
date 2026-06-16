@extends('ftth.layout')
@section('title', 'Optičke trase')
@section('subtitle', 'Dužine mikrocijevi, optičkog kabla i veze ODF/ODO/kuće.')
@section('content')

<details class="mb-6 page-form overflow-hidden" style="padding:0">
    <summary class="cursor-pointer list-none px-5 py-4 font-semibold text-slate-800 flex items-center gap-3">
        <div class="page-form-icon" style="width:28px;height:28px;border-radius:7px;">
            <svg viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM6.293 6.707a1 1 0 010-1.414l3-3a1 1 0 011.414 0l3 3a1 1 0 01-1.414 1.414L11 5.414V13a1 1 0 11-2 0V5.414L7.707 6.707a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
        </div>
        DXF import trase
    </summary>
    <form method="POST" action="{{ route('routes.dxf.import') }}" enctype="multipart/form-data" class="grid gap-3 border-t border-slate-100 px-5 py-4 md:grid-cols-3">
        @csrf
        <select name="project_id" class="ftth-input" required>
            <option value="">Odaberi projekat</option>
            @foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach
        </select>
        <input type="file" name="dxf" accept=".dxf,text/plain" class="ftth-input" required>
        <button class="btn-save">Uvezi LINE / POLYLINE</button>
    </form>
</details>

<section class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div>
                <div class="stat-label">Glavni rov fizički</div>
                <div class="stat-value">{{ number_format($routeStats['duct']) }}<span class="text-sm font-normal text-slate-400"> m</span></div>
            </div>
            <div class="stat-icon blue">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.395 2.553a1 1 0 00-1.45-.385c-.345.23-.614.558-.822.88-.214.33-.403.713-.57 1.116-.334.804-.614 1.768-.84 2.734a31.365 31.365 0 00-.613 3.58 2.64 2.64 0 01-.945-1.067c-.328-.68-.398-1.534-.398-2.654A1 1 0 005.05 6.05 6.981 6.981 0 003 11a7 7 0 1011.95-4.95c-.592-.591-.98-.985-1.348-1.467-.363-.476-.724-1.063-1.207-2.03zM12.12 15.12A3 3 0 017 13s.879.5 2.5.5c0-1 .5-4 1.25-4.5.5 1 .786 1.293 1.371 1.879A2.99 2.99 0 0113 13a2.99 2.99 0 01-.879 2.121z" clip-rule="evenodd"/></svg>
            </div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div>
                <div class="stat-label">Mikrocijev efektivno</div>
                <div class="stat-value">{{ number_format($routeStats['effective_microduct']) }}<span class="text-sm font-normal text-slate-400"> m</span></div>
                <div class="stat-sub">sa 10%: {{ number_format($routeStats['microduct_with_reserve']) }} m</div>
            </div>
            <div class="stat-icon sky">
                <svg viewBox="0 0 20 20" fill="currentColor"><path d="M2 10a8 8 0 018-8v8h8a8 8 0 11-16 0z"/><path d="M12 2.252A8.014 8.014 0 0117.748 8H12V2.252z"/></svg>
            </div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div>
                <div class="stat-label">Optički kabl</div>
                <div class="stat-value">{{ number_format($routeStats['fiber']) }}<span class="text-sm font-normal text-slate-400"> m</span></div>
                <div class="stat-sub">sa 10%: {{ number_format($routeStats['fiber_with_reserve']) }} m</div>
            </div>
            <div class="stat-icon teal">
                <svg viewBox="0 0 20 20" fill="currentColor"><path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zM8 7a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zM14 4a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z"/></svg>
            </div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div>
                <div class="stat-label">Ormarići / Splitteri</div>
                <div class="stat-value">{{ $routeStats['planned_cabinets'] }}<span class="text-slate-300 mx-1">/</span>{{ $routeStats['planned_splitters'] }}</div>
            </div>
            <div class="stat-icon green">
                <svg viewBox="0 0 20 20" fill="currentColor"><path d="M4 3a2 2 0 100 4h12a2 2 0 100-4H4zM3 8h14v7a2 2 0 01-2 2H5a2 2 0 01-2-2V8zm5 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z"/></svg>
            </div>
        </div>
    </article>
</section>

<section class="grid gap-6 xl:grid-cols-[420px_1fr]">
    <form method="POST" action="{{ route('routes.store') }}" class="page-form">
        @csrf
        <div class="page-form-header">
            <div class="page-form-icon">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.395 2.553a1 1 0 00-1.45-.385c-.345.23-.614.558-.822.88-.214.33-.403.713-.57 1.116-.334.804-.614 1.768-.84 2.734a31.365 31.365 0 00-.613 3.58 2.64 2.64 0 01-.945-1.067c-.328-.68-.398-1.534-.398-2.654A1 1 0 005.05 6.05 6.981 6.981 0 003 11a7 7 0 1011.95-4.95c-.592-.591-.98-.985-1.348-1.467-.363-.476-.724-1.063-1.207-2.03zM12.12 15.12A3 3 0 017 13s.879.5 2.5.5c0-1 .5-4 1.25-4.5.5 1 .786 1.293 1.371 1.879A2.99 2.99 0 0113 13a2.99 2.99 0 01-.879 2.121z" clip-rule="evenodd"/></svg>
            </div>
            <h2>Nova trasa</h2>
        </div>
        <div class="page-form-body">
            <label class="ftth-label">Projekat
                <select name="project_id" class="ftth-input" required>
                    <option value="">Odaberi projekat</option>
                    @foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach
                </select>
            </label>
            <label class="ftth-label">Početak trase
                <select name="from_type" class="ftth-input">
                    <option value="">Bez početne veze</option>
                    <option value="odf">ODF</option>
                    <option value="cabinet">ODO/FTTH ormarić</option>
                </select>
            </label>
            <label class="ftth-label">Početni ODF
                <select name="odf_id" class="ftth-input">
                    <option value="">Bez ODF veze</option>
                    @foreach($odfs as $odf)<option value="{{ $odf->id }}">{{ $odf->name }}</option>@endforeach
                </select>
            </label>
            <label class="ftth-label">Početni ODO
                <select name="from_id" class="ftth-input">
                    <option value="">Bez ODO početka</option>
                    @foreach($cabinets as $cabinet)<option value="{{ $cabinet->id }}">{{ $cabinet->name }}</option>@endforeach
                </select>
            </label>
            <label class="ftth-label">Krajnji ormarić
                <select name="cabinet_id" class="ftth-input">
                    <option value="">Bez ormarića</option>
                    @foreach($cabinets as $cabinet)<option value="{{ $cabinet->id }}">{{ $cabinet->name }}</option>@endforeach
                </select>
            </label>
            <label class="ftth-label">Naziv trase<input name="name" value="{{ old('name') }}" class="ftth-input" required></label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="ftth-label">Tip trase
                    <select name="route_type" class="ftth-input">
                        <option value="trench">Glavni rov</option>
                        <option value="feeder">Feeder</option>
                        <option value="distribution">Distribucija</option>
                        <option value="drop">Drop</option>
                    </select>
                </label>
                <label class="ftth-label">Polaganje
                    <select name="installation_type" class="ftth-input">
                        <option value="underground">Podzemna</option>
                        <option value="aerial">Zračna</option>
                    </select>
                </label>
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
                <label class="ftth-label">Mikrocijev m<input type="number" name="duct_length_m" value="{{ old('duct_length_m', 0) }}" min="0" class="ftth-input" required></label>
                <label class="ftth-label">Kabl m<input type="number" name="fiber_length_m" value="{{ old('fiber_length_m', 0) }}" min="0" class="ftth-input" required></label>
                <label class="ftth-label">Br. cijevi<input type="number" name="microduct_count" value="{{ old('microduct_count', 1) }}" min="1" class="ftth-input" required></label>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="ftth-label">Profil mikrocijevi
                    <select name="microduct_type" class="ftth-input">
                        <option value="14/10">14/10</option>
                        <option value="10/8">10/8</option>
                    </select>
                </label>
                <label class="ftth-label">Broj niti kabla
                    <select name="fiber_count" class="ftth-input">
                        <option value="4">4</option>
                        <option value="12" selected>12</option>
                        <option value="24">24</option>
                        <option value="48">48</option>
                    </select>
                </label>
            </div>
            <label class="ftth-label">Status
                <select name="status" class="ftth-input">
                    <option value="planned">Planirano</option>
                    <option value="in_progress">U toku</option>
                    <option value="built">Izgrađeno</option>
                </select>
            </label>
            <button class="btn-save">Sačuvaj trasu</button>
        </div>
    </form>

    @include('ftth.partials.table', [
        'rows' => $routes,
        'columns' => ['name' => 'Trasa', 'project.name' => 'Projekat', 'from_label' => 'Od', 'cabinet.name' => 'Do ODO', 'route_type' => 'Tip', 'installation_type' => 'Polaganje', 'microduct_type' => 'Mikrocijev', 'duct_length_m' => 'Dužina m', 'fiber_count' => 'Niti', 'status' => 'Status'],
        'editRoute' => fn($id) => route('routes.update', $id),
        'deleteRoute' => fn($id) => route('routes.delete', $id),
        'editFields' => [
            'name' => 'Trasa',
            'route_type' => ['label' => 'Tip', 'type' => 'select', 'options' => ['trench' => 'Glavni rov', 'feeder' => 'Feeder', 'distribution' => 'Distribucija', 'drop' => 'Drop']],
            'from_type' => ['label' => 'Početak', 'type' => 'select', 'options' => ['' => 'Bez početka', 'odf' => 'ODF', 'cabinet' => 'ODO/FTTH']],
            'odf_id' => ['label' => 'Početni ODF', 'type' => 'select', 'options' => ['' => 'Bez ODF veze'] + $odfs->pluck('name', 'id')->all()],
            'from_id' => ['label' => 'Početni ODO', 'type' => 'select', 'options' => ['' => 'Bez ODO početka'] + $cabinets->pluck('name', 'id')->all()],
            'cabinet_id' => ['label' => 'Do ODO', 'type' => 'select', 'options' => ['' => 'Bez ormarića'] + $cabinets->pluck('name', 'id')->all()],
            'microduct_type' => ['label' => 'Mikrocijev', 'type' => 'select', 'options' => ['14/10' => '14/10', '10/8' => '10/8']],
            'fiber_count' => ['label' => 'Niti', 'type' => 'select', 'options' => [4 => '4', 12 => '12', 24 => '24', 48 => '48']],
            'note' => 'Napomena',
        ]
    ])
</section>
@endsection
