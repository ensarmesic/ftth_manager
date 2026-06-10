@extends('ftth.layout')

@section('title', 'Krakovi mreze')
@section('subtitle', 'Hijerarhija primarnih, sekundarnih i izvedenih krakova.')

@section('content')
<section class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-sm text-slate-500">Krakovi ukupno</div>
        <div class="mt-2 text-2xl font-semibold">{{ $branchStats['total'] }}</div>
    </article>
    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-sm text-slate-500">Primarni</div>
        <div class="mt-2 text-2xl font-semibold">{{ $branchStats['primary'] }}</div>
    </article>
    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-sm text-slate-500">Sekundarni</div>
        <div class="mt-2 text-2xl font-semibold">{{ $branchStats['secondary'] }}</div>
    </article>
    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-sm text-slate-500">ODO na krakovima</div>
        <div class="mt-2 text-2xl font-semibold">{{ $branchStats['cabinets'] }}</div>
    </article>
</section>

<section class="grid gap-6 xl:grid-cols-[420px_1fr]">
    <form method="POST" action="{{ route('branches.store') }}" class="rounded-md border border-zinc-200 bg-white p-5 shadow-sm">
        @csrf
        <h2 class="mb-4 font-semibold">Novi krak</h2>
        <div class="grid gap-4">
            <label class="grid gap-1 text-sm"><span>Projekat</span><select name="project_id" class="rounded-md border border-zinc-300 px-3 py-2" required><option value="">Odaberi projekat</option>@foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach</select></label>
            <label class="grid gap-1 text-sm"><span>ODF</span><select name="odf_id" class="rounded-md border border-zinc-300 px-3 py-2"><option value="">ODF nije odabran</option>@foreach($odfs as $odf)<option value="{{ $odf->id }}">{{ $odf->project->name }} / {{ $odf->name }}</option>@endforeach</select></label>
            <label class="grid gap-1 text-sm"><span>Roditeljski krak</span><select name="parent_branch_id" class="rounded-md border border-zinc-300 px-3 py-2"><option value="">Glavni krak</option>@foreach($parentBranches as $branch)<option value="{{ $branch->id }}">{{ $branch->project->name }} / {{ $branch->name }}</option>@endforeach</select></label>
            <label class="grid gap-1 text-sm"><span>Vezana trasa</span><select name="route_id" class="rounded-md border border-zinc-300 px-3 py-2"><option value="">Bez vezane trase</option>@foreach($routes as $route)<option value="{{ $route->id }}">{{ $route->project->name }} / {{ $route->name }}</option>@endforeach</select></label>
            <label class="grid gap-1 text-sm"><span>Naziv kraka</span><input name="name" class="rounded-md border border-zinc-300 px-3 py-2" placeholder="Sekundarni krak 1.1" required></label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="grid gap-1 text-sm"><span>Kod</span><input name="code" class="rounded-md border border-zinc-300 px-3 py-2" placeholder="1.1"></label>
                <label class="grid gap-1 text-sm"><span>Redoslijed</span><input name="sort_order" type="number" value="0" min="0" class="rounded-md border border-zinc-300 px-3 py-2"></label>
            </div>
            <label class="grid gap-1 text-sm"><span>Tip kraka</span><select name="type" class="rounded-md border border-zinc-300 px-3 py-2"><option value="secondary">Sekundarni</option><option value="primary">Primarni</option></select></label>
            <button class="rounded-md bg-blue-600 px-4 py-2 font-bold text-white">Sacuvaj krak</button>
        </div>
    </form>

    <div class="app-table-card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <tr><th class="px-4 py-3">Krak</th><th>ODF</th><th>Roditelj</th><th>Trasa</th><th>ODO</th><th>Akcije</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($branches as $branch)
                        <tr class="transition hover:bg-blue-50/50">
                            <td class="px-4 py-3"><b>{{ $branch->name }}</b><br><small>{{ $branch->code }} / {{ $branch->type }} / #{{ $branch->sort_order }}</small></td>
                            <td>{{ $branch->odf?->name ?? '-' }}</td>
                            <td>{{ $branch->parentBranch?->name ?? '-' }}</td>
                            <td>{{ $branch->route?->name ?? '-' }}</td>
                            <td>{{ $branch->cabinets_count }}</td>
                            <td>
                                <form method="POST" action="{{ route('branches.delete', $branch) }}" style="display:inline;" data-confirm-delete="Obrisati krak?">
                                    @csrf
                                    @method('DELETE')
                                    <button class="rounded-md bg-red-50 px-2.5 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">Obrisi</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">Nema krakova mreze.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-100 bg-slate-50/60 px-4 py-3">{{ $branches->links() }}</div>
    </div>
</section>
@endsection
