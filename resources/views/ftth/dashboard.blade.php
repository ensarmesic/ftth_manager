@extends('ftth.layout')
@section('title', 'Kontrolni centar')
@section('subtitle', 'Operativni pregled FTTH infrastrukture i projektnih rizika.')
@section('content')
@php
    $current = $projectCards->first();
    $openIssues = $attentionProjects->sum('issues');
    $icons = [
        'odf' => '<svg viewBox="0 0 24 24"><path d="M7 3h10v13H7zM9 6h6M9 9h6M9 12h6M5 20h14M9 16v4m6-4v4"/></svg>',
        'cabinet' => '<svg viewBox="0 0 24 24"><path d="M6 3h12v18H6zM9 6h6v12H9zM12 9v6M15 12h.01"/></svg>',
        'house' => '<svg viewBox="0 0 24 24"><path d="m3 11 9-8 9 8M5 10v11h14V10M9 21v-7h6v7"/></svg>',
        'route' => '<svg viewBox="0 0 24 24"><path d="m7 17 10-10M8 4l12 12-4 4L4 8zM9 7l2 2m1-5 2 2m3 7 2 2m-8 2 2 2"/></svg>',
        'alert' => '<svg viewBox="0 0 24 24"><path d="M12 3 2 21h20L12 3zM12 9v5m0 3h.01"/></svg>',
    ];
@endphp

<div class="cc-dashboard">
    <header class="cc-heading">
        <div><p>FTTH OPERATIVNI PREGLED</p><h1>Kontrolni centar</h1></div>
        <svg class="cc-network" viewBox="0 0 660 90" aria-hidden="true">
            <defs><filter id="ccGlow"><feGaussianBlur stdDeviation="3" result="blur"/><feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge></filter></defs>
            <path d="M4 70C90 68 100 28 185 45s105 9 158-17 104 48 170 6 102 16 143 5"/>
            <path class="dash" d="M122 61c82-5 73-44 155-32s92 45 170 16 97 1 150 10"/>
            @foreach([122,205,281,355,430,510,585] as $x)<circle cx="{{ $x }}" cy="{{ [61,42,48,26,54,31,47][$loop->index] }}" r="5"/>@endforeach
            <circle class="cc-pulse" r="5"><animateMotion dur="5s" repeatCount="indefinite" path="M4 70C90 68 100 28 185 45s105 9 158-17 104 48 170 6 102 16 143 5"/></circle>
            <circle class="cc-pulse second" r="4"><animateMotion dur="6.5s" begin="-3s" repeatCount="indefinite" path="M122 61c82-5 73-44 155-32s92 45 170 16 97 1 150 10"/></circle>
        </svg>
    </header>

    <section class="cc-kpis">
        @foreach([
            ['odf','ODF CENTRI',$stats['odfs'],'Projektovano','blue'],
            ['cabinet','ODO ORMARIĆI',$stats['cabinets'],'Projektovano','green'],
            ['house','KUĆE / OBJEKTI',number_format($stats['houses']),'Obuhvaćeno','violet'],
            ['route','PROJEKTOVANA TRASA',number_format($stats['route_km'],2).' km','Ukupna dužina','orange'],
            ['alert','OTVORENE STAVKE',$openIssues,'Zahtijeva pažnju','red'],
        ] as [$icon,$label,$value,$hint,$tone])
            <article class="cc-kpi"><span class="cc-kpi-icon {{ $tone }}">{!! $icons[$icon] !!}</span><div><small>{{ $label }}</small><strong>{{ $value }}</strong><span>{{ $hint }}</span></div></article>
        @endforeach
    </section>

    <section class="cc-active">
        @if($current)
            <div class="cc-avatar">{{ strtoupper(substr($current['model']->name, 0, 2)) }}</div>
            <div class="cc-active-name"><small>AKTIVNI PROJEKAT</small><strong>{{ $current['model']->name }}</strong><span>{{ $current['model']->code }} · {{ $current['model']->location }}</span></div>
            <div class="cc-active-stats"><span><b>{{ $current['model']->odfs_count }}</b>ODF</span><span><b>{{ $current['model']->routes_count }}</b>TRASA</span><span><b>{{ $current['model']->cabinets_count }}</b>ODO</span><span class="warn"><b>{{ $current['issues'] }}</b>NAPOMENA</span></div>
            <div class="cc-active-actions"><a class="primary" href="{{ route('map.dashboard', ['project' => $current['model']->id]) }}">Otvori na mapi <b>›</b></a><a href="{{ route('projects.show', $current['model']) }}">Pregled projekta</a></div>
        @else
            <div class="cc-active-name"><small>PRVI KORAK</small><strong>Kreiraj prvi FTTH projekat</strong></div><div class="cc-active-actions"><a class="primary" href="{{ route('projects.index') }}">Novi projekat <b>+</b></a></div>
        @endif
    </section>

    <section class="cc-main">
        <article class="cc-card cc-portfolio">
            <header><h2><span>▱</span> PROJEKTNI PORTFOLIO</h2><a href="{{ route('projects.index') }}">Prikaži sve projekte →</a></header>
            <div class="cc-table-wrap"><table><thead><tr><th>PROJEKAT</th><th>INFRASTRUKTURA</th><th>KAPACITET</th><th>STATUS</th><th></th></tr></thead><tbody>
            @forelse($projectCards as $item)
                @php($project = $item['model'])
                <tr>
                    <td><div class="cc-project"><i>{{ strtoupper(substr($project->name,0,2)) }}</i><div><a href="{{ route('projects.show',$project) }}">{{ $project->name }}</a><span>{{ $project->code }} · {{ $project->location }}</span></div></div></td>
                    <td><div class="cc-infra"><span><b>{{ $project->odfs_count }}</b> ODF</span><span><b>{{ $project->routes_count }}</b> TRASA</span><span><b>{{ $project->cabinets_count }}</b> ODO</span></div></td>
                    <td><div class="cc-cap"><span>{{ $item['used'] }} / {{ $item['capacity'] }} portova <b>{{ $item['utilization'] }}%</b></span><i><b style="width:{{ $item['utilization'] }}%"></b></i></div></td>
                    <td>@if($item['issues'])<span class="cc-status warning">{{ $item['issues'] }} {{ $item['issues']===1?'problem':'problema' }}</span>@else<span class="cc-status ok">Uredno</span>@endif</td>
                    <td><a class="cc-arrow" href="{{ route('map.dashboard',['project'=>$project->id]) }}">›</a></td>
                </tr>
            @empty<tr><td colspan="5">Nema kreiranih projekata.</td></tr>@endforelse
            </tbody></table></div>
        </article>

        <aside class="cc-side">
            <article class="cc-card cc-attention"><header><h2>ZAHTIJEVA PAŽNJU</h2><b>{{ $openIssues }}</b></header><div>
                @forelse($attentionProjects as $item)<a href="{{ route('project-check.index') }}"><i></i><span><b>{{ $item['model']->name }}</b><small>{{ $item['issues'] }} otvorenih stavki</small></span><strong>›</strong></a>@empty<div class="cc-clear">✓ Nema otvorenih nepravilnosti</div>@endforelse
            </div><footer><a href="{{ route('project-check.index') }}">Prikaži sve napomene →</a></footer></article>
            <article class="cc-card cc-quick"><header><h2>BRZI PRISTUP</h2></header><nav>
                @foreach([[route('odfs.index'),'▤','ODF centri','Centralna infrastruktura'],[route('cabinets.index'),'▣','ODO ormarići','Distribucioni ormarići'],[route('fiber-schema.index'),'⌁','Fiber šema','Topologija mreže'],[route('reports.index'),'▧','Izvještaji','Analiza i izvještaji']] as [$url,$icon,$name,$hint])
                    <a href="{{ $url }}"><i>{{ $icon }}</i><span><b>{{ $name }}</b><small>{{ $hint }}</small></span><strong>›</strong></a>
                @endforeach
            </nav></article>
        </aside>
    </section>

    <section class="cc-main cc-continuity">
        <div class="cc-continuity-primary">
        <article class="cc-card cc-resume"><header><h2>NASTAVI GDJE SI STAO</h2><a href="{{ $current ? route('map.dashboard', ['project' => $current['model']->id]) : route('projects.index') }}">Otvori radni prostor →</a></header><div class="cc-continuity-body">
            @if($current)
                @php($readiness = max(0, 100 - min(100, $current['issues'] * 8)))
                <div class="cc-resume-project"><i>{{ strtoupper(substr($current['model']->name, 0, 2)) }}</i><div><b>{{ $current['model']->name }}</b><span>{{ $current['model']->code }} · {{ $current['model']->location }}</span></div><strong>{{ $readiness }}%</strong></div>
                <div class="cc-resume-meta"><span>Posljednja izmjena {{ $current['model']->updated_at->diffForHumans() }}</span><span class="{{ $current['issues'] ? 'warn' : 'ok' }}">{{ $current['issues'] }} otvorenih stavki</span></div>
                <div class="cc-progress" title="Procijenjena spremnost projekta {{ $readiness }}%"><i style="width:{{ $readiness }}%"></i></div>
            @else<span>Kreirajte prvi projekt da biste započeli vođeni tok.</span>@endif
        </div></article>
            <section class="cc-card cc-activity"><header><h2>POSLJEDNJE IZMJENE</h2><a href="{{ route('settings.index') }}">Sistemske postavke &rarr;</a></header><div class="cc-activity-grid">
                @forelse($recentActivity as $activity)<div class="cc-activity-item"><b><i class="method-{{ strtolower($activity->method) }}">{{ $activity->method }}</i><em>{{ $activity->status_code }}</em></b><span>{{ $activity->route_name ?: $activity->path }}</span><small>{{ $activity->user?->name ?? 'Sistem' }} · {{ $activity->created_at->diffForHumans() }}</small></div>@empty<p class="cc-activity-empty">Nema zabilježenih izmjena.</p>@endforelse
            </div></section>
        </div>
        <aside class="cc-side"><article class="cc-card cc-attention"><header><h2>POSLJEDNJE SIGURNE VERZIJE</h2></header><div>
            @forelse($latestSnapshots as $snapshot)<a href="{{ route('map.dashboard', ['project' => $snapshot->project_id]) }}"><i></i><span><b>{{ $snapshot->project?->name ?? 'Projekt' }}</b><small>{{ $snapshot->label }} · {{ $snapshot->created_at->diffForHumans() }}</small></span><strong>›</strong></a>@empty<div class="cc-clear">Još nema sačuvanih snapshot verzija.</div>@endforelse
        </div></article></aside>
    </section>

    <footer class="cc-footer"><span>© {{ date('Y') }} FTTH Manager · Vlasnički softver</span><span>Verzija {{ config('app.version') }}@if(config('app.deployed_at')) · {{ config('app.deployed_at') }}@endif</span></footer>
</div>
@endsection
