@extends('ftth.layout')
@section('title', 'ODF lokacije')
@section('subtitle', 'Centralne optičke distribucione tačke iz kojih se napaja mreža.')
@section('content')

<section class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div>
                <div class="stat-label">ODF lokacije</div>
                <div class="stat-value">{{ $odfStats['total'] }}</div>
            </div>
            <div class="stat-icon violet">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/></svg>
            </div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div>
                <div class="stat-label">Ukupno portova</div>
                <div class="stat-value">{{ $odfStats['ports'] }}</div>
            </div>
            <div class="stat-icon blue">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 3a1 1 0 000 2v8a2 2 0 002 2h2.586l-1.293 1.293a1 1 0 101.414 1.414L10 15.414l2.293 2.293a1 1 0 001.414-1.414L12.414 15H15a2 2 0 002-2V5a1 1 0 100-2H3zm11 4a1 1 0 10-2 0v4a1 1 0 102 0V7zm-3 1a1 1 0 10-2 0v3a1 1 0 102 0V8zM8 9a1 1 0 00-2 0v2a1 1 0 102 0V9z" clip-rule="evenodd"/></svg>
            </div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div>
                <div class="stat-label">Kapacitet vlakana</div>
                <div class="stat-value">{{ $odfStats['fibers'] }}</div>
            </div>
            <div class="stat-icon teal">
                <svg viewBox="0 0 20 20" fill="currentColor"><path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zM8 7a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zM14 4a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z"/></svg>
            </div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div>
                <div class="stat-label">Projekti</div>
                <div class="stat-value">{{ $odfStats['projects'] }}</div>
            </div>
            <div class="stat-icon green">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" clip-rule="evenodd"/></svg>
            </div>
        </div>
    </article>
</section>

<section class="grid gap-6 xl:grid-cols-[420px_1fr]">
    <form method="POST" action="{{ route('odfs.store') }}" class="page-form">
        @csrf
        <div class="page-form-header">
            <div class="page-form-icon">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/></svg>
            </div>
            <h2>Nova ODF lokacija</h2>
        </div>
        <div class="page-form-body">
            <label class="ftth-label">Projekat
                <select name="project_id" class="ftth-input" required>
                    <option value="">Odaberi projekat</option>
                    @foreach($projects as $project)
                        <option value="{{ $project->id }}">{{ $project->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="ftth-label">Naziv ODF-a<input name="name" value="{{ old('name') }}" class="ftth-input" required></label>
            <label class="ftth-label">Adresa<input name="address" value="{{ old('address') }}" class="ftth-input" required></label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="ftth-label">Kapacitet vlakana<input type="number" name="fiber_capacity" value="{{ old('fiber_capacity', 144) }}" min="1" class="ftth-input" required></label>
                <label class="ftth-label">Broj portova<input type="number" name="port_count" value="{{ old('port_count', 48) }}" min="1" class="ftth-input" required></label>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="ftth-label">Latitude<input name="latitude" value="{{ old('latitude') }}" class="ftth-input"></label>
                <label class="ftth-label">Longitude<input name="longitude" value="{{ old('longitude') }}" class="ftth-input"></label>
            </div>
            <label class="ftth-label">Napomena<textarea name="notes" rows="2" class="ftth-input">{{ old('notes') }}</textarea></label>
            <button class="btn-save">Sačuvaj ODF</button>
        </div>
    </form>

    @include('ftth.partials.table', [
        'rows' => $odfs,
        'columns' => ['name' => 'Naziv', 'project.name' => 'Projekat', 'address' => 'Adresa', 'port_count' => 'Portovi', 'fiber_capacity' => 'Vlakna'],
        'editRoute' => fn($id) => route('odfs.update', $id),
        'deleteRoute' => fn($id) => route('odfs.delete', $id),
        'editFields' => [
            'project_id' => ['label' => 'Projekat', 'type' => 'select', 'options' => $projects->pluck('name', 'id')->all()],
            'name' => 'Naziv', 'address' => 'Adresa',
            'fiber_capacity' => ['label' => 'Vlakna', 'type' => 'number'],
            'port_count' => ['label' => 'Portovi', 'type' => 'number'],
            'latitude' => 'Latitude', 'longitude' => 'Longitude', 'notes' => 'Napomena',
        ]
    ])
</section>
@endsection
