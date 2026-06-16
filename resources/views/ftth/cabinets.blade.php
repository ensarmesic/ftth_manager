@extends('ftth.layout')
@section('title', 'ODO ormarici')
@section('subtitle', 'Distribucione tačke koje se planiraju na sekundarnim krakovima.')
@section('content')

<section class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div><div class="stat-label">Ukupno ODO</div><div class="stat-value">{{ $cabinetStats['total'] }}</div></div>
            <div class="stat-icon blue"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M4 3a2 2 0 100 4h12a2 2 0 100-4H4zM3 8h14v7a2 2 0 01-2 2H5a2 2 0 01-2-2V8zm5 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z"/></svg></div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div><div class="stat-label">Portovi zauzeti</div><div class="stat-value">{{ $cabinetStats['used_ports'] }}<span class="text-sm font-normal text-slate-400">/{{ $cabinetStats['capacity'] }}</span></div></div>
            <div class="stat-icon sky"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 3a1 1 0 000 2v8a2 2 0 002 2h2.586l-1.293 1.293a1 1 0 101.414 1.414L10 15.414l2.293 2.293a1 1 0 001.414-1.414L12.414 15H15a2 2 0 002-2V5a1 1 0 100-2H3zm11 4a1 1 0 10-2 0v4a1 1 0 102 0V7zm-3 1a1 1 0 10-2 0v3a1 1 0 102 0V8zM8 9a1 1 0 00-2 0v2a1 1 0 102 0V9z" clip-rule="evenodd"/></svg></div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div><div class="stat-label">Popunjeni</div><div class="stat-value">{{ $cabinetStats['full'] }}</div></div>
            <div class="stat-icon red"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg></div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div><div class="stat-label">Slobodni portovi</div><div class="stat-value">{{ max($cabinetStats['capacity'] - $cabinetStats['used_ports'], 0) }}</div></div>
            <div class="stat-icon green"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v2H7a1 1 0 100 2h2v2a1 1 0 102 0v-2h2a1 1 0 100-2h-2V7z" clip-rule="evenodd"/></svg></div>
        </div>
    </article>
</section>

<div class="page-toolbar">
    <span class="page-toolbar-info">{{ $cabinets->total() }} ormarića</span>
    <button class="btn-new" data-drawer-open="drawer-cabinets">
        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/></svg>
        Novi ormarić
    </button>
</div>

<div class="app-table-card">
    <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead>
                <tr><th>Ormarić</th><th>Projekat</th><th>Krak</th><th>Napajanje</th><th>Kapacitet</th><th>Popunjenost</th><th>Status</th><th>Akcije</th></tr>
            </thead>
            <tbody>
                @forelse($cabinets as $cabinet)
                    <tr>
                        <td class="font-semibold">
                            {{ $cabinet->name }}
                            @if($cabinet->childCabinets->isNotEmpty())
                                <div class="text-xs text-slate-400 font-normal mt-0.5">{{ $cabinet->childCabinets->count() }} izvedenih</div>
                            @endif
                        </td>
                        <td>{{ $cabinet->project->name }}</td>
                        <td>
                            <div>{{ $cabinet->branch?->name ?? '—' }}</div>
                            @if($cabinet->branch_order)<small class="text-slate-400">#{{ $cabinet->branch_order }}</small>@endif
                        </td>
                        <td>
                            <div class="font-semibold text-slate-800">{{ $cabinet->parentCabinet?->name ?? ($cabinet->odf?->name ?? '—') }}</div>
                            <div class="text-xs text-slate-400">{{ $cabinet->parentCabinet ? 'iz ODO' : 'iz ODF-a' }}</div>
                        </td>
                        <td>{{ $cabinet->capacity }}</td>
                        <td class="min-w-36">
                            <div class="flex items-center gap-2">
                                <div class="h-1.5 w-20 rounded-full bg-slate-100">
                                    <div class="h-1.5 rounded-full {{ $cabinet->houses_count >= $cabinet->capacity ? 'bg-red-500' : ($cabinet->houses_count / max($cabinet->capacity,1) >= .8 ? 'bg-amber-400' : 'bg-emerald-500') }}" style="width:{{ min($cabinet->houses_count/max($cabinet->capacity,1)*100,100) }}%"></div>
                                </div>
                                <span class="text-xs font-semibold text-slate-600">{{ $cabinet->houses_count }}/{{ $cabinet->capacity }}</span>
                            </div>
                        </td>
                        <td>
                            @php($u = round($cabinet->houses_count/max($cabinet->capacity,1)*100))
                            @include('ftth.partials.badge', ['value' => $u >= 100 ? 'cancelled' : ($u >= 80 ? 'planned' : 'active'), 'variant' => $u >= 100 ? 'cancelled' : ($u >= 80 ? 'planned' : 'active')])
                        </td>
                        <td>
                            <div class="flex items-center gap-1.5">
                                <details class="relative inline-block">
                                    <summary class="tbl-btn tbl-btn-edit list-none cursor-pointer">
                                        <svg viewBox="0 0 16 16" fill="currentColor" class="w-3 h-3"><path d="M11.013 1.427a1.75 1.75 0 012.474 0l1.086 1.086a1.75 1.75 0 010 2.474l-8.61 8.61c-.21.21-.47.364-.756.445l-3.251.93a.75.75 0 01-.927-.928l.929-3.25c.081-.286.235-.547.445-.758l8.61-8.61z"/></svg>
                                        Uredi
                                    </summary>
                                    <form method="POST" action="{{ route('cabinets.update', $cabinet->id) }}" class="absolute left-0 z-20 mt-1.5 grid min-w-72 gap-2.5 rounded-xl border border-slate-200 bg-white p-4 shadow-2xl">
                                        @csrf @method('PATCH')
                                        <label class="ftth-label text-[10px]">Projekat<select name="project_id" class="ftth-input font-normal normal-case tracking-normal">@foreach($projects as $p)<option value="{{ $p->id }}" @selected($cabinet->project_id===$p->id)>{{ $p->name }}</option>@endforeach</select></label>
                                        <label class="ftth-label text-[10px]">ODF<select name="odf_id" class="ftth-input font-normal normal-case tracking-normal"><option value="">Bez ODF veze</option>@foreach($odfs as $o)<option value="{{ $o->id }}" @selected($cabinet->odf_id===$o->id)>{{ $o->name }}</option>@endforeach</select></label>
                                        <label class="ftth-label text-[10px]">Napaja se iz ODO<select name="parent_cabinet_id" class="ftth-input font-normal normal-case tracking-normal"><option value="">Direktno iz ODF-a</option>@foreach($parentCabinets as $pc)@continue($pc->id===$cabinet->id)<option value="{{ $pc->id }}" @selected($cabinet->parent_cabinet_id===$pc->id)>{{ $pc->name }}</option>@endforeach</select></label>
                                        <label class="ftth-label text-[10px]">Krak<select name="branch_id" class="ftth-input font-normal normal-case tracking-normal"><option value="">Neraspoređen</option>@foreach($branches as $b)<option value="{{ $b->id }}" @selected($cabinet->branch_id===$b->id)>{{ $b->name }}</option>@endforeach</select></label>
                                        <label class="ftth-label text-[10px]">Naziv<input name="name" value="{{ $cabinet->name }}" class="ftth-input font-normal normal-case tracking-normal"></label>
                                        <label class="ftth-label text-[10px]">Adresa<input name="address" value="{{ $cabinet->address }}" class="ftth-input font-normal normal-case tracking-normal"></label>
                                        <div class="grid grid-cols-2 gap-2">
                                            <label class="ftth-label text-[10px]">Splitteri<input type="number" name="splitter_count" value="{{ $cabinet->splitter_count }}" min="1" max="3" class="ftth-input font-normal normal-case tracking-normal"></label>
                                            <label class="ftth-label text-[10px]">Portovi<input type="number" name="ports_per_splitter" value="{{ $cabinet->ports_per_splitter }}" min="1" max="4" class="ftth-input font-normal normal-case tracking-normal"></label>
                                        </div>
                                        <div class="grid grid-cols-2 gap-2">
                                            <label class="ftth-label text-[10px]">Lat<input name="latitude" value="{{ $cabinet->latitude }}" class="ftth-input font-normal normal-case tracking-normal"></label>
                                            <label class="ftth-label text-[10px]">Lng<input name="longitude" value="{{ $cabinet->longitude }}" class="ftth-input font-normal normal-case tracking-normal"></label>
                                        </div>
                                        <button class="btn-save">Sačuvaj</button>
                                    </form>
                                </details>
                                <form method="POST" action="{{ route('cabinets.delete', $cabinet->id) }}" class="inline" data-confirm-delete="Sigurno obrisati ovaj ODO ormarić?">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="tbl-btn tbl-btn-del">
                                        <svg viewBox="0 0 16 16" fill="currentColor" class="w-3 h-3"><path d="M6.5 1.75a.25.25 0 01.25-.25h2.5a.25.25 0 01.25.25V3h-3V1.75zm4.5 0V3h2.25a.75.75 0 010 1.5H2.75a.75.75 0 010-1.5H5V1.75C5 .784 5.784 0 6.75 0h2.5C10.216 0 11 .784 11 1.75z"/></svg>
                                        Obriši
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8">@include('ftth.partials.empty-state', ['title' => 'Nema ormarića', 'message' => 'Dodaj prvi ODO ormarić kroz formu ili automatski planer.'])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="pagination-bar border-t border-slate-100 bg-slate-50/60 px-4 py-3">{{ $cabinets->links() }}</div>
</div>

<div id="drawer-cabinets" class="app-drawer">
    <div class="app-drawer-backdrop"></div>
    <div class="app-drawer-panel">
        <div class="app-drawer-head">
            <div class="app-drawer-head-left">
                <div class="page-form-icon"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M4 3a2 2 0 100 4h12a2 2 0 100-4H4zM3 8h14v7a2 2 0 01-2 2H5a2 2 0 01-2-2V8zm5 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z"/></svg></div>
                <h2>Novi ODO ormarić</h2>
            </div>
            <button type="button" class="app-drawer-close" data-drawer-close="drawer-cabinets"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg></button>
        </div>
        <form class="app-drawer-body" method="POST" action="{{ route('cabinets.store') }}">
            @csrf
            <label class="ftth-label">Projekat
                <select name="project_id" class="ftth-input" required>
                    <option value="">Odaberi projekat</option>
                    @foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach
                </select>
            </label>
            <label class="ftth-label">ODF lokacija
                <select name="odf_id" class="ftth-input">
                    <option value="">Bez direktne ODF veze</option>
                    @foreach($odfs as $odf)<option value="{{ $odf->id }}">{{ $odf->name }} — {{ $odf->project->name }}</option>@endforeach
                </select>
            </label>
            <label class="ftth-label">Napaja se iz ODO
                <select name="parent_cabinet_id" class="ftth-input">
                    <option value="">Direktno iz ODF-a</option>
                    @foreach($parentCabinets as $parentCabinet)<option value="{{ $parentCabinet->id }}">{{ $parentCabinet->name }} — {{ $parentCabinet->project->name }}</option>@endforeach
                </select>
            </label>
            <div class="grid gap-3 sm:grid-cols-[1fr_110px]">
                <label class="ftth-label">Krak
                    <select name="branch_id" class="ftth-input">
                        <option value="">Neraspoređen ODO</option>
                        @foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->project->name }} / {{ $branch->name }}</option>@endforeach
                    </select>
                </label>
                <label class="ftth-label">Redoslijed<input type="number" name="branch_order" value="0" min="0" class="ftth-input"></label>
            </div>
            <label class="ftth-label">Naziv ormarića<input name="name" value="{{ old('name') }}" class="ftth-input" required></label>
            <label class="ftth-label">Adresa<input name="address" value="{{ old('address') }}" class="ftth-input" required></label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="ftth-label">Broj splittera<input type="number" name="splitter_count" value="{{ old('splitter_count', 3) }}" min="1" max="3" class="ftth-input"></label>
                <label class="ftth-label">Portova po splitteru<input type="number" name="ports_per_splitter" value="{{ old('ports_per_splitter', 4) }}" min="1" max="4" class="ftth-input"></label>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="ftth-label">Latitude<input name="latitude" value="{{ old('latitude') }}" class="ftth-input"></label>
                <label class="ftth-label">Longitude<input name="longitude" value="{{ old('longitude') }}" class="ftth-input"></label>
            </div>
            <div class="info-banner green">
                <svg viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4 shrink-0 mt-0.5"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
                <p>Za izvedeni ormarić (npr. FTTH 2-1.1) odaberi roditeljski ODO FTTH 2-1.</p>
            </div>
            <button class="btn-save">Sačuvaj ormarić</button>
        </form>
    </div>
</div>
@if($errors->any())<script>document.getElementById('drawer-cabinets')?.classList.add('open');</script>@endif

@endsection
