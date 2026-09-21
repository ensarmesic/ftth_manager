@extends('ftth.layout')
@section('title', $project->name)
@section('subtitle', $project->code . ' · ' . ($project->location ?? ''))
@section('content')

<style>
    .project-overview { --card-border:#dbe5ef; }
    .standard-page:has(.project-overview) .ftth-page-header { display:none; }
    .project-breadcrumb { margin-bottom:12px; }
    .project-hero { position:relative; overflow:hidden; border:1px solid var(--card-border); background:linear-gradient(135deg,#fff 0%,#f8fbff 68%,#eefbf5 100%); box-shadow:0 14px 34px rgba(15,23,42,.07); }
    .project-hero::after { content:""; position:absolute; width:210px; height:210px; right:-90px; bottom:-145px; border-radius:999px; border:28px solid rgba(14,165,233,.07); pointer-events:none; }
    .project-health { position:relative; z-index:1; display:grid; min-width:170px; gap:6px; border:1px solid #dbe5ef; border-radius:12px; background:rgba(255,255,255,.82); padding:10px 12px; backdrop-filter:blur(8px); }
    .project-health-head { display:flex; align-items:baseline; justify-content:space-between; gap:12px; color:#64748b; font-size:9px; font-weight:850; letter-spacing:.07em; }
    .project-health-head b { color:#0f5270; font-size:19px; letter-spacing:-.03em; }
    .project-health-track { height:6px; overflow:hidden; border-radius:999px; background:#e7eef3; }.project-health-track i { display:block; height:100%; border-radius:inherit; background:linear-gradient(90deg,#0ea5e9,#22c55e); }
    .project-health small { color:#708396; font-size:9px; line-height:1.35; }
    .project-identity { display:flex; align-items:flex-start; gap:14px; }
    .project-monogram { display:grid; width:48px; height:48px; flex:none; place-items:center; border-radius:13px; color:#fff; font-size:14px; font-weight:900; background:linear-gradient(145deg,#0786bd,#075985); box-shadow:0 8px 18px rgba(7,89,133,.22); }
    .project-meta-line { display:flex; flex-wrap:wrap; gap:8px 18px; margin-top:8px; color:#64748b; font-size:12px; }
    .project-meta-line span { display:inline-flex; align-items:center; gap:6px; }
    .project-meta-line span::before { content:""; width:5px; height:5px; border-radius:999px; background:#94a3b8; }
    .project-kpis .stat-card { min-height:112px; padding:18px; border-color:var(--card-border); box-shadow:0 8px 22px rgba(15,23,42,.055); }
    .project-kpis .stat-label { font-size:11px; letter-spacing:.07em; }
    .project-kpis .stat-value { margin-top:7px; font-size:26px; line-height:1; }
    .project-command-grid { display:grid; grid-template-columns:minmax(0,1.35fr) minmax(360px,.85fr); gap:18px; align-items:start; }
    .project-column { display:flex; min-width:0; flex-direction:column; gap:18px; }
    .project-section-label { grid-row:1; }
    .project-column-network { grid-column:1; grid-row:2; }
    .project-column-resources { grid-column:2; grid-row:2; }
    .project-column-network, .project-column-resources { height:100%; }
    .project-column-network .project-card-branches,
    .project-column-resources .project-card-materials { flex:1; }
    .project-column-resources .project-card-occupancy { order:1; }
    .project-column-resources .project-card-materials { order:2; }
    .project-column-validation { display:contents; }
    .project-card-validation { grid-column:1/-1; grid-row:3; }
    .project-card-capacity { grid-column:1/-1; grid-row:4; }
    .project-card-capacity > .divide-y { display:grid; grid-template-columns:repeat(auto-fit,minmax(340px,1fr)); }
    .project-card-capacity > .divide-y > div { border-right:1px solid #eef2f7; }
    .project-card { overflow:hidden; border:1px solid var(--card-border); border-radius:16px; background:#fff; box-shadow:0 10px 25px rgba(15,23,42,.055); }
    .project-card > :first-child { min-height:52px; padding:15px 18px; background:linear-gradient(180deg,#fff,#fbfdff); }
    .project-card h2 { font-size:14px; color:#1e293b; }
    .project-card table { font-size:13px; }
    .project-card th { background:#f8fafc; font-size:11px; text-transform:uppercase; letter-spacing:.045em; }
    .project-card td, .project-card th { padding-top:11px; padding-bottom:11px; }
    .project-validation-list { max-height:360px; }
    .project-validation-item { position:relative; padding:13px 16px 13px 20px; background:#fff !important; }
    .project-validation-item::before { content:""; position:absolute; left:0; top:0; bottom:0; width:3px; background:#f59e0b; }
    .project-validation-item.is-error::before { background:#ef4444; }
    .project-validation-item:hover { background:#f8fafc !important; }
    .project-validation-item { display:block; text-decoration:none; }
    .project-validation-item .validation-open { margin-left:auto; flex:none; align-self:center; color:#0284c7; font-size:11px; font-weight:800; white-space:nowrap; }
    .validation-filters { display:flex; flex-wrap:wrap; gap:6px; }
    .validation-filter { min-height:28px; padding:4px 9px; border:1px solid #dbe5ef; border-radius:8px; background:#fff; color:#64748b; font-size:10px; font-weight:800; cursor:pointer; }
    .validation-filter:hover { border-color:#94a3b8; color:#334155; }
    .validation-filter.is-active { border-color:#0ea5e9; background:#eaf7fd; color:#03658e; box-shadow:0 0 0 2px rgba(14,165,233,.08); }
    .validation-summary-strip { display:flex; flex-wrap:wrap; gap:7px; padding:9px 16px; border-bottom:1px solid #eef2f7; background:#fbfdff; }
    .validation-summary-chip { display:inline-flex; align-items:center; gap:6px; padding:4px 8px; border:1px solid #e2e8f0; border-radius:999px; background:#fff; color:#64748b; font-size:10px; font-weight:750; }
    .validation-summary-chip b { color:#1e293b; }
    .validation-footer { display:flex; align-items:center; justify-content:space-between; gap:12px; min-height:48px; padding:9px 16px; border-top:1px solid #e2e8f0; background:#f8fafc; }
    .validation-more { padding:6px 10px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; color:#334155; font-size:11px; font-weight:800; }
    .validation-more:hover { border-color:#38bdf8; color:#03658e; }
    .quality-action { display:inline-flex; align-items:center; min-height:30px; padding:5px 10px; border:1px solid #f5c45e; border-radius:8px; background:#fff8e8; color:#9a5b00; font-size:10px; font-weight:850; cursor:pointer; }
    .quality-action:disabled { cursor:wait; opacity:.6; }
    .project-section-label { grid-column:1/-1; display:flex; align-items:center; gap:10px; margin:2px 0 -4px; color:#64748b; font-size:11px; font-weight:900; letter-spacing:.09em; text-transform:uppercase; }
    .project-section-label::after { content:""; height:1px; flex:1; background:#dbe5ef; }
    .project-validation-item .validation-message { font-size:13px; line-height:1.35; }
    .project-validation-item .validation-recommendation { font-size:12px; margin-top:4px; }
    @media (max-width:1100px) {
        .project-command-grid { grid-template-columns:1fr; }
        .project-section-label, .project-column-network, .project-column-resources { grid-column:1; grid-row:auto; }
        .project-column-network { order:1; }
        .project-column-resources { order:2; }
        .project-column-validation { display:contents; }
        .project-card-validation, .project-card-capacity { grid-column:1; grid-row:auto; }
        .project-card-validation { order:3; }
        .project-card-capacity { order:4; }
    }
    @media (max-width:640px) {
        .project-hero { padding:18px !important; }
        .project-monogram { width:42px; height:42px; }
        .project-actions { display:grid !important; width:100%; grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px; }
        .project-actions .tbl-btn { justify-content:center; min-height:38px; }
        .project-kpis { grid-template-columns:repeat(2,minmax(0,1fr)) !important; }
        .project-kpis .stat-card { min-height:96px; padding:14px; }
        .project-kpis .stat-value { font-size:22px; }
        .project-command-grid { gap:14px; }
        .project-card { border-radius:13px; }
    }
</style>

@php
    $statusLabels = ['planning' => 'Planiranje', 'active' => 'Aktivan', 'paused' => 'Pauziran', 'completed' => 'Završen'];
    $statusColors = ['active' => 'green', 'completed' => 'violet', 'planning' => 'amber', 'paused' => 'slate'];
    $statusColor  = $statusColors[$project->status] ?? 'sky';

    $valErrors   = $validationItems->where('level', 'error');
    $valWarnings = $validationItems->where('level', 'warning');
    $valOks      = $validationItems->where('level', 'ok');
    $validationByElement = $validationItems->whereIn('level', ['error', 'warning'])->groupBy('element_type')->map->count();

    $capacity   = $project->cabinets->sum(fn ($c) => $c->capacity);
    $usedPorts  = $project->cabinets->sum('houses_count');
    $freePorts  = max($capacity - $usedPorts, 0);
    $utilPct    = $capacity > 0 ? min(round($usedPorts / $capacity * 100), 100) : 0;
    $utilColor  = $utilPct >= 90 ? '#ef4444' : ($utilPct >= 70 ? '#f59e0b' : '#22c55e');

    $routeTypeLabels = ['backbone' => 'Backbone', 'feeder' => 'Feeder', 'distribution' => 'Distribucija', 'drop' => 'Drop', 'trench' => 'Glavni rov'];
    $healthScore = max(0, 100 - ($valErrors->count() * 15) - ($valWarnings->count() * 5));
    $healthNext = $valErrors->isNotEmpty() ? 'Prvo riješi greške validacije.' : ($valWarnings->isNotEmpty() ? 'Pregledaj i opravdaj upozorenja.' : 'Projekt je spreman za završnu stručnu kontrolu.');
@endphp

{{-- NAVIGACIJA NAZAD --}}
<div class="project-breadcrumb flex items-center gap-2 text-sm text-slate-400">
    <a href="{{ route('projects.index') }}" class="hover:text-slate-700">Projekti</a>
    <span>/</span>
    <span class="text-slate-700 font-medium">{{ $project->name }}</span>
</div>

{{-- HEADER PROJEKTA --}}
<div class="project-overview">
<div class="project-hero mb-5 flex flex-wrap items-start justify-between gap-5 rounded-2xl px-7 py-6">
    <div class="project-identity">
        <div class="project-monogram">{{ Illuminate\Support\Str::upper(Illuminate\Support\Str::substr($project->name, 0, 2)) }}</div>
        <div>
        <div class="flex flex-wrap items-center gap-2 mb-1">
            <h1 class="text-xl font-bold text-slate-900">{{ $project->name }}</h1>
            <span class="ftth-badge {{ $statusColor }}"><span class="ftth-badge-dot"></span>{{ $statusLabels[$project->status] ?? $project->status }}</span>
            @if($valErrors->count() > 0)
                <span class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700">
                    <span class="h-1.5 w-1.5 rounded-full bg-red-500"></span>{{ $valErrors->count() }} grešaka
                </span>
            @elseif($valWarnings->count() > 0)
                <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700">
                    <span class="h-1.5 w-1.5 rounded-full bg-amber-400"></span>{{ $valWarnings->count() }} upozorenja
                </span>
            @else
                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>OK
                </span>
            @endif
        </div>
        <div class="project-meta-line">
            <span>{{ $project->code }}</span>
            @if($project->location)<span>{{ $project->location }}</span>@endif
            @if($project->investor)<span>Investitor: {{ $project->investor }}</span>@endif
            @if($project->start_date)<span>Početak: {{ $project->start_date }}</span>@endif
            @if($project->deadline)<span>Rok: {{ $project->deadline }}</span>@endif
        </div>
        @if($project->description)
            <p class="mt-2 text-sm text-slate-500">{{ $project->description }}</p>
        @endif
        </div>
    </div>
    <div class="flex flex-wrap items-start justify-end gap-3 shrink-0">
    <div class="project-health" title="Heuristički health score na osnovu trenutnih grešaka i upozorenja">
        <div class="project-health-head"><span>PROJECT HEALTH</span><b>{{ $healthScore }}%</b></div>
        <div class="project-health-track"><i style="width:{{ $healthScore }}%;{{ $healthScore < 60 ? 'background:#ef4444' : ($healthScore < 85 ? 'background:#f59e0b' : '') }}"></i></div>
        <small>{{ $healthNext }}</small>
    </div>
    <div class="project-actions flex flex-wrap gap-2 shrink-0">
        <a href="{{ route('map.dashboard', ['project' => $project->id]) }}" class="tbl-btn" style="background:#eff6ff;color:#1e40af;border-color:#bfdbfe">
            <svg viewBox="0 0 16 16" fill="currentColor" class="w-3 h-3"><path fill-rule="evenodd" d="M8.879.879a2.25 2.25 0 00-1.757 0L.879 3.697A2.25 2.25 0 000 5.754v4.492a2.25 2.25 0 00.879 1.757l6.243 2.818a2.25 2.25 0 001.757 0l6.243-2.818A2.25 2.25 0 0016 10.246V5.754a2.25 2.25 0 00-.879-1.757L8.879.879z" clip-rule="evenodd"/></svg>
            Mapa
        </a>
        <a href="{{ route('projects.print', $project->id) }}" target="_blank" class="tbl-btn" style="background:#f0fdf4;color:#14532d;border-color:#bbf7d0">
            <svg viewBox="0 0 16 16" fill="currentColor" class="w-3 h-3"><path d="M3.75 1A1.75 1.75 0 002 2.75v1.5H1.75a.75.75 0 000 1.5H2v5.5c0 .966.784 1.75 1.75 1.75h8.5A1.75 1.75 0 0014 11.25v-5.5h.25a.75.75 0 000-1.5H14v-1.5A1.75 1.75 0 0012.25 1h-8.5zm0 1.5h8.5a.25.25 0 01.25.25V4.25H3.5V2.75a.25.25 0 01.25-.25zM3.5 11.25v-5.5h9v5.5a.25.25 0 01-.25.25h-8.5a.25.25 0 01-.25-.25zM6 8a.75.75 0 000 1.5h4a.75.75 0 000-1.5H6z"/></svg>
            Izvještaj
        </a>
        <a href="{{ route('reports.project-appendix', $project->id) }}" target="_blank" class="tbl-btn" style="background:#faf5ff;color:#6b21a8;border-color:#e9d5ff">
            <svg viewBox="0 0 16 16" fill="currentColor" class="w-3 h-3"><path fill-rule="evenodd" d="M4 2a2 2 0 012-2h4.586A2 2 0 0112 .586L15.414 4A2 2 0 0116 5.414V14a2 2 0 01-2 2H4a2 2 0 01-2-2V4a2 2 0 012-2zm4.5 7.25a.75.75 0 000 1.5h3.25a.75.75 0 000-1.5H8.5zm-3 0a.75.75 0 000 1.5h.5a.75.75 0 000-1.5h-.5zm.75-3.25a.75.75 0 01.75-.75h5.25a.75.75 0 010 1.5H7a.75.75 0 01-.75-.75zm-2-2A.75.75 0 015.5 3h5.25a.75.75 0 010 1.5H5.5A.75.75 0 014.75 4z" clip-rule="evenodd"/></svg>
            Prilog 3
        </a>
        <a href="{{ route('projects.fiber-schema-pdf', $project->id) }}" class="tbl-btn" style="background:#fff7ed;color:#9a3412;border-color:#fed7aa">
            <svg viewBox="0 0 16 16" fill="currentColor" class="w-3 h-3"><path d="M1 3a1 1 0 000 2h1.25v6.75a.75.75 0 001.5 0V5h9.5v6.75a.75.75 0 001.5 0V5H16a1 1 0 100-2H1z"/></svg>
            Fiber sema
        </a>
        <a href="#" data-dxf-export="{{ route('projects.dxf', $project->id) }}" class="tbl-btn dxf-export-btn" style="background:#fef3c7;color:#92400e;border-color:#fde68a">
            <svg viewBox="0 0 16 16" fill="currentColor" class="w-3 h-3"><path d="M7.47 10.78a.75.75 0 001.06 0l3.75-3.75a.75.75 0 00-1.06-1.06L8.75 8.44V1.75a.75.75 0 00-1.5 0v6.69L4.78 5.97a.75.75 0 00-1.06 1.06l3.75 3.75zM3.75 13a.75.75 0 000 1.5h8.5a.75.75 0 000-1.5h-8.5z"/></svg>
            DXF
        </a>
    </div>
    </div>
</div>

@php
    $workflowLabels = ['draft'=>'Nacrt','review'=>'Kontrola','approved'=>'Odobreno','built'=>'Izvedeno','as_built'=>'As-built'];
    $workflowNext = ['draft'=>['review'], 'review'=>['draft','approved'], 'approved'=>['review','built'], 'built'=>['approved','as_built'], 'as_built'=>['built']];
    $currentWorkflow = $project->workflow_stage ?: 'draft';
@endphp
<section class="project-card mb-5">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100">
        <div><h2>Projektni workflow</h2><p class="mt-1 text-xs text-slate-500">Trenutna faza: <b>{{ $workflowLabels[$currentWorkflow] }}</b>@if($project->workflowChangedBy) · {{ $project->workflowChangedBy->name }} · {{ $project->workflow_changed_at?->format('d.m.Y H:i') }}@endif</p></div>
        @can('project.edit')
        <form method="POST" action="{{ route('projects.workflow.update', $project) }}" class="flex flex-wrap items-end gap-2">@csrf @method('PATCH')
            <label class="grid gap-1 text-xs font-bold text-slate-600"><span>Sljedeća faza</span><select name="stage" class="rounded-lg border border-slate-300 px-3 py-2">@foreach($workflowNext[$currentWorkflow] as $stage)<option value="{{ $stage }}">{{ $workflowLabels[$stage] }}</option>@endforeach</select></label>
            <label class="grid gap-1 text-xs font-bold text-slate-600"><span>Napomena</span><input name="note" maxlength="500" class="rounded-lg border border-slate-300 px-3 py-2" placeholder="Razlog ili zapis kontrole"></label>
            <button class="tbl-btn h-9 bg-sky-700 text-white">Promijeni fazu</button>
        </form>
        @endcan
    </div>
    @if($project->stageHistories->isNotEmpty())<div class="flex flex-wrap gap-2 px-5 py-3">@foreach($project->stageHistories->take(8) as $change)<span class="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-600">{{ $workflowLabels[$change->from_stage] }} → <b>{{ $workflowLabels[$change->to_stage] }}</b> · {{ $change->user?->name ?? 'Sistem' }} · {{ $change->created_at->format('d.m.Y') }}@if($change->note) · {{ $change->note }}@endif</span>@endforeach</div>@endif
</section>

<div class="mb-5 grid gap-4 xl:grid-cols-2">
    <section class="project-card">
        <div class="border-b border-slate-100"><h2>Radni zadaci</h2><p class="mt-1 text-xs text-slate-500">Operativne obaveze, rokovi i odgovorne osobe.</p></div>
        @can('project.edit')<form method="POST" action="{{ route('projects.work-items.store', $project) }}" class="grid gap-2 border-b border-slate-100 p-4 sm:grid-cols-2">@csrf
            <input name="title" required maxlength="255" placeholder="Novi zadatak" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <select name="assigned_to" class="rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="">Bez zaduženja</option>@foreach($users as $member)<option value="{{ $member->id }}">{{ $member->name }}</option>@endforeach</select>
            <select name="priority" class="rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="normal">Normalan</option><option value="high">Visok</option><option value="urgent">Hitan</option><option value="low">Nizak</option></select>
            <input type="date" name="due_date" class="rounded-lg border border-slate-300 px-3 py-2 text-sm"><input type="hidden" name="status" value="open">
            <textarea name="description" maxlength="3000" placeholder="Opis zadatka" class="rounded-lg border border-slate-300 px-3 py-2 text-sm sm:col-span-2"></textarea>
            <button class="tbl-btn w-max bg-sky-700 text-white">Dodaj zadatak</button>
        </form>@endcan
        <div class="divide-y divide-slate-100">@forelse($project->workItems as $task)<article class="flex flex-wrap items-center justify-between gap-3 p-4"><div><b class="text-sm text-slate-800">{{ $task->title }}</b><p class="text-xs text-slate-500">{{ $task->assignee?->name ?? 'Nije zaduženo' }} · {{ $task->priority }}@if($task->due_date) · rok {{ $task->due_date->format('d.m.Y') }}@endif</p></div>@can('project.edit')<form method="POST" action="{{ route('projects.work-items.update', [$project, $task]) }}" class="flex gap-2">@csrf @method('PATCH')<input type="hidden" name="title" value="{{ $task->title }}"><input type="hidden" name="description" value="{{ $task->description }}"><input type="hidden" name="assigned_to" value="{{ $task->assigned_to }}"><input type="hidden" name="priority" value="{{ $task->priority }}"><input type="hidden" name="due_date" value="{{ $task->due_date?->toDateString() }}"><select name="status" class="rounded-lg border border-slate-300 px-2 py-1 text-xs" data-auto-submit>@foreach(['open'=>'Otvoren','in_progress'=>'U radu','blocked'=>'Blokiran','done'=>'Završen'] as $value=>$label)<option value="{{ $value }}" @selected($task->status===$value)>{{ $label }}</option>@endforeach</select></form>@endcan</article>@empty<p class="p-5 text-sm text-slate-500">Nema radnih zadataka.</p>@endforelse</div>
    </section>
    <section class="project-card">
        <div class="border-b border-slate-100"><h2>Komentari i prilozi</h2><p class="mt-1 text-xs text-slate-500">Projektne bilješke i terenska dokumentacija.</p></div>
        @can('project.edit')<form method="POST" action="{{ route('projects.comments.store', $project) }}" enctype="multipart/form-data" class="grid gap-2 border-b border-slate-100 p-4">@csrf<input type="hidden" name="subject_type" value="project"><textarea name="body" required maxlength="5000" placeholder="Napiši komentar…" class="rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea><div class="flex flex-wrap items-center gap-2"><input type="file" name="attachment" accept=".jpg,.jpeg,.png,.webp,.pdf,.txt,.csv" class="text-xs"><button class="tbl-btn bg-sky-700 text-white">Dodaj komentar</button></div></form>@endcan
        <div class="max-h-96 divide-y divide-slate-100 overflow-auto">@forelse($project->comments as $comment)<article class="p-4"><div class="flex justify-between gap-3"><b class="text-sm text-slate-800">{{ $comment->user?->name ?? 'Sistem' }}</b><small class="text-slate-400">{{ $comment->created_at->format('d.m.Y H:i') }}</small></div><p class="mt-2 whitespace-pre-line text-sm text-slate-600">{{ $comment->body }}</p>@if($comment->attachment_path)<a class="mt-2 inline-block text-xs font-bold text-sky-700" href="{{ route('projects.comments.download', [$project, $comment]) }}">Preuzmi: {{ $comment->attachment_name }}</a>@endif</article>@empty<p class="p-5 text-sm text-slate-500">Nema komentara.</p>@endforelse</div>
    </section>
</div>

<section class="project-card mb-5">
    <div class="border-b border-slate-100"><h2>Planirano i izvedeno stanje</h2><p class="mt-1 text-xs text-slate-500">Napredak se računa iz statusa mrežnih elemenata.</p></div>
    <div class="grid gap-4 p-5 md:grid-cols-3">@foreach([['Trase',$asBuilt['routes']['built'],$asBuilt['routes']['planned'],$asBuilt['routes']['percent'],'kom'],['Dužina trase',$asBuilt['route_length_m']['built'],$asBuilt['route_length_m']['planned'],$asBuilt['route_length_m']['percent'],'m'],['Priključci',$asBuilt['houses']['built'],$asBuilt['houses']['planned'],$asBuilt['houses']['percent'],'kom']] as [$label,$built,$planned,$percent,$unit])<div class="rounded-xl border border-slate-200 bg-slate-50 p-4"><div class="flex justify-between text-xs font-bold text-slate-600"><span>{{ $label }}</span><span>{{ $percent }}%</span></div><div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-200"><i class="block h-full rounded-full bg-emerald-500" style="width:{{ $percent }}%"></i></div><p class="mt-2 text-sm text-slate-600"><b class="text-slate-900">{{ number_format($built, 0, ',', '.') }}</b> / {{ number_format($planned, 0, ',', '.') }} {{ $unit }}</p></div>@endforeach</div>
</section>

<section class="project-card mb-5">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100"><div><h2>Revizije troškovnika</h2><p class="mt-1 text-xs text-slate-500">Zamrznute verzije količina i cijena materijala.</p></div>@can('project.edit')<form method="POST" action="{{ route('materials.versions.store', $project) }}" class="flex gap-2">@csrf<input name="label" required maxlength="120" value="Revizija {{ now()->format('d.m.Y') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm"><button class="tbl-btn bg-sky-700 text-white">Sačuvaj reviziju</button></form>@endcan</div>
    <div class="divide-y divide-slate-100">@forelse($project->materialEstimateVersions as $version)<div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm"><span><b>{{ $version->label }}</b><small class="ml-2 text-slate-500">{{ $version->user?->name }} · {{ $version->created_at->format('d.m.Y H:i') }}</small></span><span class="text-slate-600">Plan: <b>{{ number_format((float)$version->planned_total, 2, ',', '.') }}</b> · Utrošeno: <b>{{ number_format((float)$version->used_total, 2, ',', '.') }}</b></span></div>@empty<p class="p-5 text-sm text-slate-500">Još nema sačuvanih revizija.</p>@endforelse</div>
</section>

<section class="project-card mb-5">
    <div class="border-b border-slate-100"><h2>Background obrada</h2><p class="mt-1 text-xs text-slate-500">Veliki izvozi i proračuni rade kroz queue bez blokiranja browsera.</p></div>
    @can('project.edit')<div class="flex flex-wrap gap-2 border-b border-slate-100 p-4">@foreach(['dxf'=>'Pripremi DXF','print_pdf'=>'Pripremi PDF','auto_plan'=>'Izračunaj auto-plan'] as $type=>$label)<form method="POST" action="{{ route('projects.background-tasks.store',$project) }}">@csrf<input type="hidden" name="type" value="{{ $type }}"><button class="tbl-btn bg-sky-700 text-white">{{ $label }}</button></form>@endforeach<form method="POST" action="{{ route('projects.background-tasks.store',$project) }}" enctype="multipart/form-data" class="flex gap-2">@csrf<input type="hidden" name="type" value="survey_import"><input type="file" name="points_file" required accept=".txt,.csv" class="text-xs"><button class="tbl-btn bg-sky-700 text-white">Import u pozadini</button></form></div>@endcan
    <div class="divide-y divide-slate-100">@forelse($project->backgroundTasks as $task)<div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm"><span><b>{{ str_replace('_',' ',strtoupper($task->type)) }}</b><small class="ml-2 text-slate-500">{{ $task->created_at->format('d.m.Y H:i') }} · {{ $task->status }}</small>@if($task->error)<small class="block text-red-600">{{ $task->error }}</small>@endif</span>@if($task->status==='completed'&&$task->result_path)<a class="text-xs font-bold text-sky-700" href="{{ route('projects.background-tasks.download',[$project,$task]) }}">Preuzmi rezultat</a>@endif</div>@empty<p class="p-5 text-sm text-slate-500">Nema background zadataka.</p>@endforelse</div>
</section>

{{-- KPI STRIP --}}
<div class="project-kpis mb-5 grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
    @foreach([
        ['ODF-ovi',    $project->odfs->count(),     'sky',    '<path fill-rule="evenodd" d="M2 5a2 2 0 012-2h12a2 2 0 012 2v2a2 2 0 01-2 2H4a2 2 0 01-2-2V5zm14 1a1 1 0 11-2 0 1 1 0 012 0zM2 13a2 2 0 012-2h12a2 2 0 012 2v2a2 2 0 01-2 2H4a2 2 0 01-2-2v-2zm14 1a1 1 0 11-2 0 1 1 0 012 0z" clip-rule="evenodd"/>'],
        ['ODO ormarići', $project->cabinets->count(), 'blue', '<path d="M4 3a2 2 0 100 4h12a2 2 0 100-4H4zM3 8h14v7a2 2 0 01-2 2H5a2 2 0 01-2-2V8zm5 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z"/>'],
        ['Kuće',        $project->houses->count(),   'violet','<path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"/>'],
        ['Trase',       $cableRoutes->count(),       'green', '<path fill-rule="evenodd" d="M12.316 3.051a1 1 0 01.633 1.265l-4 12a1 1 0 11-1.898-.632l4-12a1 1 0 011.265-.633zM5.707 6.293a1 1 0 010 1.414L3.414 10l2.293 2.293a1 1 0 11-1.414 1.414l-3-3a1 1 0 010-1.414l3-3a1 1 0 011.414 0zm8.586 0a1 1 0 011.414 0l3 3a1 1 0 010 1.414l-3 3a1 1 0 11-1.414-1.414L16.586 10l-2.293-2.293a1 1 0 010-1.414z" clip-rule="evenodd"/>'],
        ['Krakovi',     $project->branches->count(), 'amber', '<path fill-rule="evenodd" d="M5 3a2 2 0 00-2 2v1a2 2 0 002 2h1v3H5a2 2 0 00-2 2v1a2 2 0 002 2h1v1a1 1 0 102 0v-1h1a2 2 0 002-2v-1a2 2 0 00-2-2h-1V8h1a2 2 0 002-2V5a2 2 0 00-2-2H5zm0 2h4v1H5V5zm0 7h4v1H5v-1zm5-4h1v3h-1V8z" clip-rule="evenodd"/>'],
        ['Slobodni portovi', $freePorts,             'green', '<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v2H7a1 1 0 100 2h2v2a1 1 0 102 0v-2h2a1 1 0 100-2h-2V7z" clip-rule="evenodd"/>'],
    ] as [$label, $value, $color, $icon])
    <article class="stat-card">
        <div class="flex items-start justify-between gap-2">
            <div><div class="stat-label">{{ $label }}</div><div class="stat-value">{{ $value }}</div></div>
            <div class="stat-icon {{ $color }}"><svg viewBox="0 0 20 20" fill="currentColor">{!! $icon !!}</svg></div>
        </div>
    </article>
    @endforeach
</div>

<div class="project-command-grid">

    <div class="project-section-label">Operativno stanje mreže</div>

    {{-- LIJEVA KOLONA: Validacija + ODF kapacitet --}}
    <div class="project-column project-column-validation">

        {{-- VALIDACIJA --}}
        <div class="project-card project-card-validation">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-3.5">
                <div><h2 class="text-sm font-semibold text-slate-700">Kontrola kvaliteta projekta</h2><p class="mt-0.5 text-[11px] text-slate-400">Klik na stavku otvara tačan element na mapi.</p></div>
                <div class="validation-filters" role="group" aria-label="Filter validacije">
                    <button type="button" class="validation-filter is-active" data-validation-filter="all">Sve {{ $valErrors->count() + $valWarnings->count() }}</button>
                    <button type="button" class="validation-filter" data-validation-filter="error">Greške {{ $valErrors->count() }}</button>
                    <button type="button" class="validation-filter" data-validation-filter="warning">Upozorenja {{ $valWarnings->count() }}</button>
                </div>
            </div>
            @if($validationByElement->isNotEmpty())
            <div class="validation-summary-strip">
                @foreach(['house' => 'Kuće', 'cabinet' => 'ODO ormarići', 'odf' => 'ODF', 'route' => 'Trase', 'project' => 'Projekat'] as $type => $label)
                    @if($validationByElement->get($type))<span class="validation-summary-chip">{{ $label }} <b>{{ $validationByElement->get($type) }}</b></span>@endif
                @endforeach
            </div>
            @endif
            <div class="project-validation-list divide-y divide-slate-100 overflow-y-auto">
                @forelse($validationItems->whereIn('level', ['error', 'warning', 'info']) as $item)
                @php
                    $vColors = ['error' => ['bg-red-50','text-red-700','text-red-500'], 'warning' => ['bg-amber-50','text-amber-700','text-amber-500'], 'info' => ['bg-slate-50','text-slate-600','text-slate-400']];
                    [$vBg, $vText, $vSub] = $vColors[$item['level']] ?? ['bg-slate-50','text-slate-600','text-slate-400'];
                @endphp
                <a href="{{ route('map.dashboard', array_filter(['project' => $project->id, 'focus_type' => $item['element_type'] ?? null, 'focus_id' => $item['element_id'] ?? null])) }}" data-validation-level="{{ $item['level'] }}" class="project-validation-item {{ $item['level'] === 'error' ? 'is-error' : '' }}">
                    <div class="flex items-start gap-2">
                        <span class="mt-0.5 shrink-0 text-[10px] font-bold uppercase {{ $vText }} opacity-60">{{ $item['level'] }}</span>
                        <div>
                            <div class="validation-message font-medium {{ $vText }}">{{ $item['message'] }}</div>
                            @if($item['recommendation'])<div class="validation-recommendation {{ $vSub }}">{{ $item['recommendation'] }}</div>@endif
                        </div>
                        @if(!empty($item['element_id']))<span class="validation-open">Mapa →</span>@endif
                    </div>
                </a>
                @empty
                <div class="px-5 py-4 text-sm text-slate-400">Nema validacijskih stavki.</div>
                @endforelse
            </div>
            @if($validationItems->whereIn('level', ['error', 'warning', 'info'])->isNotEmpty())
            <div class="validation-footer">
                <span id="validation-visible-summary" class="text-[11px] text-slate-500"></span>
                <button type="button" id="validation-show-more" class="validation-more">Prikaži još</button>
            </div>
            @endif
        </div>

        {{-- ODF KAPACITET --}}
        <div class="project-card project-card-capacity">
            <div class="border-b border-slate-100 px-5 py-3.5">
                <h2 class="text-sm font-semibold text-slate-700">Kapacitet ODF-a</h2>
            </div>
            <div class="divide-y divide-slate-50">
                @forelse($odfCapacity as $row)
                @php
                    $pct = $row['total'] > 0 ? min(100, round($row['used'] / $row['total'] * 100)) : 0;
                    $barColor = $pct > 100 ? '#ef4444' : ($pct >= 85 ? '#f59e0b' : '#22c55e');
                @endphp
                <div class="px-5 py-3">
                    <div class="mb-1.5 flex items-center justify-between">
                        <span class="text-xs font-medium text-slate-700">{{ $row['odf']->name }}</span>
                        <span class="text-xs text-slate-400">{{ $row['used'] }}/{{ $row['total'] }} vlakana</span>
                    </div>
                    <div class="h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full transition-all" style="width:{{ min($pct, 100) }}%;background:{{ $barColor }}"></div>
                    </div>
                    <div class="mt-1 text-[11px] text-slate-400">{{ $pct }}% iskorištenosti · {{ $row['odf']->address }}</div>
                </div>
                @empty
                <div class="px-5 py-4 text-sm text-slate-400">Projekat nema ODF-ova.</div>
                @endforelse
            </div>
        </div>

    </div>

    {{-- SREDNJA KOLONA: Trase + Krakovi --}}
    <div class="project-column project-column-network">

        {{-- TRASE PO TIPU --}}
        <div class="project-card project-card-routes">
            <div class="border-b border-slate-100 px-5 py-3.5">
                <h2 class="text-sm font-semibold text-slate-700">Trase</h2>
            </div>
            @if($cableRoutes->count())
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead><tr class="border-b border-slate-100">
                        <th class="px-4 py-2.5 font-semibold text-slate-500">Tip</th>
                        <th class="px-4 py-2.5 font-semibold text-slate-500 text-right">Kom.</th>
                        <th class="px-4 py-2.5 font-semibold text-slate-500 text-right">Dužina (m)</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-50">
                        @foreach($cableRoutes->groupBy('route_type') as $type => $group)
                        <tr>
                            <td class="px-4 py-2.5 font-medium text-slate-700">{{ $routeTypeLabels[$type] ?? $type }}</td>
                            <td class="px-4 py-2.5 text-right text-slate-500">{{ $group->count() }}</td>
                            <td class="px-4 py-2.5 text-right text-slate-700 font-medium">{{ number_format($group->sum('duct_length_m')) }}</td>
                        </tr>
                        @endforeach
                        @if($trenchRoutes->count())
                        <tr class="bg-slate-50">
                            <td class="px-4 py-2.5 text-slate-400 italic">Glavni rovovi</td>
                            <td class="px-4 py-2.5 text-right text-slate-400">{{ $trenchRoutes->count() }}</td>
                            <td class="px-4 py-2.5 text-right text-slate-400">{{ number_format($trenchRoutes->sum('duct_length_m')) }}</td>
                        </tr>
                        @endif
                        <tr class="border-t border-slate-200 font-semibold bg-slate-50">
                            <td class="px-4 py-2.5 text-slate-700">Ukupno kablovi</td>
                            <td class="px-4 py-2.5 text-right text-slate-700">{{ $cableRoutes->count() }}</td>
                            <td class="px-4 py-2.5 text-right text-slate-800">{{ number_format($cableRoutes->sum('duct_length_m')) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            @else
            <div class="px-5 py-4 text-sm text-slate-400">Projekat nema trasa.</div>
            @endif
        </div>

        {{-- KRAKOVI --}}
        <div class="project-card project-card-branches">
            <div class="border-b border-slate-100 px-5 py-3.5">
                <h2 class="text-sm font-semibold text-slate-700">Krakovi mreže</h2>
            </div>
            @if($project->branches->count())
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead><tr class="border-b border-slate-100">
                        <th class="px-4 py-2.5 font-semibold text-slate-500">Naziv</th>
                        <th class="px-4 py-2.5 font-semibold text-slate-500">Tip</th>
                        <th class="px-4 py-2.5 font-semibold text-slate-500 text-right">ODO</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-50">
                        @foreach($project->branches as $branch)
                        @php
                            $typeLabels = ['primary' => 'Primarni', 'secondary' => 'Sekundarni', 'rov' => 'Glavni rov'];
                            $typeBadge  = ['primary' => 'violet', 'secondary' => 'blue', 'rov' => 'amber'];
                        @endphp
                        <tr>
                            <td class="px-4 py-2.5 font-medium text-slate-700">{{ $branch->name }}</td>
                            <td class="px-4 py-2.5">
                                <span class="ftth-badge {{ $typeBadge[$branch->type] ?? 'slate' }}"><span class="ftth-badge-dot"></span>{{ $typeLabels[$branch->type] ?? $branch->type }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-right text-slate-500">{{ $branch->cabinets_count }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <div class="px-5 py-4 text-sm text-slate-400">Projekat nema krakova.</div>
            @endif
        </div>

    </div>

    {{-- DESNA KOLONA: Materijali + Popunjenost ODO --}}
    <div class="project-column project-column-resources">

        {{-- MATERIJALI --}}
        <div class="project-card project-card-materials">
            <div class="border-b border-slate-100 px-5 py-3.5">
                <h2 class="text-sm font-semibold text-slate-700">Materijali (sa {{ $materials['reserve_percent'] }}% rezerve)</h2>
            </div>
            <div class="divide-y divide-slate-50">
                @foreach([
                    ['Mikrocijev 14/10', $materials['microduct_14_10_m'], 'm'],
                    ['Mikrocijev 10/8',  $materials['microduct_10_8_m'],  'm'],
                    ['Optika 4 niti',   $materials['fiber_4_m'],         'm'],
                    ['Optika 12 niti',  $materials['fiber_12_m'],        'm'],
                    ['Optika 24 niti',  $materials['fiber_24_m'],        'm'],
                    ['Optika 48 niti',  $materials['fiber_48_m'],        'm'],
                ] as [$label, $base, $unit])
                @if($base > 0)
                @php $withRes = (int) ceil($base * (1 + $materials['reserve_percent'] / 100)); @endphp
                <div class="flex items-center justify-between px-5 py-2.5">
                    <span class="text-xs text-slate-600">{{ $label }}</span>
                    <div class="text-right">
                        <span class="text-xs font-semibold text-slate-800">{{ number_format($withRes) }} {{ $unit }}</span>
                        <span class="ml-1 text-[11px] text-slate-400">({{ number_format($base) }} + rez.)</span>
                    </div>
                </div>
                @endif
                @endforeach
                <div class="flex items-center justify-between bg-slate-50 px-5 py-2.5">
                    <span class="text-xs font-semibold text-slate-600">ODF-ovi</span>
                    <span class="text-xs font-bold text-slate-800">{{ $materials['odf_count'] }} kom.</span>
                </div>
                <div class="flex items-center justify-between px-5 py-2.5">
                    <span class="text-xs font-semibold text-slate-600">ODO ormarići</span>
                    <span class="text-xs font-bold text-slate-800">{{ $materials['odo_count'] }} kom.</span>
                </div>
                <div class="flex items-center justify-between bg-slate-50 px-5 py-2.5">
                    <span class="text-xs font-semibold text-slate-600">Splitteri 1/4</span>
                    <span class="text-xs font-bold text-slate-800">{{ $materials['splitter_count'] }} kom.</span>
                </div>
                @if($materials['estimated_value'] > 0)
                <div class="flex items-center justify-between px-5 py-2.5 border-t border-slate-200">
                    <span class="text-xs font-semibold text-slate-600">Procjenjena vrijednost</span>
                    <span class="text-xs font-bold text-slate-800">{{ number_format($materials['estimated_value'], 2) }} KM</span>
                </div>
                @endif
            </div>
        </div>

        {{-- POPUNJENOST ODO --}}
        <div class="project-card project-card-occupancy">
            <div class="border-b border-slate-100 px-5 py-3.5">
                <h2 class="text-sm font-semibold text-slate-700">Popunjenost ODO ormarića</h2>
            </div>
            <div class="px-5 py-4 space-y-3">
                <div class="flex items-end justify-between">
                    <div>
                        <div class="text-2xl font-bold text-slate-900">{{ $utilPct }}%</div>
                        <div class="text-xs text-slate-400 mt-0.5">{{ $usedPorts }}/{{ $capacity }} portova</div>
                    </div>
                    <div class="text-right text-xs text-slate-400">
                        <div>{{ $freePorts }} slobodnih portova</div>
                        <div>{{ $project->cabinets->count() }} ormarića</div>
                    </div>
                </div>
                <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full rounded-full transition-all" style="width:{{ $utilPct }}%;background:{{ $utilColor }}"></div>
                </div>
            </div>
            @if($project->cabinets->count())
            <div class="border-t border-slate-100 divide-y divide-slate-50 max-h-56 overflow-y-auto">
                @foreach($project->cabinets->sortBy('name') as $cab)
                @php
                    $cabPct = $cab->capacity > 0 ? min(100, round($cab->houses_count / $cab->capacity * 100)) : 0;
                    $cabColor = $cabPct >= 90 ? '#ef4444' : ($cabPct >= 70 ? '#f59e0b' : '#22c55e');
                @endphp
                <div class="flex items-center gap-3 px-5 py-2">
                    <div class="min-w-0 flex-1">
                        <div class="text-xs font-medium text-slate-700 truncate">{{ $cab->name }}</div>
                        <div class="mt-0.5 h-1 w-full overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full" style="width:{{ $cabPct }}%;background:{{ $cabColor }}"></div>
                        </div>
                    </div>
                    <span class="shrink-0 text-[11px] text-slate-400">{{ $cab->houses_count }}/{{ $cab->capacity }}</span>
                </div>
                @endforeach
            </div>
            @endif
        </div>

    </div>

</div>
</div>

@push('scripts')
<script nonce="{{ Vite::cspNonce() }}">
(function () {
    // A browser Back navigation can restore warnings from before map edits.
    window.addEventListener('pageshow', event => {
        if (event.persisted) window.location.reload();
    });
    let validationLevel = 'all';
    let validationLimit = 12;
    function renderValidationItems() {
        const items = [...document.querySelectorAll('[data-validation-level]')];
        const matching = items.filter(item => validationLevel === 'all' || item.dataset.validationLevel === validationLevel);
        items.forEach(item => { item.hidden = true; });
        matching.slice(0, validationLimit).forEach(item => { item.hidden = false; });
        const summary = document.getElementById('validation-visible-summary');
        const more = document.getElementById('validation-show-more');
        if (summary) summary.textContent = `Prikazano ${Math.min(validationLimit, matching.length)} od ${matching.length} stavki`;
        if (more) more.hidden = validationLimit >= matching.length;
    }
    document.querySelectorAll('[data-validation-filter]').forEach(button => {
        button.addEventListener('click', () => {
            validationLevel = button.dataset.validationFilter;
            validationLimit = 12;
            document.querySelectorAll('[data-validation-filter]').forEach(item => item.classList.toggle('is-active', item === button));
            renderValidationItems();
        });
    });
    document.getElementById('validation-show-more')?.addEventListener('click', () => {
        validationLimit += 12;
        renderValidationItems();
    });
    renderValidationItems();

    document.getElementById('project-fill-missing-drops')?.addEventListener('click', async event => {
        const button = event.currentTarget;
        if (!await window.ftthConfirm('Kreirati nedostajuće drop trase za sve kuće koje imaju dodijeljen ODO?', {title:'Popunjavanje drop trasa',detail:'Aplikacija će koristiti trenutno povezane ODO-e i dostupnu mrežnu geometriju. Napravi snapshot prije nastavka.',confirmLabel:'Kreiraj trase'})) return;
        const original = button.textContent;
        button.disabled = true;
        button.textContent = 'Kreiram trase…';
        try {
            const response = await fetch(button.dataset.url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Drop trase nisu kreirane.');
            button.textContent = `Kreirano: ${result.created}`;
            window.setTimeout(() => window.location.reload(), 650);
        } catch (error) {
            button.disabled = false;
            button.textContent = original;
            window.alert(error.message);
        }
    });

    const DB = 'ftth_dxf_v1', ST = 'layers', VER = 1;
    let _db = null;
    function openDb() {
        if (_db) return Promise.resolve(_db);
        return new Promise((res, rej) => {
            const r = indexedDB.open(DB, VER);
            r.onupgradeneeded = e => { if (!e.target.result.objectStoreNames.contains(ST)) e.target.result.createObjectStore(ST, { keyPath: 'dbId' }); };
            r.onsuccess = e => { _db = e.target.result; res(_db); };
            r.onerror   = e => rej(e.target.error);
        });
    }
    async function getExportLayers() {
        try {
            const db  = await openDb();
            const all = await new Promise((res, rej) => { const tx = db.transaction(ST, 'readonly'); const rq = tx.objectStore(ST).getAll(); rq.onsuccess = e => res(e.target.result || []); rq.onerror = e => rej(e.target.error); });
            const result = []; let missingKey = 0;
            for (const s of all) { const ck = s.cacheKey || s.geojson?._cache_key || null; if (ck) result.push({ cache_key: ck, color: s.color }); else if (s.geojson?.features?.length) missingKey++; }
            if (missingKey > 0) alert(`${missingKey} DXF podloga nema cache ključ — mora se ponovo importovati.`);
            if (result.length === 0 && missingKey === 0) alert('Nema sačuvanih DXF podloga u browseru.');
            return result;
        } catch { return []; }
    }
    document.addEventListener('click', async function (e) {
        const btn = e.target.closest('.dxf-export-btn');
        if (!btn) return;
        e.preventDefault();
        const url = btn.getAttribute('data-dxf-export');
        if (!url) return;
        const orig = btn.innerHTML;
        btn.textContent = 'Pripremam…'; btn.style.pointerEvents = 'none';
        try {
            const bgLayers = await getExportLayers();
            btn.textContent = bgLayers.length > 0 ? `Export (${bgLayers.length} DXF)…` : 'Export (bez podloge)…';
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
            const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/octet-stream,application/dxf,*/*' }, body: JSON.stringify({ background_layers: bgLayers }) });
            if (!res.ok) { let msg = 'HTTP ' + res.status; try { const j = await res.json(); if (j.error) msg = j.error; } catch {} throw new Error(msg); }
            const blob = await res.blob();
            const a = document.createElement('a');
            const cd = res.headers.get('Content-Disposition') ?? '';
            a.download = cd.match(/filename[^;=\n]*=["']?([^"'\n]+)/i)?.[1] ?? 'export.dxf';
            a.href = URL.createObjectURL(blob);
            document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(a.href);
        } catch (err) { alert('Greška pri DXF exportu: ' + err.message); }
        finally { btn.innerHTML = orig; btn.style.pointerEvents = ''; }
    });
})();
</script>
@endpush

@endsection
