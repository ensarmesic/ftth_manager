@extends('ftth.layout')
@section('title', 'Provjera projekta')
@section('subtitle', 'Automatska kontrola veza, kapaciteta i tehničkih podataka projekta.')
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
        <article class="check-card">
            <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div>
                    <h2 class="font-bold text-slate-900">{{ $project->name }}</h2>
                    <p class="text-xs text-slate-500 mt-0.5">{{ $project->code }} &bull; {{ $project->location }}</p>
                </div>
                @if($checks->isEmpty())
                    <span class="ftth-badge green"><span class="ftth-badge-dot"></span>Sve uredu</span>
                @else
                    <span class="ftth-badge red"><span class="ftth-badge-dot"></span>{{ $checks->count() }} stavki</span>
                @endif
            </div>
            <div class="grid gap-2 p-5">
                @forelse($checks as [$level, $message])
                    <div class="check-item {{ $level === 'warning' ? 'warn' : $level }}">
                        <div class="check-item-dot"></div>
                        <span>{{ $message }}</span>
                    </div>
                @empty
                    <div class="check-item ok">
                        <div class="check-item-dot"></div>
                        <span>Nisu pronađene tehničke greške.</span>
                    </div>
                @endforelse
            </div>
        </article>
    @empty
        @include('ftth.partials.empty-state', ['title' => 'Nema projekata', 'message' => 'Provjera projekata će biti dostupna kada dodate prvi projekat.'])
    @endforelse
</section>
@endsection
