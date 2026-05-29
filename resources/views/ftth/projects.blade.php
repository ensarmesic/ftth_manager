@extends('ftth.layout')

@section('title', 'Projekti')
@section('subtitle', 'Kreiranje i pracenje FTTH projekata po lokaciji i statusu.')

@section('content')
<section class="grid gap-6 xl:grid-cols-[420px_1fr]">
    <form method="POST" action="{{ route('projects.store') }}" class="rounded-md border border-zinc-200 bg-white p-5 shadow-sm">
        @csrf
        <h2 class="mb-4 font-semibold">Novi projekat</h2>
        <div class="grid gap-4">
            <label class="grid gap-1 text-sm"><span>Naziv projekta</span><input name="name" value="{{ old('name') }}" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            <label class="grid gap-1 text-sm"><span>Sifra projekta</span><input name="code" value="{{ old('code') }}" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            <label class="grid gap-1 text-sm"><span>Lokacija</span><input name="location" value="{{ old('location') }}" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            <label class="grid gap-1 text-sm"><span>Status</span><select name="status" class="rounded-md border border-zinc-300 px-3 py-2"><option value="planning">Planiranje</option><option value="active">Aktivan</option><option value="paused">Pauziran</option><option value="completed">Zavrsen</option></select></label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="grid gap-1 text-sm"><span>Pocetak</span><input type="date" name="start_date" value="{{ old('start_date') }}" class="rounded-md border border-zinc-300 px-3 py-2"></label>
                <label class="grid gap-1 text-sm"><span>Rok</span><input type="date" name="deadline" value="{{ old('deadline') }}" class="rounded-md border border-zinc-300 px-3 py-2"></label>
            </div>
            <label class="grid gap-1 text-sm"><span>Opis</span><textarea name="description" rows="3" class="rounded-md border border-zinc-300 px-3 py-2">{{ old('description') }}</textarea></label>
            <button class="rounded-md bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-700">Sacuvaj projekat</button>
        </div>
    </form>

    @include('ftth.partials.table', ['rows' => $projects, 'columns' => ['name' => 'Naziv', 'code' => 'Sifra', 'location' => 'Lokacija', 'status' => 'Status'], 'deleteRoute' => fn($id) => route('projects.delete', $id)])
</section>
@endsection
