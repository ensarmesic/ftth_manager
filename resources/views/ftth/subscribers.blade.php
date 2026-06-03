@extends('ftth.layout')

@section('title', 'Korisnici')
@section('subtitle', 'Evidencija korisnika, priključka, splittera i porta u ormariću.')

@section('content')
<section class="grid gap-6 xl:grid-cols-[420px_1fr]">
    <form method="POST" action="{{ route('subscribers.store') }}" class="rounded-md border border-zinc-200 bg-white p-5 shadow-sm">
        @csrf
        <h2 class="mb-4 font-semibold">Novi korisnik</h2>
        <div class="grid gap-4">
            <label class="grid gap-1 text-sm"><span>Projekat</span><select name="project_id" class="rounded-md border border-zinc-300 px-3 py-2" required><option value="">Odaberi projekat</option>@foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach</select></label>
            <label class="grid gap-1 text-sm"><span>Ormarić</span><select name="cabinet_id" id="cabinet-select" class="rounded-md border border-zinc-300 px-3 py-2"><option value="">Bez ormarića</option>@foreach($cabinets as $cabinet)<option value="{{ $cabinet->id }}" data-capacity="{{ $cabinet->capacity }}" data-used="{{ $cabinet->subscribers_count }}">{{ $cabinet->name }} ({{ $cabinet->subscribers_count }}/{{ $cabinet->capacity }})</option>@endforeach</select></label>
            <div id="capacity-warning" class="hidden rounded-md bg-red-50 p-3 text-sm font-medium text-red-800">
                Odabrani ormarić je popunjen! Odaberi drugi ormarić ili planiraj novi.
            </div>
            <label class="grid gap-1 text-sm"><span>Ime korisnika</span><input name="name" value="{{ old('name') }}" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            <label class="grid gap-1 text-sm"><span>Adresa</span><input name="address" value="{{ old('address') }}" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="grid gap-1 text-sm"><span>Latitude</span><input name="latitude" value="{{ old('latitude') }}" class="rounded-md border border-zinc-300 px-3 py-2"></label>
                <label class="grid gap-1 text-sm"><span>Longitude</span><input name="longitude" value="{{ old('longitude') }}" class="rounded-md border border-zinc-300 px-3 py-2"></label>
            </div>
            <label class="grid gap-1 text-sm"><span>Telefon</span><input name="phone" value="{{ old('phone') }}" class="rounded-md border border-zinc-300 px-3 py-2"></label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="grid gap-1 text-sm"><span>Splitter broj</span><input type="number" name="splitter_no" value="{{ old('splitter_no') }}" min="1" max="3" class="rounded-md border border-zinc-300 px-3 py-2"></label>
                <label class="grid gap-1 text-sm"><span>Port broj</span><input type="number" name="port_no" value="{{ old('port_no') }}" min="1" max="4" class="rounded-md border border-zinc-300 px-3 py-2"></label>
            </div>
            <label class="grid gap-1 text-sm"><span>Status usluge</span><select name="service_status" class="rounded-md border border-zinc-300 px-3 py-2"><option value="planned">Planiran</option><option value="connected">Spojen</option><option value="in_service">U servisu</option><option value="cancelled">Otkazan</option></select></label>
            <button type="submit" id="submit-btn" class="rounded-md bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-700">Sačuvaj korisnika</button>
        </div>
    </form>

    @include('ftth.partials.table', ['rows' => $subscribers, 'columns' => ['name' => 'Korisnik', 'project.name' => 'Projekat', 'cabinet.name' => 'Ormarić', 'address' => 'Adresa', 'service_status' => 'Status'], 'editRoute' => fn($id) => route('subscribers.update', $id), 'deleteRoute' => fn($id) => route('subscribers.delete', $id), 'editFields' => [
        'project_id' => ['label' => 'Projekat', 'type' => 'select', 'options' => $projects->pluck('name', 'id')->all()],
        'cabinet_id' => ['label' => 'Ormarić', 'type' => 'select', 'options' => ['' => 'Bez ormarića'] + $cabinets->pluck('name', 'id')->all()],
        'name' => 'Korisnik', 'address' => 'Adresa', 'latitude' => 'Latitude', 'longitude' => 'Longitude', 'phone' => 'Telefon',
        'splitter_no' => ['label' => 'Splitter', 'type' => 'number'], 'port_no' => ['label' => 'Port', 'type' => 'number'],
        'service_status' => ['label' => 'Status', 'type' => 'select', 'options' => ['planned' => 'Planiran', 'connected' => 'Spojen', 'in_service' => 'U servisu', 'cancelled' => 'Otkazan']],
    ]])
</section>

<script>
document.getElementById('cabinet-select').addEventListener('change', function() {
    const option = this.options[this.selectedIndex];
    const capacity = parseInt(option.dataset.capacity || 0);
    const used = parseInt(option.dataset.used || 0);
    const warning = document.getElementById('capacity-warning');
    const submitBtn = document.getElementById('submit-btn');

    if (capacity > 0 && used >= capacity) {
        warning.classList.remove('hidden');
        submitBtn.disabled = true;
        submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
    } else {
        warning.classList.add('hidden');
        submitBtn.disabled = false;
        submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
    }
});

// Trigger on page load if cabinet is pre-selected
document.getElementById('cabinet-select').dispatchEvent(new Event('change'));
</script>
@endsection
