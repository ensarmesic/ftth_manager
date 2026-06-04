@extends('ftth.layout')

@section('title', 'Opticke trase')
@section('subtitle', 'Duzine mikrocijevi, optickog kabla i veze ODF/ODO/kuce.')

@section('content')
<details class="mb-6 rounded-md border border-sky-200 bg-sky-50">
    <summary class="cursor-pointer list-none px-4 py-3 font-semibold text-sky-950">DXF import trase</summary>
    <form method="POST" action="{{ route('routes.dxf.import') }}" enctype="multipart/form-data" class="grid gap-3 border-t border-sky-200 p-4 md:grid-cols-3">
        @csrf
        <select name="project_id" class="rounded-md border border-sky-200 bg-white px-3 py-2 text-sm" required><option value="">Projekat</option>@foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach</select>
        <input type="file" name="dxf" accept=".dxf,text/plain" class="rounded-md border border-sky-200 bg-white px-3 py-2 text-sm" required>
        <button class="rounded-md bg-sky-700 px-4 py-2 text-sm font-semibold text-white">Uvezi LINE / POLYLINE</button>
    </form>
</details>

<section class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <article class="rounded-md border border-zinc-200 bg-white p-4 shadow-sm"><div class="text-sm text-zinc-500">Trase ukupno</div><div class="mt-2 text-2xl font-semibold">{{ number_format($routeStats['duct']) }} m</div></article>
    <article class="rounded-md border border-zinc-200 bg-white p-4 shadow-sm"><div class="text-sm text-zinc-500">Mikrocijev efektivno</div><div class="mt-2 text-2xl font-semibold">{{ number_format($routeStats['effective_microduct']) }} m</div><div class="text-xs text-zinc-500">sa 10%: {{ number_format($routeStats['microduct_with_reserve']) }} m</div></article>
    <article class="rounded-md border border-zinc-200 bg-white p-4 shadow-sm"><div class="text-sm text-zinc-500">Opticki kabl</div><div class="mt-2 text-2xl font-semibold">{{ number_format($routeStats['fiber']) }} m</div><div class="text-xs text-zinc-500">sa 10%: {{ number_format($routeStats['fiber_with_reserve']) }} m</div></article>
    <article class="rounded-md border border-zinc-200 bg-white p-4 shadow-sm"><div class="text-sm text-zinc-500">Ormarici / splitteri</div><div class="mt-2 text-2xl font-semibold">{{ $routeStats['planned_cabinets'] }} / {{ $routeStats['planned_splitters'] }}</div></article>
</section>

<section class="grid gap-6 xl:grid-cols-[420px_1fr]">
    <form method="POST" action="{{ route('routes.store') }}" class="rounded-md border border-zinc-200 bg-white p-5 shadow-sm">
        @csrf
        <h2 class="mb-4 font-semibold">Nova trasa</h2>
        <div class="grid gap-4">
            <label class="grid gap-1 text-sm"><span>Projekat</span><select name="project_id" class="rounded-md border border-zinc-300 px-3 py-2" required><option value="">Odaberi projekat</option>@foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach</select></label>
            <label class="grid gap-1 text-sm"><span>Pocetak trase</span><select name="from_type" class="rounded-md border border-zinc-300 px-3 py-2"><option value="">Bez pocetne veze</option><option value="odf">ODF</option><option value="cabinet">ODO/FTTH ormaric</option></select></label>
            <label class="grid gap-1 text-sm"><span>Pocetni ODF</span><select name="odf_id" class="rounded-md border border-zinc-300 px-3 py-2"><option value="">Bez ODF veze</option>@foreach($odfs as $odf)<option value="{{ $odf->id }}">{{ $odf->name }}</option>@endforeach</select></label>
            <label class="grid gap-1 text-sm"><span>Pocetni ODO</span><select name="from_id" class="rounded-md border border-zinc-300 px-3 py-2"><option value="">Bez ODO pocetka</option>@foreach($cabinets as $cabinet)<option value="{{ $cabinet->id }}">{{ $cabinet->name }}</option>@endforeach</select></label>
            <label class="grid gap-1 text-sm"><span>Krajnji ormaric</span><select name="cabinet_id" class="rounded-md border border-zinc-300 px-3 py-2"><option value="">Bez ormarica</option>@foreach($cabinets as $cabinet)<option value="{{ $cabinet->id }}">{{ $cabinet->name }}</option>@endforeach</select></label>
            <label class="grid gap-1 text-sm"><span>Naziv trase</span><input name="name" value="{{ old('name') }}" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            <label class="grid gap-1 text-sm"><span>Tip trase</span><select name="route_type" class="rounded-md border border-zinc-300 px-3 py-2"><option value="feeder">Feeder</option><option value="distribution">Distribucija</option><option value="drop">Drop</option></select></label>
            <label class="grid gap-1 text-sm"><span>Polaganje</span><select name="installation_type" class="rounded-md border border-zinc-300 px-3 py-2"><option value="underground">Podzemna</option><option value="aerial">Zracna</option></select></label>
            <div class="grid gap-3 sm:grid-cols-3">
                <label class="grid gap-1 text-sm"><span>Mikrocijev m</span><input type="number" name="duct_length_m" value="{{ old('duct_length_m', 0) }}" min="0" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
                <label class="grid gap-1 text-sm"><span>Kabl m</span><input type="number" name="fiber_length_m" value="{{ old('fiber_length_m', 0) }}" min="0" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
                <label class="grid gap-1 text-sm"><span>Broj cijevi</span><input type="number" name="microduct_count" value="{{ old('microduct_count', 1) }}" min="1" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="grid gap-1 text-sm"><span>Profil mikrocijevi</span><select name="microduct_type" class="rounded-md border border-zinc-300 px-3 py-2"><option value="14/10">14/10</option><option value="10/8">10/8</option></select></label>
                <label class="grid gap-1 text-sm"><span>Broj niti kabla</span><select name="fiber_count" class="rounded-md border border-zinc-300 px-3 py-2"><option value="4">4</option><option value="12" selected>12</option><option value="24">24</option><option value="48">48</option></select></label>
            </div>
            <label class="grid gap-1 text-sm"><span>Status</span><select name="status" class="rounded-md border border-zinc-300 px-3 py-2"><option value="planned">Planirano</option><option value="in_progress">U toku</option><option value="built">Izgradjeno</option></select></label>
            <button class="rounded-md bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-700">Sacuvaj trasu</button>
        </div>
    </form>

    @include('ftth.partials.table', ['rows' => $routes, 'columns' => ['name' => 'Trasa', 'project.name' => 'Projekat', 'from_label' => 'Od', 'cabinet.name' => 'Do ODO', 'installation_type' => 'Polaganje', 'microduct_type' => 'Mikrocijev', 'duct_length_m' => 'Duzina m', 'fiber_count' => 'Niti', 'status' => 'Status'], 'editRoute' => fn($id) => route('routes.update', $id), 'deleteRoute' => fn($id) => route('routes.delete', $id), 'editFields' => [
        'name' => 'Trasa',
        'route_type' => ['label' => 'Tip', 'type' => 'select', 'options' => ['feeder' => 'Feeder', 'distribution' => 'Distribucija', 'drop' => 'Drop']],
        'from_type' => ['label' => 'Pocetak', 'type' => 'select', 'options' => ['' => 'Bez pocetka', 'odf' => 'ODF', 'cabinet' => 'ODO/FTTH']],
        'odf_id' => ['label' => 'Pocetni ODF', 'type' => 'select', 'options' => ['' => 'Bez ODF veze'] + $odfs->pluck('name', 'id')->all()],
        'from_id' => ['label' => 'Pocetni ODO', 'type' => 'select', 'options' => ['' => 'Bez ODO pocetka'] + $cabinets->pluck('name', 'id')->all()],
        'cabinet_id' => ['label' => 'Do ODO', 'type' => 'select', 'options' => ['' => 'Bez ormarica'] + $cabinets->pluck('name', 'id')->all()],
        'microduct_type' => ['label' => 'Mikrocijev', 'type' => 'select', 'options' => ['14/10' => '14/10', '10/8' => '10/8']],
        'fiber_count' => ['label' => 'Niti', 'type' => 'select', 'options' => [4 => '4', 12 => '12', 24 => '24', 48 => '48']],
        'note' => 'Napomena',
    ]])
</section>
@endsection
