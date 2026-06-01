@extends('ftth.layout')

@section('title', 'ODF lokacije')
@section('subtitle', 'Centralne opticke distribucione tacke iz kojih se napaja mreza.')

@section('content')
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
            <button class="rounded-md bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-700">Sacuvaj ODF</button>
        </div>
    </form>

    @include('ftth.partials.table', ['rows' => $odfs, 'columns' => ['name' => 'Naziv', 'project.name' => 'Projekat', 'address' => 'Adresa', 'port_count' => 'Portovi', 'fiber_capacity' => 'Vlakna'], 'deleteRoute' => fn($id) => route('odfs.delete', $id)])
</section>
@endsection
