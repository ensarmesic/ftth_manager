@extends('ftth.layout')
@section('title', 'Provjera projekta')
@section('subtitle', $selectedProject ? 'Provjera projekta '.$selectedProject->name : 'Automatska kontrola veza, kapaciteta i tehničkih podataka svih projekata')
@section('content')
@php
    $n = fn ($value) => number_format((float) $value, 0, ',', '.');
    $levelLabels = ['error' => 'Greška', 'warning' => 'Upozorenje', 'info' => 'Napomena'];
    $typeLabels = ['project' => 'Projekat', 'odf' => 'ODF', 'cabinet' => 'ODO', 'house' => 'Kuće', 'route' => 'Trase'];
    $plural = fn (int $count, string $one, string $few, string $many) => $count % 10 === 1 && $count % 100 !== 11 ? $one : (in_array($count % 10, [2, 3, 4], true) && ! in_array($count % 100, [12, 13, 14], true) ? $few : $many);
    $mapLink = fn ($project, array $item) => $item['element_type'] === 'project'
        ? route('map.dashboard', ['project' => $project->id])
        : route('map.dashboard', ['project' => $project->id, 'focus_type' => $item['element_type'], 'focus_id' => $item['element_id']]);
    $rowLimit = 300;
@endphp
<div class="reports-page check-page">

    <section class="rp-toolbar">
        <form method="GET" class="rp-project-picker">
            <label for="pc-project">Provjera za</label>
            <select id="pc-project" name="project" data-auto-submit>
                <option value="">Svi projekti (pregled)</option>
                @foreach($projects as $project)
                    <option value="{{ $project->id }}" @selected($selectedProject?->id === $project->id)>{{ $project->name }} · {{ $project->code }}</option>
                @endforeach
            </select>
            <noscript><button type="submit" class="rp-btn">Prikaži</button></noscript>
        </form>
        <div class="rp-toolbar-actions">
            @if($selectedProject)
                <a href="{{ route('project-check.index') }}" class="rp-btn">← Svi projekti</a>
                <a href="{{ route('reports.index', ['project' => $selectedProject->id]) }}" class="rp-btn">Izvještaj</a>
                <a href="{{ route('map.dashboard', ['project' => $selectedProject->id]) }}" class="rp-btn rp-btn-dark">Otvori mapu</a>
            @endif
        </div>
    </section>

@if(! $selectedProject)
    {{-- ═════════════ SVI PROJEKTI ═════════════ --}}
    @php
        $rows = collect($overview);
        $errorTotal = $rows->sum(fn ($row) => $row['health']['errors']);
        $warningTotal = $rows->sum(fn ($row) => $row['health']['warnings']);
        $clean = $rows->filter(fn ($row) => $row['health']['issues'] === 0)->count();
        $rows = $rows->sortByDesc(fn ($row) => [$row['health']['errors'], $row['health']['warnings']])->values();
    @endphp
    <section class="rp-kpis">
        <div class="rp-kpi"><small>Projekti</small><b>{{ $n($rows->count()) }}</b><span>provjereno</span></div>
        <div class="rp-kpi is-good"><small>Bez problema</small><b>{{ $n($clean) }}</b><span>nema grešaka ni upozorenja</span></div>
        <div class="rp-kpi {{ $errorTotal ? 'is-bad' : 'is-good' }}"><small>Greške</small><b>{{ $n($errorTotal) }}</b><span>treba ispraviti</span></div>
        <div class="rp-kpi {{ $warningTotal ? 'is-warn' : 'is-good' }}"><small>Upozorenja</small><b>{{ $n($warningTotal) }}</b><span>treba pregledati</span></div>
    </section>

    <section class="rp-card">
        <div class="rp-card-hd"><div><h2>Projekti</h2><p>Projekti s najviše grešaka su prvi. Klikni projekat za detaljnu provjeru.</p></div></div>
        @if($rows->count())
        <div class="rp-table-wrap">
            <table class="rp-table">
                <thead><tr><th>Projekat</th><th>Stanje</th><th class="num">Greške</th><th class="num">Upozorenja</th><th class="num">Napomene</th><th>Najčešće</th><th></th></tr></thead>
                <tbody>
                @foreach($rows as $row)
                    @php
                        $project = $row['project'];
                        $h = $row['health'];
                    @endphp
                    <tr class="rp-row-link" data-href="{{ route('project-check.index', ['project' => $project->id]) }}">
                        <td><a href="{{ route('project-check.index', ['project' => $project->id]) }}" class="rp-project-name">{{ $project->name }}</a><small>{{ $project->code }} · {{ $n($project->odfs_count) }} ODF · {{ $n($project->cabinets_count) }} ODO · {{ $n($project->houses_count) }} kuća · {{ $n($project->network_routes_count) }} trasa</small></td>
                        <td>
                            @if($h['errors'])<span class="rp-pill is-bad">Ima grešaka</span>
                            @elseif($h['warnings'])<span class="rp-pill is-warn">Za pregled</span>
                            @else<span class="rp-pill is-good">Uredno</span>@endif
                        </td>
                        <td class="num {{ $h['errors'] ? 'pc-num-bad' : '' }}">{{ $n($h['errors']) }}</td>
                        <td class="num {{ $h['warnings'] ? 'pc-num-warn' : '' }}">{{ $n($h['warnings']) }}</td>
                        <td class="num">{{ $n($h['infos']) }}</td>
                        <td class="pc-top">
                            @forelse($row['top'] as $group)
                                <span class="pc-top-item is-{{ $group['level'] }}"><i></i>{{ $group['label'] }} <b>{{ $n($group['count']) }}</b></span>
                            @empty
                                <span class="pc-muted">—</span>
                            @endforelse
                        </td>
                        <td><a href="{{ route('project-check.index', ['project' => $project->id]) }}" class="rp-link">Provjeri →</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @else
            @include('ftth.partials.empty-state', ['title' => 'Nema projekata', 'message' => 'Provjera projekata će biti dostupna kada dodate prvi projekat.'])
        @endif
    </section>

@else
    {{-- ═════════════ JEDAN PROJEKAT ═════════════ --}}
    @php
        $typeCounts = collect($result['items'])->countBy('element_type');
        $state = $result['errors'] ? 'is-bad' : ($result['warnings'] ? 'is-warn' : 'is-good');
    @endphp
    <section class="rp-hero pc-hero {{ $state }}">
        <div class="rp-hero-main">
            <small>Provjera projekta</small>
            <h2>{{ $selectedProject->name }}</h2>
            <p>{{ $selectedProject->code }} · {{ $n($selectedProject->odfs_count) }} ODF · {{ $n($selectedProject->cabinets_count) }} ODO · {{ $n($selectedProject->houses_count) }} kuća · {{ $n($selectedProject->network_routes_count) }} trasa</p>
        </div>
        <div class="pc-verdict">
            @if($result['errors'])
                <b>{{ $n($result['errors']) }} {{ $plural($result['errors'], 'greška', 'greške', 'grešaka') }}</b><span>Projekat nije spreman dok se greške ne isprave.</span>
            @elseif($result['warnings'])
                <b>Nema grešaka</b><span>{{ $n($result['warnings']) }} {{ $plural($result['warnings'], 'upozorenje', 'upozorenja', 'upozorenja') }} za pregled.</span>
            @else
                <b>Sve uredno</b><span>Projekat je prošao sve provjere.</span>
            @endif
        </div>
    </section>

    @if(count($groups))
    <section class="pc-filters" aria-label="Filter provjere">
        <div class="pc-chips" role="group" aria-label="Nivo">
            <button type="button" class="pc-chip is-active" data-pc-level="all">Sve <b>{{ $n(count($result['items'])) }}</b></button>
            @if($result['errors'])<button type="button" class="pc-chip is-error" data-pc-level="error">Greške <b>{{ $n($result['errors']) }}</b></button>@endif
            @if($result['warnings'])<button type="button" class="pc-chip is-warning" data-pc-level="warning">Upozorenja <b>{{ $n($result['warnings']) }}</b></button>@endif
            @if($result['infos'])<button type="button" class="pc-chip is-info" data-pc-level="info">Napomene <b>{{ $n($result['infos']) }}</b></button>@endif
        </div>
        <div class="pc-chips" role="group" aria-label="Element">
            <button type="button" class="pc-chip is-active" data-pc-type="all">Svi elementi</button>
            @foreach($typeLabels as $type => $label)
                @if($typeCounts[$type] ?? 0)<button type="button" class="pc-chip" data-pc-type="{{ $type }}">{{ $label }} <b>{{ $n($typeCounts[$type]) }}</b></button>@endif
            @endforeach
        </div>
        <input type="search" id="pc-search" class="pc-search" placeholder="Traži npr. K-006 ili ZO-001" aria-label="Pretraga stavki">
    </section>

    <section class="pc-groups">
        @foreach($groups as $group)
            <details class="pc-group is-{{ $group['level'] }}" data-level="{{ $group['level'] }}" data-type="{{ $group['element_type'] }}" @if($group['level'] === 'error' || count($groups) <= 3) open @endif>
                <summary>
                    <i class="pc-dot"></i>
                    <span class="pc-group-title">
                        <b>{{ $group['label'] }}</b>
                        @if($group['recommendation'])<small>{{ $group['recommendation'] }}</small>@endif
                    </span>
                    <span class="pc-group-meta">
                        <em>{{ $levelLabels[$group['level']] ?? '' }}</em>
                        <span class="pc-type">{{ $typeLabels[$group['element_type']] ?? $group['element_type'] }}</span>
                        <strong class="pc-count" data-total="{{ $group['count'] }}">{{ $n($group['count']) }}</strong>
                    </span>
                </summary>
                <ul class="pc-items">
                    @foreach(array_slice($group['items'], 0, $rowLimit) as $item)
                        <li data-text="{{ mb_strtolower($item['message'].' '.$item['recommendation']) }}">
                            <span class="pc-item-text">{{ $item['message'] }}@unless($group['recommendation'])<small>{{ $item['recommendation'] }}</small>@endunless</span>
                            <a href="{{ $mapLink($selectedProject, $item) }}" class="pc-map">Na mapi →</a>
                        </li>
                    @endforeach
                </ul>
                @if($group['count'] > $rowLimit)
                    <p class="pc-more">Prikazano prvih {{ $rowLimit }} od {{ $n($group['count']) }}. Ispravi ove pa osvježi stranicu.</p>
                @endif
            </details>
        @endforeach
        <p class="rp-empty pc-no-match" hidden>Nijedna stavka ne odgovara filteru.</p>
    </section>
    @else
        <section class="rp-card"><div class="rp-note is-good">Nisu pronađene greške ni upozorenja. Projekat je tehnički ispravan.</div></section>
    @endif
@endif
</div>

<script nonce="{{ Vite::cspNonce() }}">
    document.querySelectorAll('.rp-row-link').forEach((row) => row.addEventListener('click', (event) => {
        if (!event.target.closest('a, button')) window.location.href = row.dataset.href;
    }));
    (() => {
        const groups = [...document.querySelectorAll('.pc-group')];
        if (!groups.length) return;
        const state = { level: 'all', type: 'all', text: '' };
        const noMatch = document.querySelector('.pc-no-match');
        const apply = () => {
            let shown = 0;
            groups.forEach((group) => {
                const fits = (state.level === 'all' || group.dataset.level === state.level) && (state.type === 'all' || group.dataset.type === state.type);
                let visible = 0;
                group.querySelectorAll('.pc-items li').forEach((item) => {
                    const match = fits && (!state.text || item.dataset.text.includes(state.text));
                    item.hidden = !match;
                    if (match) visible++;
                });
                const count = group.querySelector('.pc-count');
                count.textContent = state.text ? `${visible} / ${count.dataset.total}` : Number(count.dataset.total).toLocaleString('bs');
                group.hidden = !fits || (state.text && visible === 0);
                if (!group.hidden) shown++;
                if (state.text && visible) group.open = true;
            });
            if (noMatch) noMatch.hidden = shown > 0;
        };
        const bind = (attr, key) => document.querySelectorAll(`[data-pc-${attr}]`).forEach((chip) => chip.addEventListener('click', () => {
            state[key] = chip.dataset[`pc${attr[0].toUpperCase()}${attr.slice(1)}`];
            document.querySelectorAll(`[data-pc-${attr}]`).forEach((other) => other.classList.toggle('is-active', other === chip));
            apply();
        }));
        bind('level', 'level');
        bind('type', 'type');
        let timer;
        document.getElementById('pc-search')?.addEventListener('input', (event) => {
            clearTimeout(timer);
            timer = setTimeout(() => { state.text = event.target.value.trim().toLowerCase(); apply(); }, 150);
        });
    })();
</script>
@endsection
