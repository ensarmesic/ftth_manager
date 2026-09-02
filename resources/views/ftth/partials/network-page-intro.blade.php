@php
    $pages = [
        'odf' => ['ODF centri', 'Polazna tačka', 'Definiši centralne distribucione tačke, kapacitet vlakana i portova.', 'odfs.index', 'blue'],
        'cabinet' => ['ODO ormarići', 'Distribucija', 'Pregledaj napajanje ormarića, pripadnost kraku i iskorištenost portova.', 'cabinets.index', 'green'],
        'house' => ['Kuće i objekti', 'Krajnje tačke', 'Pronađi objekte, provjeri vezu prema ODO-u i status planiranog priključka.', 'houses.index', 'violet'],
        'route' => ['Mrežne trase', 'Fizička mreža', 'Kontroliši tip, dužinu, kablove i mikrocijevi svake projektovane trase.', 'routes.index', 'orange'],
        'branch' => ['Krakovi mreže', 'Topologija', 'Organizuj primarne i sekundarne krakove te njihove veze s ODF-om i trasama.', 'branches.index', 'cyan'],
    ];
    [$pageTitle, $eyebrow, $description, $activeRoute, $tone] = $pages[$kind];
@endphp
<section class="network-page-intro tone-{{ $tone }}">
    <div class="network-page-intro-copy">
        <span>{{ $eyebrow }}</span>
        <h2>{{ $pageTitle }}</h2>
        <p>{{ $description }}</p>
    </div>
    <form class="network-project-filter" method="GET">
        @if(request('q'))<input type="hidden" name="q" value="{{ request('q') }}">@endif
        <label for="network-project-{{ $kind }}">Prikaz projekta</label>
        <div>
            <select id="network-project-{{ $kind }}" name="project" onchange="this.form.submit()">
                <option value="">Svi projekti</option>
                @foreach($projects as $project)
                    <option value="{{ $project->id }}" @selected($selectedProject?->id === $project->id)>{{ $project->name }} · {{ $project->code }}</option>
                @endforeach
            </select>
            @if($selectedProject)<span>Prikazani su samo podaci projekta <b>{{ $selectedProject->name }}</b></span>@else<span>Zajednički pregled svih projekata</span>@endif
        </div>
        @if($selectedProject)
            <div class="network-project-actions">
                <a href="{{ route('map.dashboard', ['project' => $selectedProject->id]) }}">Otvori mapu</a>
                <a href="{{ route('projects.show', $selectedProject) }}">Pregled projekta</a>
                <a href="{{ url()->current() }}">Prikaži sve</a>
            </div>
        @endif
    </form>
    <nav class="network-page-flow" aria-label="Dijelovi FTTH projekta">
        @foreach($pages as $key => $page)
            <a href="{{ route($page[3], $selectedProject ? ['project' => $selectedProject->id] : []) }}" class="{{ $key === $kind ? 'is-current' : '' }}">
                <i>{{ $loop->iteration }}</i><span><b>{{ $page[0] }}</b><small>{{ $page[1] }}</small></span>
            </a>
        @endforeach
    </nav>
</section>
@if($projectContext)
@php($contextProject = $projectContext['project'])
<section class="network-project-overview">
    <div class="network-project-identity">
        <i>{{ strtoupper(substr($contextProject->name, 0, 2)) }}</i>
        <span><small>AKTIVNI PROJEKAT</small><b>{{ $contextProject->name }}</b><em>{{ $contextProject->code }} · {{ $contextProject->location }}</em></span>
    </div>
    <div class="network-topology" aria-label="Topologija projekta">
        <span><b>{{ $contextProject->odfs_count }}</b><small>ODF</small></span><i>→</i>
        <span><b>{{ $contextProject->cabinets_count }}</b><small>ODO</small></span><i>→</i>
        <span><b>{{ $contextProject->houses_count }}</b><small>OBJEKATA</small></span>
    </div>
    <div class="network-project-metric"><small>MREŽNE TRASE</small><b>{{ number_format($projectContext['route_km'], 2) }} km</b><span>{{ $contextProject->network_routes_count }} segmenata</span></div>
    <div class="network-project-capacity">
        <div><small>ISKORIŠTENOST ODO</small><b>{{ $projectContext['used_ports'] }} / {{ $projectContext['capacity'] }}</b><em>{{ $projectContext['utilization'] }}%</em></div>
        <span><i style="width:{{ $projectContext['utilization'] }}%"></i></span>
    </div>
    <a class="network-project-health {{ $projectContext['issues'] ? 'has-issues' : 'is-clear' }}" href="{{ route('project-check.index', ['project' => $contextProject->id]) }}">
        <small>PROVJERA</small><b>{{ $projectContext['issues'] ?: 'Uredno' }}</b><span>{{ $projectContext['issues'] ? 'nepovezanih elemenata' : 'Nema osnovnih grešaka' }}</span>
    </a>
</section>
@endif
