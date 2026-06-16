@extends('ftth.layout')
@section('title', 'Kuće')
@section('subtitle', 'Evidencija kuća i priključnih tačaka povezanih na projekat i ODO ormarić.')
@section('content')

<section class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div><div class="stat-label">Kuće ukupno</div><div class="stat-value">{{ $houseStats['total'] }}</div></div>
            <div class="stat-icon blue"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"/></svg></div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div><div class="stat-label">Povezane na ODO</div><div class="stat-value">{{ $houseStats['connected'] }}</div></div>
            <div class="stat-icon green"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg></div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div><div class="stat-label">Bez ormarića</div><div class="stat-value">{{ $houseStats['unassigned'] }}</div></div>
            <div class="stat-icon amber"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg></div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div><div class="stat-label">Raspoloživi ODO</div><div class="stat-value">{{ $houseStats['cabinets'] }}</div></div>
            <div class="stat-icon sky"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M4 3a2 2 0 100 4h12a2 2 0 100-4H4zM3 8h14v7a2 2 0 01-2 2H5a2 2 0 01-2-2V8zm5 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z"/></svg></div>
        </div>
    </article>
</section>

<div class="page-toolbar">
    <span class="page-toolbar-info">{{ $houses->total() }} kuća</span>
    <button class="btn-new" data-drawer-open="drawer-houses">
        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/></svg>
        Nova kuća
    </button>
</div>

@include('ftth.partials.table', [
    'rows' => $houses,
    'columns' => ['label' => 'Kuća', 'project.name' => 'Projekat', 'cabinet.name' => 'ODO', 'address' => 'Adresa', 'status' => 'Status'],
    'editRoute' => fn($id) => route('houses.update', $id),
    'deleteRoute' => fn($id) => route('houses.delete', $id),
    'editFields' => [
        'project_id' => ['label' => 'Projekat', 'type' => 'select', 'options' => $projects->pluck('name', 'id')->all()],
        'cabinet_id' => ['label' => 'ODO', 'type' => 'select', 'options' => ['' => 'Bez ormarića'] + $cabinets->pluck('name', 'id')->all()],
        'label' => 'Oznaka', 'address' => 'Adresa', 'latitude' => 'Latitude', 'longitude' => 'Longitude',
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['planned' => 'Planirana', 'connected' => 'Spojena', 'cancelled' => 'Otkazana']],
    ]
])

<div id="drawer-houses" class="app-drawer">
    <div class="app-drawer-backdrop"></div>
    <div class="app-drawer-panel">
        <div class="app-drawer-head">
            <div class="app-drawer-head-left">
                <div class="page-form-icon"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"/></svg></div>
                <h2>Nova kuća</h2>
            </div>
            <button type="button" class="app-drawer-close" data-drawer-close="drawer-houses"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg></button>
        </div>
        <form class="app-drawer-body" method="POST" action="{{ route('houses.store') }}">
            @csrf
            <label class="ftth-label">Projekat
                <select name="project_id" class="ftth-input" required>
                    <option value="">Odaberi projekat</option>
                    @foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach
                </select>
            </label>
            <label class="ftth-label">ODO ormarić
                <select name="cabinet_id" class="ftth-input">
                    <option value="">Bez ormarića</option>
                    @foreach($cabinets as $cabinet)<option value="{{ $cabinet->id }}" @disabled($cabinet->houses_count >= 12)>{{ $cabinet->name }} — {{ $cabinet->project->name }} ({{ $cabinet->houses_count }}/12)</option>@endforeach
                </select>
            </label>
            <label class="ftth-label">Oznaka kuće<input name="label" value="{{ old('label') }}" class="ftth-input" required></label>
            <label class="ftth-label">Adresa<input name="address" value="{{ old('address') }}" class="ftth-input"></label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="ftth-label">Latitude<input name="latitude" value="{{ old('latitude') }}" class="ftth-input" required></label>
                <label class="ftth-label">Longitude<input name="longitude" value="{{ old('longitude') }}" class="ftth-input" required></label>
            </div>
            <label class="ftth-label">Status
                <select name="status" class="ftth-input">
                    <option value="planned">Planirana</option>
                    <option value="connected">Spojena</option>
                    <option value="cancelled">Otkazana</option>
                </select>
            </label>
            <button class="btn-save">Sačuvaj kuću</button>
        </form>
    </div>
</div>
@if($errors->any())<script>document.getElementById('drawer-houses')?.classList.add('open');</script>@endif

@endsection
