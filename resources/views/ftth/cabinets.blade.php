@extends('ftth.layout')

@section('title', 'ODO ormarići')
@section('subtitle', 'Distribucione tačke sa najviše 3 splittera i 12 korisničkih izlaza.')

@section('content')
<section class="grid gap-6 xl:grid-cols-[420px_1fr]">
    <form method="POST" action="{{ route('cabinets.store') }}" class="rounded-md border border-zinc-200 bg-white p-5 shadow-sm">
        @csrf
        <h2 class="mb-4 font-semibold">Novi ormarić</h2>
        <div class="grid gap-4">
            <label class="grid gap-1 text-sm"><span>Projekat</span><select name="project_id" class="rounded-md border border-zinc-300 px-3 py-2" required><option value="">Odaberi projekat</option>@foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach</select></label>
            <label class="grid gap-1 text-sm"><span>ODF lokacija</span><select name="odf_id" class="rounded-md border border-zinc-300 px-3 py-2"><option value="">Bez ODF veze</option>@foreach($odfs as $odf)<option value="{{ $odf->id }}">{{ $odf->name }} - {{ $odf->project->name }}</option>@endforeach</select></label>
            <label class="grid gap-1 text-sm"><span>Naziv ormarića</span><input name="name" value="{{ old('name') }}" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            <label class="grid gap-1 text-sm"><span>Adresa</span><input name="address" value="{{ old('address') }}" class="rounded-md border border-zinc-300 px-3 py-2" required></label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="grid gap-1 text-sm"><span>Broj splittera</span><input type="number" name="splitter_count" value="{{ old('splitter_count', 3) }}" min="1" max="3" class="rounded-md border border-zinc-300 px-3 py-2"></label>
                <label class="grid gap-1 text-sm"><span>Portova po splitteru</span><input type="number" name="ports_per_splitter" value="{{ old('ports_per_splitter', 4) }}" min="1" max="4" class="rounded-md border border-zinc-300 px-3 py-2"></label>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="grid gap-1 text-sm"><span>Latitude</span><input name="latitude" value="{{ old('latitude') }}" class="rounded-md border border-zinc-300 px-3 py-2"></label>
                <label class="grid gap-1 text-sm"><span>Longitude</span><input name="longitude" value="{{ old('longitude') }}" class="rounded-md border border-zinc-300 px-3 py-2"></label>
            </div>
            <div class="rounded-md bg-emerald-50 p-3 text-sm text-emerald-800">Preporučeno: 3 splittera x 4 porta = 12 korisnika po ormariću.</div>
            <button class="rounded-md bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-700">Sačuvaj ormarić</button>
        </div>
    </form>

    <div class="app-table-card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <tr><th class="px-4 py-3">Ormarić</th><th>Projekat</th><th>ODF</th><th>Kapacitet</th><th>Zauzeto</th><th>Status</th><th>Akcije</th></tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse($cabinets as $cabinet)
                        <tr class="transition hover:bg-blue-50/50">
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
                            <td>
                                <details class="inline-block align-middle">
                                    <summary class="cursor-pointer rounded-md bg-amber-50 px-2.5 py-1.5 text-xs font-semibold text-amber-700 hover:bg-amber-100">Uredi</summary>
                                    <form method="POST" action="{{ route('cabinets.update', $cabinet->id) }}" class="absolute z-20 mt-2 grid min-w-72 gap-2 rounded-md border border-slate-200 bg-white p-3 shadow-xl">
                                        @csrf
                                        @method('PATCH')
                                        <label class="grid gap-1 text-xs"><span>Projekat</span><select name="project_id" class="rounded-md border border-slate-300 px-2 py-1.5">@foreach($projects as $project)<option value="{{ $project->id }}" @selected($cabinet->project_id === $project->id)>{{ $project->name }}</option>@endforeach</select></label>
                                        <label class="grid gap-1 text-xs"><span>ODF</span><select name="odf_id" class="rounded-md border border-slate-300 px-2 py-1.5"><option value="">Bez ODF veze</option>@foreach($odfs as $odf)<option value="{{ $odf->id }}" @selected($cabinet->odf_id === $odf->id)>{{ $odf->name }}</option>@endforeach</select></label>
                                        <label class="grid gap-1 text-xs"><span>Naziv</span><input name="name" value="{{ $cabinet->name }}" class="rounded-md border border-slate-300 px-2 py-1.5"></label>
                                        <label class="grid gap-1 text-xs"><span>Adresa</span><input name="address" value="{{ $cabinet->address }}" class="rounded-md border border-slate-300 px-2 py-1.5"></label>
                                        <div class="grid grid-cols-2 gap-2"><label class="grid gap-1 text-xs"><span>Splitteri</span><input type="number" name="splitter_count" value="{{ $cabinet->splitter_count }}" min="1" max="3" class="rounded-md border border-slate-300 px-2 py-1.5"></label><label class="grid gap-1 text-xs"><span>Portovi</span><input type="number" name="ports_per_splitter" value="{{ $cabinet->ports_per_splitter }}" min="1" max="4" class="rounded-md border border-slate-300 px-2 py-1.5"></label></div>
                                        <div class="grid grid-cols-2 gap-2"><label class="grid gap-1 text-xs"><span>Lat</span><input name="latitude" value="{{ $cabinet->latitude }}" class="rounded-md border border-slate-300 px-2 py-1.5"></label><label class="grid gap-1 text-xs"><span>Lng</span><input name="longitude" value="{{ $cabinet->longitude }}" class="rounded-md border border-slate-300 px-2 py-1.5"></label></div>
                                        <button class="rounded-md bg-amber-600 px-3 py-2 text-xs font-semibold text-white">Sačuvaj izmjene</button>
                                    </form>
                                </details>
                                <form method="POST" action="{{ route('cabinets.delete', $cabinet->id) }}" style="display:inline;" data-confirm-delete="Sigurno obrisati ovaj ODO ormarić?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rounded-md bg-red-50 px-2.5 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">Obriši</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-8 text-center text-zinc-500" colspan="7">Nema ormarića. Dodaj prvi ormarić kroz formu lijevo.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-100 bg-slate-50/60 px-4 py-3">{{ $cabinets->links() }}</div>
    </div>
</section>
@endsection
