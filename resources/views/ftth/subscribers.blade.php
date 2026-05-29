@extends('ftth.layout')

@section('title', 'Korisnici')
@section('subtitle', 'Evidencija korisnika, prikljucka, splittera i porta u ormaricu.')

@section('content')
<section class="grid gap-6 xl:grid-cols-[420px_1fr]">
    <form method="POST" action="{{ route('subscribers.store') }}" class="rounded-md border border-zinc-200 bg-white p-5 shadow-sm">
        @csrf
        <h2 class="mb-4 font-semibold">Novi korisnik</h2>
        <div class="grid gap-4">
            <label class="grid gap-1 text-sm"><span>Projekat</span><select name="project_id" class="rounded-md border border-zinc-300 px-3 py-2" required><option value="">Odaberi projekat</option>@foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach</select></label>
            <label class="grid gap-1 text-sm"><span>Ormaric</span><select name="cabinet_id" class="rounded-md border border-zinc-300 px-3 py-2"><option value="">Bez ormarica</option>@foreach($cabinets as $cabinet)<option value="{{ $cabinet->id }}">{{ $cabinet->name }} ({{ $cabinet->subscribers_count }}/{{ $cabinet->capacity }})</option>@endforeach</select></label>
            <label class="grid gap-1 text-sm"><span>Ime korisnika</span><input name="name" value="{{ old('name') }}" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            <label class="grid gap-1 text-sm"><span>Adresa</span><input name="address" value="{{ old('address') }}" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            <label class="grid gap-1 text-sm"><span>Telefon</span><input name="phone" value="{{ old('phone') }}" class="rounded-md border border-zinc-300 px-3 py-2"></label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="grid gap-1 text-sm"><span>Splitter broj</span><input type="number" name="splitter_no" value="{{ old('splitter_no') }}" min="1" max="3" class="rounded-md border border-zinc-300 px-3 py-2"></label>
                <label class="grid gap-1 text-sm"><span>Port broj</span><input type="number" name="port_no" value="{{ old('port_no') }}" min="1" max="4" class="rounded-md border border-zinc-300 px-3 py-2"></label>
            </div>
            <label class="grid gap-1 text-sm"><span>Status usluge</span><select name="service_status" class="rounded-md border border-zinc-300 px-3 py-2"><option value="planned">Planiran</option><option value="connected">Spojen</option><option value="in_service">U servisu</option><option value="cancelled">Otkazan</option></select></label>
            <button class="rounded-md bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-700">Sacuvaj korisnika</button>
        </div>
    </form>

    @include('ftth.partials.table', ['rows' => $subscribers, 'columns' => ['name' => 'Korisnik', 'project.name' => 'Projekat', 'cabinet.name' => 'Ormaric', 'address' => 'Adresa', 'service_status' => 'Status']])
</section>
@endsection
