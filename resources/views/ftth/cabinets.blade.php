@extends('ftth.layout')

@section('title', 'Zeleni ormarici')
@section('subtitle', 'Distribucione tacke sa najvise 3 splittera i 12 korisnickih izlaza.')

@section('content')
<section class="grid gap-6 xl:grid-cols-[420px_1fr]">
    <form method="POST" action="{{ route('cabinets.store') }}" class="rounded-md border border-zinc-200 bg-white p-5 shadow-sm">
        @csrf
        <h2 class="mb-4 font-semibold">Novi ormaric</h2>
        <div class="grid gap-4">
            <label class="grid gap-1 text-sm"><span>Projekat</span><select name="project_id" class="rounded-md border border-zinc-300 px-3 py-2" required><option value="">Odaberi projekat</option>@foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach</select></label>
            <label class="grid gap-1 text-sm"><span>ODF lokacija</span><select name="odf_id" class="rounded-md border border-zinc-300 px-3 py-2"><option value="">Bez ODF veze</option>@foreach($odfs as $odf)<option value="{{ $odf->id }}">{{ $odf->name }} - {{ $odf->project->name }}</option>@endforeach</select></label>
            <label class="grid gap-1 text-sm"><span>Naziv ormarica</span><input name="name" value="{{ old('name') }}" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            <label class="grid gap-1 text-sm"><span>Adresa</span><input name="address" value="{{ old('address') }}" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="grid gap-1 text-sm"><span>Broj splittera</span><input type="number" name="splitter_count" value="{{ old('splitter_count', 3) }}" min="1" max="3" class="rounded-md border border-zinc-300 px-3 py-2"></label>
                <label class="grid gap-1 text-sm"><span>Portova po splitteru</span><input type="number" name="ports_per_splitter" value="{{ old('ports_per_splitter', 4) }}" min="1" max="4" class="rounded-md border border-zinc-300 px-3 py-2"></label>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="grid gap-1 text-sm"><span>Latitude</span><input name="latitude" value="{{ old('latitude') }}" class="rounded-md border border-zinc-300 px-3 py-2"></label>
                <label class="grid gap-1 text-sm"><span>Longitude</span><input name="longitude" value="{{ old('longitude') }}" class="rounded-md border border-zinc-300 px-3 py-2"></label>
            </div>
            <div class="rounded-md bg-emerald-50 p-3 text-sm text-emerald-800">Preporuceno: 3 splittera x 4 porta = 12 korisnika po ormaricu.</div>
            <button class="rounded-md bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-700">Sacuvaj ormaric</button>
        </div>
    </form>

    <div class="overflow-hidden rounded-md border border-zinc-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold uppercase text-zinc-500">
                    <tr><th class="px-4 py-3">Ormaric</th><th>Projekat</th><th>ODF</th><th>Kapacitet</th><th>Zauzeto</th><th>Status</th></tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse($cabinets as $cabinet)
                        <tr class="hover:bg-zinc-50">
                            <td class="px-4 py-3 font-medium">{{ $cabinet->name }}</td>
                            <td>{{ $cabinet->project->name }}</td>
                            <td>{{ $cabinet->odf->name ?? '-' }}</td>
                            <td>{{ $cabinet->capacity }}</td>
                            <td class="min-w-40 pr-4">
                                <div class="flex items-center gap-2">
                                    <div class="h-2 w-24 rounded bg-zinc-100"><div class="h-2 rounded {{ $cabinet->utilization >= 100 ? 'bg-red-600' : ($cabinet->utilization >= 80 ? 'bg-amber-500' : 'bg-emerald-600') }}" style="width: {{ min($cabinet->utilization, 100) }}%"></div></div>
                                    <span>{{ $cabinet->subscribers_count }}/{{ $cabinet->capacity }}</span>
                                </div>
                            </td>
                            <td><span class="rounded-md px-2 py-1 text-xs font-semibold {{ $cabinet->utilization >= 100 ? 'bg-red-100 text-red-800' : ($cabinet->utilization >= 80 ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800') }}">{{ $cabinet->utilization >= 100 ? 'Popunjen' : $cabinet->utilization.'%' }}</span></td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-8 text-center text-zinc-500" colspan="6">Nema ormarica. Dodaj prvi ormaric kroz formu lijevo.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-zinc-100 px-4 py-3">{{ $cabinets->links() }}</div>
    </div>
</section>
@endsection
