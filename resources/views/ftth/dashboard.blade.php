@extends('ftth.layout')

@section('title', 'Pregled mreze')
@section('subtitle', 'Kapaciteti, status projekata i upozorenja za popunjene ormarice.')

@section('content')
    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['label' => 'Projekti', 'value' => $stats['projects'], 'tone' => 'border-emerald-200'],
            ['label' => 'ODF lokacije', 'value' => $stats['odfs'], 'tone' => 'border-cyan-200'],
            ['label' => 'Zeleni ormarici', 'value' => $stats['cabinets'], 'tone' => 'border-lime-200'],
            ['label' => 'Kuce / prikljucci', 'value' => $stats['houses'], 'tone' => 'border-violet-200'],
            ['label' => 'Korisnici', 'value' => $stats['subscribers'], 'tone' => 'border-amber-200'],
            ['label' => 'Mikrocijevi', 'value' => number_format($stats['duct_m']).' m', 'tone' => 'border-sky-200'],
            ['label' => 'Opticki kabl', 'value' => number_format($stats['fiber_m']).' m', 'tone' => 'border-indigo-200'],
            ['label' => 'Procjena materijala', 'value' => number_format($stats['materials_cost'], 2).' KM', 'tone' => 'border-rose-200'],
        ] as $card)
            <article class="rounded-md border {{ $card['tone'] }} bg-white p-4 shadow-sm">
                <div class="text-sm font-medium text-zinc-500">{{ $card['label'] }}</div>
                <div class="mt-2 text-2xl font-semibold">{{ $card['value'] }}</div>
            </article>
        @endforeach
    </section>

    <section class="mt-6 grid gap-6 xl:grid-cols-[1.15fr_.85fr]">
        <div class="overflow-hidden rounded-md border border-zinc-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3">
                <h2 class="font-semibold">Projekti</h2>
                <a href="{{ route('projects.index') }}" class="text-sm font-medium text-emerald-700">Dodaj projekat</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-zinc-50 text-xs font-semibold uppercase text-zinc-500">
                        <tr><th class="px-4 py-3">Projekat</th><th>Lokacija</th><th>Status</th><th>ODF</th><th>Ormarici</th><th>Kuce</th><th>Korisnici</th></tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100">
                        @forelse ($projects as $project)
                            <tr class="hover:bg-zinc-50">
                                <td class="px-4 py-3">
                                    <div class="font-medium">{{ $project->name }}</div>
                                    <div class="text-xs text-zinc-500">{{ $project->code }}</div>
                                </td>
                                <td>{{ $project->location }}</td>
                                <td><span class="rounded-md bg-zinc-100 px-2 py-1 text-xs font-medium">{{ $project->status }}</span></td>
                                <td>{{ $project->odfs_count }}</td>
                                <td>{{ $project->cabinets_count }}</td>
                                <td>{{ $project->houses_count }}</td>
                                <td>{{ $project->subscribers_count }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-4 py-8 text-center text-zinc-500" colspan="7">Nema projekata. Kreni od stranice Projekti.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="overflow-hidden rounded-md border border-zinc-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3">
                <h2 class="font-semibold">Iskoristenost ormarica</h2>
                <a href="{{ route('cabinets.index') }}" class="text-sm font-medium text-emerald-700">Svi ormarici</a>
            </div>
            <div class="divide-y divide-zinc-100">
                @forelse ($cabinets as $cabinet)
                    <div class="p-4">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="font-medium">{{ $cabinet->name }}</div>
                                <div class="text-sm text-zinc-500">{{ $cabinet->project->name ?? 'Bez projekta' }}</div>
                            </div>
                            <span class="rounded-md px-2 py-1 text-sm font-semibold {{ $cabinet->utilization >= 100 ? 'bg-red-100 text-red-800' : ($cabinet->utilization >= 80 ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800') }}">
                                {{ $cabinet->used_ports }}/{{ $cabinet->capacity }}
                            </span>
                        </div>
                        <div class="mt-3 h-2 rounded bg-zinc-100">
                            <div class="h-2 rounded {{ $cabinet->utilization >= 100 ? 'bg-red-600' : ($cabinet->utilization >= 80 ? 'bg-amber-500' : 'bg-emerald-600') }}" style="width: {{ min($cabinet->utilization, 100) }}%"></div>
                        </div>
                        <div class="mt-2 flex justify-between text-xs text-zinc-500">
                            <span>{{ $cabinet->utilization }}% zauzeto</span>
                            @if ($cabinet->utilization >= 100)
                                <span class="font-medium text-red-700">Planirati novi ormaric</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="p-6 text-center text-sm text-zinc-500">Nema ormarica za prikaz.</div>
                @endforelse
            </div>
        </div>
    </section>
@endsection
