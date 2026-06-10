@extends('ftth.layout')

@section('title', 'Kuće')
@section('subtitle', 'Evidencija kuća i priključnih tačaka povezanih na projekat i ODO ormarić.')

@section('content')
<section class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-sm text-slate-500">Kuce ukupno</div>
        <div class="mt-2 text-2xl font-semibold">{{ $houseStats['total'] }}</div>
    </article>
    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-sm text-slate-500">Povezane na ODO</div>
        <div class="mt-2 text-2xl font-semibold">{{ $houseStats['connected'] }}</div>
    </article>
    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-sm text-slate-500">Bez ormarica</div>
        <div class="mt-2 text-2xl font-semibold">{{ $houseStats['unassigned'] }}</div>
    </article>
    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-sm text-slate-500">Raspolozivi ODO</div>
        <div class="mt-2 text-2xl font-semibold">{{ $houseStats['cabinets'] }}</div>
    </article>
</section>

<section class="grid gap-6 xl:grid-cols-[420px_1fr]">
    <form method="POST" action="{{ route('houses.store') }}" class="rounded-md border border-zinc-200 bg-white p-5 shadow-sm">
        @csrf
        <h2 class="mb-4 font-semibold">Nova kuća</h2>
        <div class="grid gap-4">
            <label class="grid gap-1 text-sm"><span>Projekat</span><select name="project_id" class="rounded-md border border-zinc-300 px-3 py-2" required><option value="">Odaberi projekat</option>@foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach</select></label>
            <label class="grid gap-1 text-sm"><span>ODO ormarić</span><select name="cabinet_id" class="rounded-md border border-zinc-300 px-3 py-2"><option value="">Bez ormarića</option>@foreach($cabinets as $cabinet)<option value="{{ $cabinet->id }}" @disabled($cabinet->houses_count >= 12)>{{ $cabinet->name }} - {{ $cabinet->project->name }} ({{ $cabinet->houses_count }}/12)</option>@endforeach</select></label>
            <label class="grid gap-1 text-sm"><span>Oznaka kuće</span><input name="label" value="{{ old('label') }}" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            <label class="grid gap-1 text-sm"><span>Adresa</span><input name="address" value="{{ old('address') }}" class="rounded-md border border-zinc-300 px-3 py-2"></label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="grid gap-1 text-sm"><span>Latitude</span><input name="latitude" value="{{ old('latitude') }}" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
                <label class="grid gap-1 text-sm"><span>Longitude</span><input name="longitude" value="{{ old('longitude') }}" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            </div>
            <label class="grid gap-1 text-sm"><span>Status</span><select name="status" class="rounded-md border border-zinc-300 px-3 py-2"><option value="planned">Planirana</option><option value="connected">Spojena</option><option value="cancelled">Otkazana</option></select></label>
            <button class="rounded-md bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-700">Sačuvaj kuću</button>
        </div>
    </form>

    @include('ftth.partials.table', ['rows' => $houses, 'columns' => ['label' => 'Kuća', 'project.name' => 'Projekat', 'cabinet.name' => 'ODO', 'address' => 'Adresa', 'status' => 'Status'], 'editRoute' => fn($id) => route('houses.update', $id), 'deleteRoute' => fn($id) => route('houses.delete', $id), 'editFields' => [
        'project_id' => ['label' => 'Projekat', 'type' => 'select', 'options' => $projects->pluck('name', 'id')->all()],
        'cabinet_id' => ['label' => 'ODO', 'type' => 'select', 'options' => ['' => 'Bez ormarića'] + $cabinets->pluck('name', 'id')->all()],
        'label' => 'Oznaka', 'address' => 'Adresa', 'latitude' => 'Latitude', 'longitude' => 'Longitude',
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['planned' => 'Planirana', 'connected' => 'Spojena', 'cancelled' => 'Otkazana']],
    ]])
</section>
@endsection
