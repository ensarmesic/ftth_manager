@extends('ftth.layout')
@section('title', 'Projekti')
@section('subtitle', 'Kreiranje i praćenje FTTH projekata po lokaciji i statusu.')
@section('content')

<section class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div><div class="stat-label">Projekti ukupno</div><div class="stat-value">{{ $projectStats['total'] }}</div></div>
            <div class="stat-icon blue"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" clip-rule="evenodd"/></svg></div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div><div class="stat-label">Aktivni</div><div class="stat-value">{{ $projectStats['active'] }}</div></div>
            <div class="stat-icon green"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg></div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div><div class="stat-label">Planiranje</div><div class="stat-value">{{ $projectStats['planning'] }}</div></div>
            <div class="stat-icon amber"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg></div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div><div class="stat-label">Završeni</div><div class="stat-value">{{ $projectStats['completed'] }}</div></div>
            <div class="stat-icon violet"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg></div>
        </div>
    </article>
</section>

<div class="page-toolbar">
    <span class="page-toolbar-info">{{ $projects->total() }} projekata</span>
    <button class="btn-new" data-drawer-open="drawer-projects">
        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/></svg>
        Novi projekat
    </button>
</div>

@include('ftth.partials.table', ['rows' => $projects, 'columns' => ['name' => 'Naziv', 'code' => 'Šifra', 'location' => 'Lokacija', 'investor' => 'Investitor', 'status' => 'Status'], 'editRoute' => fn($id) => route('projects.update', $id), 'deleteRoute' => fn($id) => route('projects.delete', $id), 'editFields' => [
    'name' => 'Naziv', 'code' => 'Šifra', 'location' => 'Lokacija', 'investor' => 'Investitor',
    'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['planning' => 'Planiranje', 'active' => 'Aktivan', 'paused' => 'Pauziran', 'completed' => 'Završen']],
    'start_date' => ['label' => 'Početak', 'type' => 'date'], 'deadline' => ['label' => 'Rok', 'type' => 'date'], 'description' => 'Opis',
]])

<div id="drawer-projects" class="app-drawer">
    <div class="app-drawer-backdrop"></div>
    <div class="app-drawer-panel">
        <div class="app-drawer-head">
            <div class="app-drawer-head-left">
                <div class="page-form-icon"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" clip-rule="evenodd"/></svg></div>
                <h2>Novi projekat</h2>
            </div>
            <button type="button" class="app-drawer-close" data-drawer-close="drawer-projects"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg></button>
        </div>
        <form class="app-drawer-body" method="POST" action="{{ route('projects.store') }}">
            @csrf
            <label class="ftth-label">Naziv projekta<input name="name" value="{{ old('name') }}" class="ftth-input" required></label>
            <label class="ftth-label">Šifra projekta<input name="code" value="{{ old('code') }}" class="ftth-input" required></label>
            <label class="ftth-label">Lokacija<input name="location" value="{{ old('location') }}" class="ftth-input" required></label>
            <label class="ftth-label">Investitor<input name="investor" value="{{ old('investor') }}" class="ftth-input"></label>
            <label class="ftth-label">Status
                <select name="status" class="ftth-input">
                    <option value="planning">Planiranje</option>
                    <option value="active">Aktivan</option>
                    <option value="paused">Pauziran</option>
                    <option value="completed">Završen</option>
                </select>
            </label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="ftth-label">Početak<input type="date" name="start_date" value="{{ old('start_date') }}" class="ftth-input"></label>
                <label class="ftth-label">Rok<input type="date" name="deadline" value="{{ old('deadline') }}" class="ftth-input"></label>
            </div>
            <label class="ftth-label">Opis<textarea name="description" rows="3" class="ftth-input">{{ old('description') }}</textarea></label>
            <button class="btn-save">Sačuvaj projekat</button>
        </form>
    </div>
</div>
@if($errors->any())<script>document.getElementById('drawer-projects')?.classList.add('open');</script>@endif

@endsection
