@extends('ftth.layout')

@section('title', 'Projekti')
@section('subtitle', 'Kreiranje i praćenje FTTH projekata po lokaciji i statusu.')

@section('content')
<section class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-sm text-slate-500">Projekti ukupno</div>
        <div class="mt-2 text-2xl font-semibold">{{ $projectStats['total'] }}</div>
    </article>
    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-sm text-slate-500">Aktivni</div>
        <div class="mt-2 text-2xl font-semibold">{{ $projectStats['active'] }}</div>
    </article>
    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-sm text-slate-500">Planiranje</div>
        <div class="mt-2 text-2xl font-semibold">{{ $projectStats['planning'] }}</div>
    </article>
    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-sm text-slate-500">Završeni</div>
        <div class="mt-2 text-2xl font-semibold">{{ $projectStats['completed'] }}</div>
    </article>
</section>

<section class="grid gap-6 xl:grid-cols-[420px_1fr]">
    <form method="POST" action="{{ route('projects.store') }}" class="rounded-md border border-zinc-200 bg-white p-5 shadow-sm">
        @csrf
        <h2 class="mb-4 font-semibold">Novi projekat</h2>
        <div class="grid gap-4">
            <label class="grid gap-1 text-sm"><span>Naziv projekta</span><input name="name" value="{{ old('name') }}" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            <label class="grid gap-1 text-sm"><span>Šifra projekta</span><input name="code" value="{{ old('code') }}" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            <label class="grid gap-1 text-sm"><span>Lokacija</span><input name="location" value="{{ old('location') }}" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            <label class="grid gap-1 text-sm"><span>Investitor</span><input name="investor" value="{{ old('investor') }}" class="rounded-md border border-zinc-300 px-3 py-2"></label>
            <label class="grid gap-1 text-sm"><span>Status</span><select name="status" class="rounded-md border border-zinc-300 px-3 py-2"><option value="planning">Planiranje</option><option value="active">Aktivan</option><option value="paused">Pauziran</option><option value="completed">Zavrsen</option></select></label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="grid gap-1 text-sm"><span>Početak</span><input type="date" name="start_date" value="{{ old('start_date') }}" class="rounded-md border border-zinc-300 px-3 py-2"></label>
                <label class="grid gap-1 text-sm"><span>Rok</span><input type="date" name="deadline" value="{{ old('deadline') }}" class="rounded-md border border-zinc-300 px-3 py-2"></label>
            </div>
            <label class="grid gap-1 text-sm"><span>Opis</span><textarea name="description" rows="3" class="rounded-md border border-zinc-300 px-3 py-2">{{ old('description') }}</textarea></label>
            <button class="rounded-md bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-700">Sačuvaj projekat</button>
        </div>
    </form>

    @include('ftth.partials.table', ['rows' => $projects, 'columns' => ['name' => 'Naziv', 'code' => 'Šifra', 'location' => 'Lokacija', 'investor' => 'Investitor', 'status' => 'Status'], 'editRoute' => fn($id) => route('projects.update', $id), 'deleteRoute' => fn($id) => route('projects.delete', $id), 'editFields' => [
        'name' => 'Naziv', 'code' => 'Šifra', 'location' => 'Lokacija', 'investor' => 'Investitor',
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['planning' => 'Planiranje', 'active' => 'Aktivan', 'paused' => 'Pauziran', 'completed' => 'Završen']],
        'start_date' => ['label' => 'Početak', 'type' => 'date'], 'deadline' => ['label' => 'Rok', 'type' => 'date'], 'description' => 'Opis',
    ]])
</section>
@endsection
