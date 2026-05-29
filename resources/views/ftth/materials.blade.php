@extends('ftth.layout')

@section('title', 'Materijali')
@section('subtitle', 'Planirane i utrosene kolicine materijala po projektu.')

@section('content')
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
                <label class="grid gap-1 text-sm"><span>Utroseno</span><input type="number" step="0.01" name="used_quantity" value="{{ old('used_quantity', 0) }}" min="0" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
                <label class="grid gap-1 text-sm"><span>Cijena KM</span><input type="number" step="0.01" name="unit_price" value="{{ old('unit_price', 0) }}" min="0" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            </div>
            <button class="rounded-md bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-700">Sacuvaj materijal</button>
        </div>
    </form>

    @include('ftth.partials.table', ['rows' => $materials, 'columns' => ['name' => 'Materijal', 'project.name' => 'Projekat', 'planned_quantity' => 'Plan', 'used_quantity' => 'Utroseno', 'unit' => 'Jed.', 'unit_price' => 'KM'], 'deleteRoute' => fn($id) => route('materials.delete', $id)])
</section>
@endsection
