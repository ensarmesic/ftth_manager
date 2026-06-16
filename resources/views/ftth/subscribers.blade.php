@extends('ftth.layout')
@section('title', 'Korisnici')
@section('subtitle', 'Evidencija korisnika, priključka, splittera i porta u ormariću.')
@section('content')

<section class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div><div class="stat-label">Korisnici</div><div class="stat-value">{{ $subscriberStats['total'] }}</div></div>
            <div class="stat-icon blue"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/></svg></div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div><div class="stat-label">U servisu</div><div class="stat-value">{{ $subscriberStats['in_service'] }}</div></div>
            <div class="stat-icon green"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg></div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div><div class="stat-label">Planirani</div><div class="stat-value">{{ $subscriberStats['planned'] }}</div></div>
            <div class="stat-icon amber"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg></div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div><div class="stat-label">Ormarići</div><div class="stat-value">{{ $subscriberStats['cabinets'] }}</div></div>
            <div class="stat-icon sky"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M4 3a2 2 0 100 4h12a2 2 0 100-4H4zM3 8h14v7a2 2 0 01-2 2H5a2 2 0 01-2-2V8zm5 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z"/></svg></div>
        </div>
    </article>
</section>

<div class="page-toolbar">
    <span class="page-toolbar-info">{{ $subscribers->total() }} korisnika</span>
    <button class="btn-new" data-drawer-open="drawer-subscribers">
        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/></svg>
        Novi korisnik
    </button>
</div>

@include('ftth.partials.table', [
    'rows' => $subscribers,
    'columns' => ['name' => 'Korisnik', 'project.name' => 'Projekat', 'cabinet.name' => 'Ormarić', 'address' => 'Adresa', 'service_status' => 'Status'],
    'editRoute' => fn($id) => route('subscribers.update', $id),
    'deleteRoute' => fn($id) => route('subscribers.delete', $id),
    'editFields' => [
        'project_id' => ['label' => 'Projekat', 'type' => 'select', 'options' => $projects->pluck('name', 'id')->all()],
        'cabinet_id' => ['label' => 'Ormarić', 'type' => 'select', 'options' => ['' => 'Bez ormarića'] + $cabinets->pluck('name', 'id')->all()],
        'name' => 'Korisnik', 'address' => 'Adresa', 'latitude' => 'Latitude', 'longitude' => 'Longitude', 'phone' => 'Telefon',
        'splitter_no' => ['label' => 'Splitter', 'type' => 'number'], 'port_no' => ['label' => 'Port', 'type' => 'number'],
        'service_status' => ['label' => 'Status', 'type' => 'select', 'options' => ['planned' => 'Planiran', 'connected' => 'Spojen', 'in_service' => 'U servisu', 'cancelled' => 'Otkazan']],
    ]
])

<div id="drawer-subscribers" class="app-drawer">
    <div class="app-drawer-backdrop"></div>
    <div class="app-drawer-panel">
        <div class="app-drawer-head">
            <div class="app-drawer-head-left">
                <div class="page-form-icon"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M8 9a3 3 0 100-6 3 3 0 000 6zM8 11a6 6 0 016 6H2a6 6 0 016-6zM16 7a1 1 0 10-2 0v1h-1a1 1 0 100 2h1v1a1 1 0 102 0v-1h1a1 1 0 100-2h-1V7z"/></svg></div>
                <h2>Novi korisnik</h2>
            </div>
            <button type="button" class="app-drawer-close" data-drawer-close="drawer-subscribers"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg></button>
        </div>
        <form class="app-drawer-body" method="POST" action="{{ route('subscribers.store') }}">
            @csrf
            <label class="ftth-label">Projekat
                <select name="project_id" class="ftth-input" required>
                    <option value="">Odaberi projekat</option>
                    @foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach
                </select>
            </label>
            <label class="ftth-label">Ormarić
                <select name="cabinet_id" id="cabinet-select" class="ftth-input">
                    <option value="">Bez ormarića</option>
                    @foreach($cabinets as $cabinet)
                        <option value="{{ $cabinet->id }}" data-capacity="{{ $cabinet->capacity }}" data-used="{{ $cabinet->subscribers_count }}">
                            {{ $cabinet->name }} ({{ $cabinet->subscribers_count }}/{{ $cabinet->capacity }})
                        </option>
                    @endforeach
                </select>
            </label>
            <div id="capacity-warning" class="hidden info-banner red">
                <svg viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4 shrink-0 mt-0.5"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                <p class="font-medium">Odabrani ormarić je popunjen!</p>
            </div>
            <label class="ftth-label">Ime korisnika<input name="name" value="{{ old('name') }}" class="ftth-input" required></label>
            <label class="ftth-label">Adresa<input name="address" value="{{ old('address') }}" class="ftth-input" required></label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="ftth-label">Latitude<input name="latitude" value="{{ old('latitude') }}" class="ftth-input"></label>
                <label class="ftth-label">Longitude<input name="longitude" value="{{ old('longitude') }}" class="ftth-input"></label>
            </div>
            <label class="ftth-label">Telefon<input name="phone" value="{{ old('phone') }}" class="ftth-input"></label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="ftth-label">Splitter br.<input type="number" name="splitter_no" value="{{ old('splitter_no') }}" min="1" max="3" class="ftth-input"></label>
                <label class="ftth-label">Port br.<input type="number" name="port_no" value="{{ old('port_no') }}" min="1" max="4" class="ftth-input"></label>
            </div>
            <label class="ftth-label">Status usluge
                <select name="service_status" class="ftth-input">
                    <option value="planned">Planiran</option>
                    <option value="connected">Spojen</option>
                    <option value="in_service">U servisu</option>
                    <option value="cancelled">Otkazan</option>
                </select>
            </label>
            <button type="submit" id="submit-btn" class="btn-save">Sačuvaj korisnika</button>
        </form>
    </div>
</div>
@if($errors->any())<script>document.getElementById('drawer-subscribers')?.classList.add('open');</script>@endif

<script>
(function () {
    const sel = document.getElementById('cabinet-select');
    const warn = document.getElementById('capacity-warning');
    const btn = document.getElementById('submit-btn');
    function check() {
        const opt = sel.options[sel.selectedIndex];
        const full = parseInt(opt.dataset.capacity || 0) > 0 && parseInt(opt.dataset.used || 0) >= parseInt(opt.dataset.capacity || 0);
        warn.classList.toggle('hidden', !full);
        btn.disabled = full;
    }
    sel.addEventListener('change', check);
    check();
})();
</script>
@endsection
