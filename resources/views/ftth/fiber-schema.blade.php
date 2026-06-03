@extends('ftth.layout')

@section('title', 'Fiber sema')
@section('subtitle', 'Hijerarhijski pregled projekta: ODF, ODO ormarići, portovi i trase.')

@section('content')
<section class="grid gap-4">
@forelse($projects as $project)
    <article class="rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
            <div><h2 class="font-bold">{{ $project->name }}</h2><p class="text-xs text-slate-500">{{ $project->code }} / {{ $project->location }}</p></div>
            <a href="{{ route('map.dashboard') }}" class="rounded-md bg-blue-600 px-3 py-2 text-xs font-semibold text-white">Otvori mapu</a>
        </div>
        <div class="grid gap-3 p-5 lg:grid-cols-2">
        @forelse($project->odfs as $odf)
            <section class="rounded-lg border border-blue-100 bg-blue-50/50 p-4">
                <h3 class="font-bold text-blue-800">ODF: {{ $odf->name }}</h3>
                <p class="mt-1 text-xs text-slate-500">{{ $odf->address }} / {{ $odf->port_count }} portova / {{ $odf->fiber_capacity }} vlakana</p>
                <div class="mt-3 grid gap-2">
                @forelse($odf->cabinets as $cabinet)
                    <div class="rounded-md border border-emerald-100 bg-white p-3 text-sm"><div class="flex justify-between gap-3"><b class="text-emerald-700">ODO: {{ $cabinet->name }}</b><span>{{ $cabinet->houses_count }}/{{ $cabinet->capacity }} kuća</span></div><div class="mt-1 text-xs text-slate-500">{{ $cabinet->splitter_count }} splittera 1:4 / {{ $cabinet->subscribers_count }} aktivnih korisnika</div></div>
                @empty
                    <p class="text-sm text-slate-500">ODF još nema povezane ODO ormariće.</p>
                @endforelse
                </div>
            </section>
        @empty
            <p class="text-sm text-slate-500">Projekat još nema ODF lokaciju.</p>
        @endforelse
        </div>
        <div class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">Trase: {{ $project->routes->count() }} / Ukupna dužina: {{ number_format($project->routes->sum('duct_length_m')) }} m</div>
    </article>
@empty
    <div class="rounded-xl border border-slate-200 bg-white p-8 text-center text-slate-500">Nema projekata.</div>
@endforelse
</section>
@endsection
