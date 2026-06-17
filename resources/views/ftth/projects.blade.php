@extends('ftth.layout')
@section('title', 'Projekti')
@section('subtitle', 'Kreiranje i praćenje FTTH projekata po lokaciji i statusu.')
@section('content')

<section class="mb-3 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
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

<div class="app-table-card">
    <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead>
                <tr>
                    <th>Naziv</th>
                    <th>Šifra</th>
                    <th>Lokacija</th>
                    <th>Investitor</th>
                    <th>Status</th>
                    <th>Akcije</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($projects as $project)
                <tr>
                    <td>{{ $project->name }}</td>
                    <td>{{ $project->code }}</td>
                    <td>{{ $project->location ?? '-' }}</td>
                    <td>{{ $project->investor ?? '-' }}</td>
                    <td>@include('ftth.partials.badge', ['value' => $project->status])</td>
                    <td>
                        <div class="flex items-center gap-1.5 flex-wrap">
                            {{-- Uredi --}}
                            <details class="relative inline-block">
                                <summary class="tbl-btn tbl-btn-edit list-none cursor-pointer">
                                    <svg viewBox="0 0 16 16" fill="currentColor" class="w-3 h-3"><path d="M11.013 1.427a1.75 1.75 0 012.474 0l1.086 1.086a1.75 1.75 0 010 2.474l-8.61 8.61c-.21.21-.47.364-.756.445l-3.251.93a.75.75 0 01-.927-.928l.929-3.25c.081-.286.235-.547.445-.758l8.61-8.61z"/></svg>
                                    Uredi
                                </summary>
                                <form method="POST" action="{{ route('projects.update', $project->id) }}" class="absolute left-0 z-20 mt-1.5 grid min-w-72 gap-2.5 rounded-xl border border-slate-200 bg-white p-4 shadow-2xl">
                                    @csrf @method('PATCH')
                                    <label class="grid gap-1.5 text-xs font-semibold text-slate-500 uppercase tracking-wide">Naziv<input name="name" value="{{ $project->name }}" class="ftth-input font-normal normal-case tracking-normal"></label>
                                    <label class="grid gap-1.5 text-xs font-semibold text-slate-500 uppercase tracking-wide">Šifra<input name="code" value="{{ $project->code }}" class="ftth-input font-normal normal-case tracking-normal"></label>
                                    <label class="grid gap-1.5 text-xs font-semibold text-slate-500 uppercase tracking-wide">Lokacija<input name="location" value="{{ $project->location }}" class="ftth-input font-normal normal-case tracking-normal"></label>
                                    <label class="grid gap-1.5 text-xs font-semibold text-slate-500 uppercase tracking-wide">Investitor<input name="investor" value="{{ $project->investor }}" class="ftth-input font-normal normal-case tracking-normal"></label>
                                    <label class="grid gap-1.5 text-xs font-semibold text-slate-500 uppercase tracking-wide">Status
                                        <select name="status" class="ftth-input text-[13px] font-normal normal-case tracking-normal">
                                            @foreach(['planning' => 'Planiranje', 'active' => 'Aktivan', 'paused' => 'Pauziran', 'completed' => 'Završen'] as $val => $lbl)
                                                <option value="{{ $val }}" @selected($project->status === $val)>{{ $lbl }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <div class="grid grid-cols-2 gap-2">
                                        <label class="grid gap-1.5 text-xs font-semibold text-slate-500 uppercase tracking-wide">Početak<input type="date" name="start_date" value="{{ $project->start_date }}" class="ftth-input font-normal normal-case tracking-normal"></label>
                                        <label class="grid gap-1.5 text-xs font-semibold text-slate-500 uppercase tracking-wide">Rok<input type="date" name="deadline" value="{{ $project->deadline }}" class="ftth-input font-normal normal-case tracking-normal"></label>
                                    </div>
                                    <label class="grid gap-1.5 text-xs font-semibold text-slate-500 uppercase tracking-wide">Opis<textarea name="description" rows="2" class="ftth-input font-normal normal-case tracking-normal">{{ $project->description }}</textarea></label>
                                    <button class="btn-save mt-1">Sačuvaj izmjene</button>
                                </form>
                            </details>

                            {{-- Export dugmad --}}
                            <a href="{{ route('projects.dxf', $project->id) }}"
                               class="tbl-btn"
                               style="background:#fef3c7;color:#92400e;border-color:#fde68a"
                               title="Preuzmi DXF (Gauss-Krüger)">
                                <svg viewBox="0 0 16 16" fill="currentColor" class="w-3 h-3"><path d="M7.47 10.78a.75.75 0 001.06 0l3.75-3.75a.75.75 0 00-1.06-1.06L8.75 8.44V1.75a.75.75 0 00-1.5 0v6.69L4.78 5.97a.75.75 0 00-1.06 1.06l3.75 3.75zM3.75 13a.75.75 0 000 1.5h8.5a.75.75 0 000-1.5h-8.5z"/></svg>
                                DXF
                            </a>
                            <a href="{{ route('projects.geojson', $project->id) }}"
                               class="tbl-btn"
                               style="background:#eff6ff;color:#1e40af;border-color:#bfdbfe"
                               title="Preuzmi GeoJSON">
                                <svg viewBox="0 0 16 16" fill="currentColor" class="w-3 h-3"><path d="M7.47 10.78a.75.75 0 001.06 0l3.75-3.75a.75.75 0 00-1.06-1.06L8.75 8.44V1.75a.75.75 0 00-1.5 0v6.69L4.78 5.97a.75.75 0 00-1.06 1.06l3.75 3.75zM3.75 13a.75.75 0 000 1.5h8.5a.75.75 0 000-1.5h-8.5z"/></svg>
                                GeoJSON
                            </a>
                            <a href="{{ route('projects.print', $project->id) }}"
                               target="_blank"
                               class="tbl-btn"
                               style="background:#f0fdf4;color:#14532d;border-color:#bbf7d0"
                               title="Print projekta">
                                <svg viewBox="0 0 16 16" fill="currentColor" class="w-3 h-3"><path d="M3.75 1A1.75 1.75 0 002 2.75v1.5H1.75a.75.75 0 000 1.5H2v5.5c0 .966.784 1.75 1.75 1.75h8.5A1.75 1.75 0 0014 11.25v-5.5h.25a.75.75 0 000-1.5H14v-1.5A1.75 1.75 0 0012.25 1h-8.5zm0 1.5h8.5a.25.25 0 01.25.25V4.25H3.5V2.75a.25.25 0 01.25-.25zM3.5 11.25v-5.5h9v5.5a.25.25 0 01-.25.25h-8.5a.25.25 0 01-.25-.25zM6 8a.75.75 0 000 1.5h4a.75.75 0 000-1.5H6z"/></svg>
                                Print
                            </a>

                            {{-- Obriši --}}
                            <form method="POST" action="{{ route('projects.delete', $project->id) }}" style="display:inline;" data-confirm-delete="Sigurno obrisati projekat {{ $project->name }}?">
                                @csrf @method('DELETE')
                                <button type="submit" class="tbl-btn tbl-btn-del">
                                    <svg viewBox="0 0 16 16" fill="currentColor" class="w-3 h-3"><path d="M6.5 1.75a.25.25 0 01.25-.25h2.5a.25.25 0 01.25.25V3h-3V1.75zm4.5 0V3h2.25a.75.75 0 010 1.5H2.75a.75.75 0 010-1.5H5V1.75C5 .784 5.784 0 6.75 0h2.5C10.216 0 11 .784 11 1.75zM4.496 6.675a.75.75 0 10-1.492.15l.66 6.6A1.75 1.75 0 005.405 15h5.19a1.75 1.75 0 001.741-1.575l.66-6.6a.75.75 0 00-1.492-.15l-.66 6.6a.25.25 0 01-.249.225H5.405a.25.25 0 01-.249-.225l-.66-6.6z"/></svg>
                                    Obriši
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6">
                        @include('ftth.partials.empty-state', ['title' => 'Nema projekata', 'message' => 'Dodaj prvi projekat kroz formu iznad.'])
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="pagination-bar border-t border-slate-100 bg-slate-50/60 px-4 py-3">{{ $projects->links() }}</div>
</div>

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
