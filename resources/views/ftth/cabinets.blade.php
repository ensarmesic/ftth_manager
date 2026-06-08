@extends('ftth.layout')

@section('title', 'ODO ormarici')
@section('subtitle', 'Distribucione tacke koje se planiraju na sekundarnim krakovima.')

@section('content')
<section class="grid gap-6 xl:grid-cols-[420px_1fr]">
    <form method="POST" action="{{ route('cabinets.store') }}" class="rounded-md border border-zinc-200 bg-white p-5 shadow-sm">
        @csrf
        <h2 class="mb-4 font-semibold">Novi ormaric</h2>
        <div class="grid gap-4">
            <label class="grid gap-1 text-sm">
                <span>Projekat</span>
                <select name="project_id" class="rounded-md border border-zinc-300 px-3 py-2" required>
                    <option value="">Odaberi projekat</option>
                    @foreach($projects as $project)
                        <option value="{{ $project->id }}">{{ $project->name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="grid gap-1 text-sm">
                <span>ODF lokacija</span>
                <select name="odf_id" class="rounded-md border border-zinc-300 px-3 py-2">
                    <option value="">Bez direktne ODF veze</option>
                    @foreach($odfs as $odf)
                        <option value="{{ $odf->id }}">{{ $odf->name }} - {{ $odf->project->name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="grid gap-1 text-sm">
                <span>Napaja se iz ODO</span>
                <select name="parent_cabinet_id" class="rounded-md border border-zinc-300 px-3 py-2">
                    <option value="">Direktno iz ODF-a</option>
                    @foreach($parentCabinets as $parentCabinet)
                        <option value="{{ $parentCabinet->id }}">{{ $parentCabinet->name }} - {{ $parentCabinet->project->name }}</option>
                    @endforeach
                </select>
            </label>
            <div class="grid gap-3 sm:grid-cols-[1fr_110px]">
                <label class="grid gap-1 text-sm"><span>Krak</span><select name="branch_id" class="rounded-md border border-zinc-300 px-3 py-2"><option value="">Neraspoređen ODO</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->project->name }} / {{ $branch->name }}</option>@endforeach</select></label>
                <label class="grid gap-1 text-sm"><span>Redoslijed</span><input type="number" name="branch_order" value="0" min="0" class="rounded-md border border-zinc-300 px-3 py-2"></label>
            </div>

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

            <div class="rounded-md bg-emerald-50 p-3 text-sm text-emerald-800">Za izvedeni ormaric, npr. FTTH 2-1.1, odaberi roditeljski ODO FTTH 2-1.</div>
            <button class="rounded-md bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-700">Sacuvaj ormaric</button>
        </div>
    </form>

    <div class="app-table-card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <tr><th class="px-4 py-3">Ormaric</th><th>Projekat</th><th>Krak</th><th>Napajanje</th><th>Kapacitet</th><th>Kuce</th><th>Status</th><th>Akcije</th></tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse($cabinets as $cabinet)
                        <tr class="transition hover:bg-blue-50/50">
                            <td class="px-4 py-3 font-medium">
                                <div>{{ $cabinet->name }}</div>
                                @if($cabinet->childCabinets->isNotEmpty())
                                    <div class="text-xs text-slate-500">{{ $cabinet->childCabinets->count() }} izvedenih ODO</div>
                                @endif
                            </td>
                            <td>{{ $cabinet->project->name }}</td>
                            <td>{{ $cabinet->branch?->name ?? 'Neraspoređen' }}<br><small>#{{ $cabinet->branch_order }}</small></td>
                            <td>
                                <div class="font-semibold text-slate-800">{{ $cabinet->parentCabinet->name ?? ($cabinet->odf->name ?? '-') }}</div>
                                <div class="text-xs text-slate-500">{{ $cabinet->parentCabinet ? 'iz ODO ormara' : 'iz ODF-a' }}</div>
                            </td>
                            <td>{{ $cabinet->capacity }}</td>
                            <td class="min-w-40 pr-4">
                                <div class="flex items-center gap-2">
                                    <div class="h-2 w-24 rounded bg-zinc-100"><div class="h-2 rounded {{ $cabinet->houses_count >= $cabinet->capacity ? 'bg-red-600' : ($cabinet->houses_count / max($cabinet->capacity, 1) >= .8 ? 'bg-amber-500' : 'bg-emerald-600') }}" style="width: {{ min($cabinet->houses_count / max($cabinet->capacity, 1) * 100, 100) }}%"></div></div>
                                    <span>{{ $cabinet->houses_count }}/{{ $cabinet->capacity }}</span>
                                </div>
                            </td>
                            <td>
                                @php($utilization = round($cabinet->houses_count / max($cabinet->capacity, 1) * 100))
                                <span class="rounded-md px-2 py-1 text-xs font-semibold {{ $utilization >= 100 ? 'bg-red-100 text-red-800' : ($utilization >= 80 ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800') }}">{{ $utilization >= 100 ? 'Popunjen' : $utilization.'%' }}</span>
                            </td>
                            <td>
                                <details class="inline-block align-middle">
                                    <summary class="cursor-pointer rounded-md bg-amber-50 px-2.5 py-1.5 text-xs font-semibold text-amber-700 hover:bg-amber-100">Uredi</summary>
                                    <form method="POST" action="{{ route('cabinets.update', $cabinet->id) }}" class="absolute z-20 mt-2 grid min-w-72 gap-2 rounded-md border border-slate-200 bg-white p-3 shadow-xl">
                                        @csrf
                                        @method('PATCH')
                                        <label class="grid gap-1 text-xs"><span>Projekat</span><select name="project_id" class="rounded-md border border-slate-300 px-2 py-1.5">@foreach($projects as $project)<option value="{{ $project->id }}" @selected($cabinet->project_id === $project->id)>{{ $project->name }}</option>@endforeach</select></label>
                                        <label class="grid gap-1 text-xs"><span>ODF</span><select name="odf_id" class="rounded-md border border-slate-300 px-2 py-1.5"><option value="">Bez direktne ODF veze</option>@foreach($odfs as $odf)<option value="{{ $odf->id }}" @selected($cabinet->odf_id === $odf->id)>{{ $odf->name }}</option>@endforeach</select></label>
                                        <label class="grid gap-1 text-xs"><span>Napaja se iz ODO</span><select name="parent_cabinet_id" class="rounded-md border border-slate-300 px-2 py-1.5"><option value="">Direktno iz ODF-a</option>@foreach($parentCabinets as $parentCabinet)@continue($parentCabinet->id === $cabinet->id)<option value="{{ $parentCabinet->id }}" @selected($cabinet->parent_cabinet_id === $parentCabinet->id)>{{ $parentCabinet->name }} - {{ $parentCabinet->project->name }}</option>@endforeach</select></label>
                                        <label class="grid gap-1 text-xs"><span>Sekundarni krak</span><select name="branch_id" class="rounded-md border border-slate-300 px-2 py-1.5"><option value="">Nerasporeden ODO</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected($cabinet->branch_id === $branch->id)>{{ $branch->project->name }} / {{ $branch->name }}</option>@endforeach</select></label>
                                        <label class="grid gap-1 text-xs"><span>Redoslijed</span><input type="number" name="branch_order" value="{{ $cabinet->branch_order }}" min="0" class="rounded-md border border-slate-300 px-2 py-1.5"></label>
                                        <label class="grid gap-1 text-xs"><span>Naziv</span><input name="name" value="{{ $cabinet->name }}" class="rounded-md border border-slate-300 px-2 py-1.5"></label>
                                        <label class="grid gap-1 text-xs"><span>Adresa</span><input name="address" value="{{ $cabinet->address }}" class="rounded-md border border-slate-300 px-2 py-1.5"></label>
                                        <div class="grid grid-cols-2 gap-2"><label class="grid gap-1 text-xs"><span>Splitteri</span><input type="number" name="splitter_count" value="{{ $cabinet->splitter_count }}" min="1" max="3" class="rounded-md border border-slate-300 px-2 py-1.5"></label><label class="grid gap-1 text-xs"><span>Portovi</span><input type="number" name="ports_per_splitter" value="{{ $cabinet->ports_per_splitter }}" min="1" max="4" class="rounded-md border border-slate-300 px-2 py-1.5"></label></div>
                                        <div class="grid grid-cols-2 gap-2"><label class="grid gap-1 text-xs"><span>Lat</span><input name="latitude" value="{{ $cabinet->latitude }}" class="rounded-md border border-slate-300 px-2 py-1.5"></label><label class="grid gap-1 text-xs"><span>Lng</span><input name="longitude" value="{{ $cabinet->longitude }}" class="rounded-md border border-slate-300 px-2 py-1.5"></label></div>
                                        <button class="rounded-md bg-amber-600 px-3 py-2 text-xs font-semibold text-white">Sacuvaj izmjene</button>
                                    </form>
                                </details>
                                <form method="POST" action="{{ route('cabinets.delete', $cabinet->id) }}" style="display:inline;" data-confirm-delete="Sigurno obrisati ovaj ODO ormaric?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rounded-md bg-red-50 px-2.5 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">Obrisi</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-8 text-center text-zinc-500" colspan="7">Nema ormarica. Dodaj prvi ormaric kroz formu lijevo.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-100 bg-slate-50/60 px-4 py-3">{{ $cabinets->links() }}</div>
    </div>
</section>
@endsection
