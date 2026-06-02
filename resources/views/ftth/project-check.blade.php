@extends('ftth.layout')

@section('title', 'Provjera projekta')
@section('subtitle', 'Automatska kontrola veza, kapaciteta i tehnickih podataka projekta.')

@section('content')
<section class="grid gap-4">
@forelse($projects as $project)
    @php
        $checks = collect();
        if (!$project->odfs_count) $checks->push(['error', 'Nedostaje ODF lokacija.']);
        $project->cabinets->whereNull('odf_id')->each(fn ($cabinet) => $checks->push(['warning', "{$cabinet->name} nema povezani ODF."]));
        $project->cabinets->filter(fn ($cabinet) => $cabinet->houses_count > $cabinet->capacity)->each(fn ($cabinet) => $checks->push(['error', "{$cabinet->name} prelazi kapacitet {$cabinet->capacity}."]));
        $project->houses->whereNull('cabinet_id')->each(fn ($house) => $checks->push(['warning', "{$house->label} nema dodijeljeni ODO."]));
        $project->routes->filter(fn ($route) => !$route->microduct_type || !$route->fiber_count)->each(fn ($route) => $checks->push(['warning', "{$route->name} nema kompletne podatke o mikrocijevi ili kablu."]));
    @endphp
    <article class="rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
            <div><h2 class="font-bold">{{ $project->name }}</h2><p class="text-xs text-slate-500">{{ $project->code }} / {{ $project->location }}</p></div>
            <span class="rounded-full px-3 py-1 text-xs font-bold {{ $checks->isEmpty() ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-800' }}">{{ $checks->isEmpty() ? 'Spremno' : $checks->count().' stavki' }}</span>
        </div>
        <div class="grid gap-2 p-5">
        @forelse($checks as [$level, $message])
            <div class="rounded-md border px-3 py-2 text-sm {{ $level === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-amber-200 bg-amber-50 text-amber-800' }}">{{ $message }}</div>
        @empty
            <div class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">Nisu pronadjene tehnicke greske.</div>
        @endforelse
        </div>
    </article>
@empty
    <div class="rounded-xl border border-slate-200 bg-white p-8 text-center text-slate-500">Nema projekata za provjeru.</div>
@endforelse
</section>
@endsection
