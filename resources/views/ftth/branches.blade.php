@extends('ftth.layout')
@section('title', 'Krakovi mreže')
@section('subtitle', 'Hijerarhija primarnih, sekundarnih i izvedenih krakova.')
@section('content')

<section class="mb-3 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div><div class="stat-label">Krakovi ukupno</div><div class="stat-value">{{ $branchStats['total'] }}</div></div>
            <div class="stat-icon blue"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg></div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div><div class="stat-label">Primarni</div><div class="stat-value">{{ $branchStats['primary'] }}</div></div>
            <div class="stat-icon violet"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M5 3a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2V5a2 2 0 00-2-2H5zM5 11a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2v-2a2 2 0 00-2-2H5zM11 5a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V5zM14 11a1 1 0 011 1v1h1a1 1 0 110 2h-1v1a1 1 0 11-2 0v-1h-1a1 1 0 110-2h1v-1a1 1 0 011-1z"/></svg></div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div><div class="stat-label">Sekundarni</div><div class="stat-value">{{ $branchStats['secondary'] }}</div></div>
            <div class="stat-icon sky"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 4a1 1 0 011-1h4a1 1 0 010 2H6.414l2.293 2.293a1 1 0 01-1.414 1.414L5 6.414V8a1 1 0 01-2 0V4zm9 1a1 1 0 010-2h4a1 1 0 011 1v4a1 1 0 01-2 0V6.414l-2.293 2.293a1 1 0 11-1.414-1.414L13.586 5H12zm-9 7a1 1 0 012 0v1.586l2.293-2.293a1 1 0 111.414 1.414L6.414 15H8a1 1 0 010 2H4a1 1 0 01-1-1v-4zm13-1a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 010-2h1.586l-2.293-2.293a1 1 0 111.414-1.414L15 13.586V12a1 1 0 011-1z" clip-rule="evenodd"/></svg></div>
        </div>
    </article>
    <article class="stat-card">
        <div class="flex items-start justify-between gap-3">
            <div><div class="stat-label">ODO na krakovima</div><div class="stat-value">{{ $branchStats['cabinets'] }}</div></div>
            <div class="stat-icon green"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M4 3a2 2 0 100 4h12a2 2 0 100-4H4zM3 8h14v7a2 2 0 01-2 2H5a2 2 0 01-2-2V8zm5 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z"/></svg></div>
        </div>
    </article>
</section>

<div class="page-toolbar">
    <span class="page-toolbar-info">{{ $branches->total() }} krakova</span>
    <button class="btn-new" data-drawer-open="drawer-branches">
        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/></svg>
        Novi krak
    </button>
</div>

@include('ftth.partials.table', [
    'rows'        => $branches,
    'columns'     => ['name' => 'Krak', 'code' => 'Kod', 'type' => 'Tip', 'odf.name' => 'ODF', 'parentBranch.name' => 'Roditelj', 'route.name' => 'Trasa', 'cabinets_count' => 'ODO'],
    'editRoute'   => fn($id) => route('branches.update', $id),
    'deleteRoute' => fn($id) => route('branches.delete', $id),
    'editFields'  => [
        'project_id'       => ['label' => 'Projekat', 'type' => 'select', 'options' => $projects->pluck('name', 'id')->all()],
        'odf_id'           => ['label' => 'ODF', 'type' => 'select', 'options' => ['' => '—'] + $odfs->mapWithKeys(fn($o) => [$o->id => $o->project->name.' / '.$o->name])->all()],
        'parent_branch_id' => ['label' => 'Roditeljski krak', 'type' => 'select', 'options' => ['' => 'Glavni krak'] + $parentBranches->mapWithKeys(fn($b) => [$b->id => $b->project->name.' / '.$b->name])->all()],
        'route_id'         => ['label' => 'Vezana trasa', 'type' => 'select', 'options' => ['' => '—'] + $routes->mapWithKeys(fn($r) => [$r->id => $r->project->name.' / '.$r->name])->all()],
        'name'             => 'Naziv', 'code' => 'Kod',
        'sort_order'       => ['label' => 'Redoslijed', 'type' => 'number'],
        'type'             => ['label' => 'Tip', 'type' => 'select', 'options' => ['secondary' => 'Sekundarni', 'primary' => 'Primarni']],
    ],
])

<div id="drawer-branches" class="app-drawer">
    <div class="app-drawer-backdrop"></div>
    <div class="app-drawer-panel">
        <div class="app-drawer-head">
            <div class="app-drawer-head-left">
                <div class="page-form-icon"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg></div>
                <h2>Novi krak</h2>
            </div>
            <button type="button" class="app-drawer-close" data-drawer-close="drawer-branches"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg></button>
        </div>
        <form class="app-drawer-body" method="POST" action="{{ route('branches.store') }}">
            @csrf
            <label class="ftth-label">Projekat
                <select name="project_id" class="ftth-input" required>
                    <option value="">Odaberi projekat</option>
                    @foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach
                </select>
            </label>
            <label class="ftth-label">ODF
                <select name="odf_id" class="ftth-input">
                    <option value="">ODF nije odabran</option>
                    @foreach($odfs as $odf)<option value="{{ $odf->id }}">{{ $odf->project->name }} / {{ $odf->name }}</option>@endforeach
                </select>
            </label>
            <label class="ftth-label">Roditeljski krak
                <select name="parent_branch_id" class="ftth-input">
                    <option value="">Glavni krak</option>
                    @foreach($parentBranches as $branch)<option value="{{ $branch->id }}">{{ $branch->project->name }} / {{ $branch->name }}</option>@endforeach
                </select>
            </label>
            <label class="ftth-label">Vezana trasa
                <select name="route_id" class="ftth-input">
                    <option value="">Bez vezane trase</option>
                    @foreach($routes as $route)<option value="{{ $route->id }}">{{ $route->project->name }} / {{ $route->name }}</option>@endforeach
                </select>
            </label>
            <label class="ftth-label">Naziv kraka<input name="name" class="ftth-input" placeholder="Sekundarni krak 1.1" required></label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="ftth-label">Kod<input name="code" class="ftth-input" placeholder="1.1"></label>
                <label class="ftth-label">Redoslijed<input name="sort_order" type="number" value="0" min="0" class="ftth-input"></label>
            </div>
            <label class="ftth-label">Tip kraka
                <select name="type" class="ftth-input">
                    <option value="secondary">Sekundarni</option>
                    <option value="primary">Primarni</option>
                </select>
            </label>
            <button class="btn-save">Sačuvaj krak</button>
        </form>
    </div>
</div>
@if($errors->any())<script>document.getElementById('drawer-branches')?.classList.add('open');</script>@endif

@endsection
