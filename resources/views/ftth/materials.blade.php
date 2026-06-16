@extends('ftth.layout')
@section('title', 'Materijali')
@section('subtitle', 'Planirane i utrošene količine materijala po projektu.')
@section('content')

<section class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div>
                <div class="stat-label">Stavke materijala</div>
                <div class="stat-value">{{ $materialStats['total'] }}</div>
            </div>
            <div class="stat-icon slate">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6 2a2 2 0 00-2 2v12a2 2 0 002 2h8a2 2 0 002-2V7.414A2 2 0 0015.414 6L12 2.586A2 2 0 0010.586 2H6zm5 6a1 1 0 10-2 0v2H7a1 1 0 100 2h2v2a1 1 0 102 0v-2h2a1 1 0 100-2h-2V8z" clip-rule="evenodd"/></svg>
            </div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div>
                <div class="stat-label">Planirano</div>
                <div class="stat-value">{{ number_format($materialStats['planned_cost'], 2) }}<span class="text-sm font-normal text-slate-400"> KM</span></div>
            </div>
            <div class="stat-icon blue">
                <svg viewBox="0 0 20 20" fill="currentColor"><path d="M8.433 7.418c.155-.103.346-.196.567-.267v1.698a2.305 2.305 0 01-.567-.267C8.07 8.34 8 8.114 8 8c0-.114.07-.34.433-.582zM11 12.849v-1.698c.22.071.412.164.567.267.364.243.433.468.433.582 0 .114-.07.34-.433.582a2.305 2.305 0 01-.567.267z"/><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-13a1 1 0 10-2 0v.092a4.535 4.535 0 00-1.676.662C6.602 6.234 6 7.009 6 8c0 .99.602 1.765 1.324 2.246.48.32 1.054.545 1.676.662v1.941c-.391-.127-.68-.317-.843-.504a1 1 0 10-1.51 1.31c.562.649 1.413 1.076 2.353 1.253V15a1 1 0 102 0v-.092a4.535 4.535 0 001.676-.662C13.398 13.766 14 12.991 14 12c0-.99-.602-1.765-1.324-2.246A4.535 4.535 0 0011 9.092V7.151c.391.127.68.317.843.504a1 1 0 101.511-1.31c-.563-.649-1.413-1.076-2.354-1.253V5z" clip-rule="evenodd"/></svg>
            </div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div>
                <div class="stat-label">Utrošeno</div>
                <div class="stat-value">{{ number_format($materialStats['used_cost'], 2) }}<span class="text-sm font-normal text-slate-400"> KM</span></div>
            </div>
            <div class="stat-icon amber">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/></svg>
            </div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div>
                <div class="stat-label">Projekti</div>
                <div class="stat-value">{{ $materialStats['projects'] }}</div>
            </div>
            <div class="stat-icon green">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" clip-rule="evenodd"/></svg>
            </div>
        </div>
    </article>
</section>

<div class="info-banner green mb-6">
    <svg viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5 shrink-0 mt-0.5"><path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd"/></svg>
    <div>
        <p class="font-semibold text-emerald-900">Automatski obračun materijala</p>
        <p class="mt-0.5 text-sm text-emerald-800">Generiše mikrocijevi 14/10 i 10/8, optičke kablove, ODF, ODO i splittere.</p>
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach($projects as $project)
                <form method="POST" action="{{ route('materials.calculate', $project) }}">
                    @csrf
                    <button class="rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-800 transition-colors">{{ $project->name }}</button>
                </form>
            @endforeach
        </div>
    </div>
</div>

<section class="grid gap-6 xl:grid-cols-[420px_1fr]">
    <form method="POST" action="{{ route('materials.store') }}" class="page-form">
        @csrf
        <div class="page-form-header">
            <div class="page-form-icon">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6 2a2 2 0 00-2 2v12a2 2 0 002 2h8a2 2 0 002-2V7.414A2 2 0 0015.414 6L12 2.586A2 2 0 0010.586 2H6zm5 6a1 1 0 10-2 0v2H7a1 1 0 100 2h2v2a1 1 0 102 0v-2h2a1 1 0 100-2h-2V8z" clip-rule="evenodd"/></svg>
            </div>
            <h2>Novi materijal</h2>
        </div>
        <div class="page-form-body">
            <label class="ftth-label">Projekat
                <select name="project_id" class="ftth-input" required>
                    <option value="">Odaberi projekat</option>
                    @foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach
                </select>
            </label>
            <label class="ftth-label">Naziv materijala<input name="name" value="{{ old('name') }}" class="ftth-input" required></label>
            <label class="ftth-label">Jedinica mjere<input name="unit" value="{{ old('unit', 'kom') }}" class="ftth-input" required></label>
            <div class="grid gap-3 sm:grid-cols-3">
                <label class="ftth-label">Planirano<input type="number" step="0.01" name="planned_quantity" value="{{ old('planned_quantity', 0) }}" min="0" class="ftth-input" required></label>
                <label class="ftth-label">Utrošeno<input type="number" step="0.01" name="used_quantity" value="{{ old('used_quantity', 0) }}" min="0" class="ftth-input" required></label>
                <label class="ftth-label">Cijena KM<input type="number" step="0.01" name="unit_price" value="{{ old('unit_price', 0) }}" min="0" class="ftth-input" required></label>
            </div>
            <button class="btn-save">Sačuvaj materijal</button>
        </div>
    </form>

    @include('ftth.partials.table', [
        'rows' => $materials,
        'columns' => ['name' => 'Materijal', 'project.name' => 'Projekat', 'planned_quantity' => 'Plan', 'used_quantity' => 'Utrošeno', 'unit' => 'Jed.', 'unit_price' => 'KM'],
        'editRoute' => fn($id) => route('materials.update', $id),
        'deleteRoute' => fn($id) => route('materials.delete', $id),
        'editFields' => [
            'project_id' => ['label' => 'Projekat', 'type' => 'select', 'options' => $projects->pluck('name', 'id')->all()],
            'name' => 'Materijal', 'unit' => 'Jedinica',
            'planned_quantity' => ['label' => 'Planirano', 'type' => 'number'],
            'used_quantity' => ['label' => 'Utrošeno', 'type' => 'number'],
            'unit_price' => ['label' => 'Cijena KM', 'type' => 'number'],
        ]
    ])
</section>
@endsection
