@extends('ftth.layout')

@section('title', 'Splitteri')
@section('subtitle', 'Pregled zauzeća splittera i raspoloživih portova po ODO ormariću.')

@section('content')
<section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @php($capacity = $cabinets->sum(fn ($cabinet) => $cabinet->capacity))
    @php($used = $cabinets->sum('houses_count'))
    @foreach ([
        'ODO ormarići' => $cabinets->count(),
        'Splitteri 1:4' => $cabinets->sum('splitter_count'),
        'Ukupno portova' => $capacity,
        'Slobodni portovi' => max($capacity - $used, 0),
    ] as $label => $value)
        <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</div>
            <div class="mt-2 text-2xl font-bold text-slate-900">{{ $value }}</div>
        </article>
    @endforeach
</section>

<section class="mt-5 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
    <div class="border-b border-slate-200 px-5 py-4"><h2 class="font-semibold">Kapacitet po ODO-u</h2></div>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">ODO</th><th class="px-4 py-3">Projekat</th><th class="px-4 py-3">ODF</th><th class="px-4 py-3">Splitteri</th><th class="px-4 py-3">Kuće</th><th class="px-4 py-3">Portovi</th><th class="px-4 py-3">Zauzeće</th></tr></thead>
            <tbody>
            @forelse($cabinets as $cabinet)
                @php($usedPorts = $cabinet->houses_count)
                @php($percent = $cabinet->capacity ? min(round($usedPorts / $cabinet->capacity * 100), 100) : 0)
                <tr class="border-t border-slate-100">
                    <td class="px-4 py-3 font-semibold">{{ $cabinet->name }}</td><td class="px-4 py-3">{{ $cabinet->project->name }}</td><td class="px-4 py-3">{{ $cabinet->odf->name ?? '-' }}</td><td class="px-4 py-3">{{ $cabinet->splitter_count }} x 1:4</td><td class="px-4 py-3">{{ $usedPorts }}</td><td class="px-4 py-3">{{ $usedPorts }}/{{ $cabinet->capacity }}</td>
                    <td class="px-4 py-3"><div class="flex min-w-40 items-center gap-2"><div class="h-2 flex-1 rounded-full bg-slate-100"><div class="h-2 rounded-full {{ $percent > 90 ? 'bg-red-500' : ($percent > 70 ? 'bg-amber-500' : 'bg-emerald-500') }}" style="width:{{ $percent }}%"></div></div><b class="text-xs">{{ $percent }}%</b></div></td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">Nema evidentiranih ODO ormarića.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
