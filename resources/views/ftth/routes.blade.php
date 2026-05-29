@extends('ftth.layout')

@section('title', 'Opticke trase')
@section('subtitle', 'Duzine mikrocijevi, optickog kabla i status izgradnje.')

@section('content')
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
            <label class="grid gap-1 text-sm"><span>Pocetni ODF</span><select name="odf_id" class="rounded-md border border-zinc-300 px-3 py-2"><option value="">Bez ODF veze</option>@foreach($odfs as $odf)<option value="{{ $odf->id }}">{{ $odf->name }}</option>@endforeach</select></label>
            <label class="grid gap-1 text-sm"><span>Krajnji ormaric</span><select name="cabinet_id" class="rounded-md border border-zinc-300 px-3 py-2"><option value="">Bez ormarica</option>@foreach($cabinets as $cabinet)<option value="{{ $cabinet->id }}">{{ $cabinet->name }}</option>@endforeach</select></label>
            <label class="grid gap-1 text-sm"><span>Naziv trase</span><input name="name" value="{{ old('name') }}" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            <label class="grid gap-1 text-sm"><span>Tip trase</span><select name="route_type" class="rounded-md border border-zinc-300 px-3 py-2"><option value="feeder">Feeder</option><option value="distribution">Distribucija</option><option value="drop">Drop</option></select></label>
            <div class="grid gap-3 sm:grid-cols-3">
                <label class="grid gap-1 text-sm"><span>Mikrocijev m</span><input type="number" name="duct_length_m" value="{{ old('duct_length_m', 0) }}" min="0" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
                <label class="grid gap-1 text-sm"><span>Kabl m</span><input type="number" name="fiber_length_m" value="{{ old('fiber_length_m', 0) }}" min="0" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
                <label class="grid gap-1 text-sm"><span>Broj cijevi</span><input type="number" name="microduct_count" value="{{ old('microduct_count', 1) }}" min="1" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            </div>
            <label class="grid gap-1 text-sm"><span>Status</span><select name="status" class="rounded-md border border-zinc-300 px-3 py-2"><option value="planned">Planirano</option><option value="in_progress">U toku</option><option value="built">Izgradjeno</option></select></label>
            <button class="rounded-md bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-700">Sacuvaj trasu</button>
        </div>
    </form>

    @include('ftth.partials.table', ['rows' => $routes, 'columns' => ['name' => 'Trasa', 'project.name' => 'Projekat', 'odf.name' => 'ODF', 'cabinet.name' => 'Ormaric', 'route_type' => 'Tip', 'duct_length_m' => 'Mikrocijev m', 'fiber_length_m' => 'Kabl m', 'status' => 'Status']])
</section>
@endsection
