@extends('ftth.layout')

@section('title', 'ODF lokacije')
@section('subtitle', 'Centralne optičke distribucione tačke iz kojih se napaja mreža.')

@section('content')
<section class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-sm text-slate-500">ODF lokacije</div>
        <div class="mt-2 text-2xl font-semibold">{{ $odfStats['total'] }}</div>
    </article>
    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-sm text-slate-500">Ukupno portova</div>
        <div class="mt-2 text-2xl font-semibold">{{ $odfStats['ports'] }}</div>
    </article>
    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-sm text-slate-500">Kapacitet vlakana</div>
        <div class="mt-2 text-2xl font-semibold">{{ $odfStats['fibers'] }}</div>
    </article>
    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-sm text-slate-500">Projekti</div>
        <div class="mt-2 text-2xl font-semibold">{{ $odfStats['projects'] }}</div>
    </article>
</section>

<section class="grid gap-6 xl:grid-cols-[420px_1fr]">
    <form method="POST" action="{{ route('odfs.store') }}" class="rounded-md border border-zinc-200 bg-white p-5 shadow-sm">
        @csrf
        <h2 class="mb-4 font-semibold">Nova ODF lokacija</h2>
        <div class="grid gap-4">
            <label class="grid gap-1 text-sm"><span>Projekat</span><select name="project_id" class="rounded-md border border-zinc-300 px-3 py-2" required><option value="">Odaberi projekat</option>@foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach</select></label>
            <label class="grid gap-1 text-sm"><span>Naziv ODF-a</span><input name="name" value="{{ old('name') }}" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            <label class="grid gap-1 text-sm"><span>Adresa</span><input name="address" value="{{ old('address') }}" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            <label class="grid gap-1 text-sm"><span>Kapacitet vlakana</span><input type="number" name="fiber_capacity" value="{{ old('fiber_capacity', 144) }}" min="1" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            <label class="grid gap-1 text-sm"><span>Broj portova</span><input type="number" name="port_count" value="{{ old('port_count', 48) }}" min="1" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="grid gap-1 text-sm"><span>Latitude</span><input name="latitude" value="{{ old('latitude') }}" class="rounded-md border border-zinc-300 px-3 py-2"></label>
                <label class="grid gap-1 text-sm"><span>Longitude</span><input name="longitude" value="{{ old('longitude') }}" class="rounded-md border border-zinc-300 px-3 py-2"></label>
            </div>
            <label class="grid gap-1 text-sm"><span>Napomena</span><textarea name="notes" rows="2" class="rounded-md border border-zinc-300 px-3 py-2">{{ old('notes') }}</textarea></label>
            <button class="rounded-md bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-700">Sačuvaj ODF</button>
        </div>
    </form>

    @include('ftth.partials.table', ['rows' => $odfs, 'columns' => ['name' => 'Naziv', 'project.name' => 'Projekat', 'address' => 'Adresa', 'port_count' => 'Portovi', 'fiber_capacity' => 'Vlakna'], 'editRoute' => fn($id) => route('odfs.update', $id), 'deleteRoute' => fn($id) => route('odfs.delete', $id), 'editFields' => [
        'project_id' => ['label' => 'Projekat', 'type' => 'select', 'options' => $projects->pluck('name', 'id')->all()],
        'name' => 'Naziv', 'address' => 'Adresa', 'fiber_capacity' => ['label' => 'Vlakna', 'type' => 'number'],
        'port_count' => ['label' => 'Portovi', 'type' => 'number'], 'latitude' => 'Latitude', 'longitude' => 'Longitude', 'notes' => 'Napomena',
    ]])
</section>
@endsection
