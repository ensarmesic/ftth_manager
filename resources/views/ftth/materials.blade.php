@extends('ftth.layout')

@section('title', 'Materijali')
@section('subtitle', 'Planirane i utrošene količine materijala po projektu.')

@section('content')
<section class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-sm text-slate-500">Stavke materijala</div>
        <div class="mt-2 text-2xl font-semibold">{{ $materials->total() }}</div>
    </article>
    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-sm text-slate-500">Planirano KM</div>
        <div class="mt-2 text-2xl font-semibold">{{ number_format($materials->sum(fn ($material) => $material->planned_quantity * $material->unit_price), 2) }}</div>
    </article>
    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-sm text-slate-500">Utrošeno KM</div>
        <div class="mt-2 text-2xl font-semibold">{{ number_format($materials->sum(fn ($material) => $material->used_quantity * $material->unit_price), 2) }}</div>
    </article>
    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-sm text-slate-500">Projekti</div>
        <div class="mt-2 text-2xl font-semibold">{{ $projects->count() }}</div>
    </article>
</section>

<section class="mb-6 rounded-md border border-emerald-200 bg-emerald-50 p-4">
    <h2 class="font-semibold text-emerald-950">Automatski obračun</h2>
    <p class="mt-1 text-sm text-emerald-800">Generiše mikrocijevi 14/10 i 10/8, optičke kablove po broju niti, ODF, ODO i splittere.</p>
    <div class="mt-3 flex flex-wrap gap-2">
        @foreach($projects as $project)
            <form method="POST" action="{{ route('materials.calculate', $project) }}">@csrf<button class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white">{{ $project->name }}</button></form>
        @endforeach
    </div>
</section>
<section class="grid gap-6 xl:grid-cols-[420px_1fr]">
    <form method="POST" action="{{ route('materials.store') }}" class="rounded-md border border-zinc-200 bg-white p-5 shadow-sm">
        @csrf
        <h2 class="mb-4 font-semibold">Novi materijal</h2>
        <div class="grid gap-4">
            <label class="grid gap-1 text-sm"><span>Projekat</span><select name="project_id" class="rounded-md border border-zinc-300 px-3 py-2" required><option value="">Odaberi projekat</option>@foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach</select></label>
            <label class="grid gap-1 text-sm"><span>Naziv materijala</span><input name="name" value="{{ old('name') }}" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            <label class="grid gap-1 text-sm"><span>Jedinica mjere</span><input name="unit" value="{{ old('unit', 'kom') }}" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            <div class="grid gap-3 sm:grid-cols-3">
                <label class="grid gap-1 text-sm"><span>Planirano</span><input type="number" step="0.01" name="planned_quantity" value="{{ old('planned_quantity', 0) }}" min="0" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
                <label class="grid gap-1 text-sm"><span>Utrošeno</span><input type="number" step="0.01" name="used_quantity" value="{{ old('used_quantity', 0) }}" min="0" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
                <label class="grid gap-1 text-sm"><span>Cijena KM</span><input type="number" step="0.01" name="unit_price" value="{{ old('unit_price', 0) }}" min="0" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            </div>
            <button class="rounded-md bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-700">Sačuvaj materijal</button>
        </div>
    </form>

    @include('ftth.partials.table', ['rows' => $materials, 'columns' => ['name' => 'Materijal', 'project.name' => 'Projekat', 'planned_quantity' => 'Plan', 'used_quantity' => 'Utrošeno', 'unit' => 'Jed.', 'unit_price' => 'KM'], 'editRoute' => fn($id) => route('materials.update', $id), 'deleteRoute' => fn($id) => route('materials.delete', $id), 'editFields' => [
        'project_id' => ['label' => 'Projekat', 'type' => 'select', 'options' => $projects->pluck('name', 'id')->all()],
        'name' => 'Materijal', 'unit' => 'Jedinica', 'planned_quantity' => ['label' => 'Planirano', 'type' => 'number'],
        'used_quantity' => ['label' => 'Utrošeno', 'type' => 'number'], 'unit_price' => ['label' => 'Cijena KM', 'type' => 'number'],
    ]])
</section>
@endsection
