<!DOCTYPE html>
<html lang="bs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>FTTH Manager</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('css/ftth-app.css') }}?v={{ filemtime(public_path('css/ftth-app.css')) }}">
    <style>
        body { line-height: 1.4; }
        .app-content table { font-size: .875rem; }
        .app-content table th, .app-content table td { line-height: 1.25; }
        .leaflet-popup-content, .leaflet-tooltip { font-size: .78rem; line-height: 1.2; }
    </style>
</head>
@php
    $isWide = trim($__env->yieldContent('wide'));
    $isDashboard = request()->routeIs('map.dashboard');
    $sidebarItems = [
        ['dashboard',           'Pregled',            'squares'],
        ['map.dashboard',       'Mapa',               'map'],
        ['projects.index',      'Projekti',           'folder'],
        ['odfs.index',          'ODF-ovi',            'server'],
        ['cabinets.index',      'ODO ormarići',       'archive'],
        ['houses.index',        'Kuće',               'home'],
        ['routes.index',        'Trase',              'route'],
        ['branches.index',      'Krakovi',            'branch'],
        ['reports.index',       'Izvještaji',         'chart'],
        ['splitters.index',     'Splitteri',          'split'],
        ['fiber-schema.index',  'Fiber sema',         'chip'],
        ['project-check.index', 'Provjera projekta',  'shield'],
        ['settings.index',      'Postavke',           'cog'],
    ];
    $sidebarIcons = [
        'squares' => '<svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path d="M2 4a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H3a1 1 0 01-1-1V4zM10 4a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1h-6a1 1 0 01-1-1V4zM2 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H3a1 1 0 01-1-1v-6zM10 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1h-6a1 1 0 01-1-1v-6z"/></svg>',
        'folder'  => '<svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" clip-rule="evenodd"/></svg>',
        'map'     => '<svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12 1.586l-4 4v12.828l4-4V1.586zM3.707 3.293A1 1 0 002 4v10a1 1 0 00.293.707L6 18.414V5.586L3.707 3.293zm10.586 13.414L18 14.414V1.586l-3.707 3.707A1 1 0 0014 6v10.707z" clip-rule="evenodd"/></svg>',
        'server'  => '<svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2 5a2 2 0 012-2h12a2 2 0 012 2v2a2 2 0 01-2 2H4a2 2 0 01-2-2V5zm14 1a1 1 0 11-2 0 1 1 0 012 0zM2 13a2 2 0 012-2h12a2 2 0 012 2v2a2 2 0 01-2 2H4a2 2 0 01-2-2v-2zm14 1a1 1 0 11-2 0 1 1 0 012 0z" clip-rule="evenodd"/></svg>',
        'archive' => '<svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path d="M4 3a2 2 0 100 4h12a2 2 0 100-4H4zM3 8h14v7a2 2 0 01-2 2H5a2 2 0 01-2-2V8zm5 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z"/></svg>',
        'home'    => '<svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"/></svg>',
        'users'   => '<svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/></svg>',
        'route'   => '<svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.316 3.051a1 1 0 01.633 1.265l-4 12a1 1 0 11-1.898-.632l4-12a1 1 0 011.265-.633zM5.707 6.293a1 1 0 010 1.414L3.414 10l2.293 2.293a1 1 0 11-1.414 1.414l-3-3a1 1 0 010-1.414l3-3a1 1 0 011.414 0zm8.586 0a1 1 0 011.414 0l3 3a1 1 0 010 1.414l-3 3a1 1 0 11-1.414-1.414L16.586 10l-2.293-2.293a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>',
        'branch'  => '<svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 3a2 2 0 00-2 2v1a2 2 0 002 2h1v3H5a2 2 0 00-2 2v1a2 2 0 002 2h1v1a1 1 0 102 0v-1h1a2 2 0 002-2v-1a2 2 0 00-2-2h-1V8h1a2 2 0 002-2V5a2 2 0 00-2-2H5zm0 2h4v1H5V5zm0 7h4v1H5v-1zm5-4h1v3h-1V8z" clip-rule="evenodd"/></svg>',
        'cube'    => '<svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path d="M11 17a1 1 0 001.447.894l4-2A1 1 0 0017 15V9.236a1 1 0 00-1.447-.894l-4 2a1 1 0 00-.553.894V17zM15.211 6.276a1 1 0 000-1.788l-4.764-2.382a1 1 0 00-.894 0L4.789 4.488a1 1 0 000 1.788l4.764 2.382a1 1 0 00.894 0l4.764-2.382zM4.447 8.342A1 1 0 003 9.236V15a1 1 0 00.553.894l4 2A1 1 0 009 17v-5.764a1 1 0 00-.553-.894l-4-2z"/></svg>',
        'chart'   => '<svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6 2a2 2 0 00-2 2v12a2 2 0 002 2h8a2 2 0 002-2V7.414A2 2 0 0015.414 6L12 2.586A2 2 0 0010.586 2H6zm2 10a1 1 0 10-2 0v3a1 1 0 102 0v-3zm2-3a1 1 0 011 1v5a1 1 0 11-2 0v-5a1 1 0 011-1zm4-1a1 1 0 10-2 0v7a1 1 0 102 0V8z" clip-rule="evenodd"/></svg>',
        'split'   => '<svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/><path fill-rule="evenodd" d="M3 10a7 7 0 1114 0 7 7 0 01-14 0zm7-5a5 5 0 100 10A5 5 0 0010 5z" clip-rule="evenodd"/></svg>',
        'chip'    => '<svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2 5a2 2 0 012-2h8a2 2 0 012 2v10a2 2 0 002 2H4a2 2 0 01-2-2V5zm3 1h6v4H5V6zm6 6H5v2h6v-2z" clip-rule="evenodd"/><path d="M15 7h1a2 2 0 012 2v5.5a1.5 1.5 0 01-3 0V7z"/></svg>',
        'shield'  => '<svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>',
        'cog'     => '<svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/></svg>',
    ];
    $headerNotifications = collect();
    $notificationProjectId = request()->integer('project');
    $scopeNotifications = fn ($query) => $query->when($notificationProjectId > 0, fn ($projectQuery) => $projectQuery->where('project_id', $notificationProjectId));
    $unlinkedHouses = $scopeNotifications(\App\Models\House::query())->whereNull('cabinet_id')->count();
    $unlinkedCabinets = $scopeNotifications(\App\Models\Cabinet::query())->whereNull('odf_id')->count();
    $incompleteRoutes = $scopeNotifications(\App\Models\NetworkRoute::query())->where('route_type', '!=', 'trench')->where(function ($query) { $query->whereNull('microduct_type')->orWhere('microduct_type', '')->orWhere('microduct_count', '<=', 0)->orWhere('duct_length_m', '<=', 0)->orWhere(function ($cableQuery) { $cableQuery->where('fiber_count', '>', 0)->where('fiber_length_m', '<=', 0); }); })->count();
    if ($unlinkedHouses) $headerNotifications->push("$unlinkedHouses kuća nema dodijeljeni ODO.");
    if ($unlinkedCabinets) $headerNotifications->push("$unlinkedCabinets ODO ormarića nema povezani ODF.");
    if ($incompleteRoutes) $headerNotifications->push("$incompleteRoutes trasa nema kompletne tehničke podatke.");
@endphp
<body class="{{ $isDashboard || request()->routeIs('fiber-schema.index') ? 'workspace-page' : 'standard-page' }} h-screen overflow-hidden bg-slate-100 font-sans text-slate-950 antialiased">
<div class="flex h-screen overflow-hidden">
    <aside class="app-sidebar hidden w-52 shrink-0 bg-linear-to-b from-[#004f7d] to-[#003558] text-white lg:flex lg:flex-col" style="box-shadow: 4px 0 24px rgba(0,0,0,.18);">
        <a href="{{ route('dashboard') }}" class="app-brand flex h-11 items-center border-b border-white/10 px-5 xl:h-12 xl:px-6" aria-label="FTTH Manager — početna">
            <img src="{{ asset('images/logo.png') }}" alt="Media Sky Telekomunikacije" class="app-brand-logo h-auto w-full max-w-40 object-contain object-left">
        </a>
        <nav class="app-navigation flex flex-col p-2 text-[12px] flex-1 overflow-y-auto" style="gap:1px">
            @foreach ($sidebarItems as [$route, $label, $iconKey])
                @if($route === 'dashboard')<span class="nav-section-label">Radni prostor</span>@endif
                @if($route === 'odfs.index')<span class="nav-section-label">Mrežna evidencija</span>@endif
                @if($route === 'reports.index')<span class="nav-section-label">Analitika i kontrola</span>@endif
                @if($route === 'settings.index')<span class="nav-section-label">Sistem</span>@endif
                <a class="flex items-center gap-2 rounded px-2.5 py-1.25 leading-none transition-colors {{ request()->routeIs($route) ? 'sidebar-active font-semibold text-white' : 'text-blue-100/75 hover:bg-white/10 hover:text-white' }}" href="{{ route($route) }}">
                    <span class="shrink-0 opacity-70">{!! $sidebarIcons[$iconKey] ?? '' !!}</span>
                    {{ $label }}
                </a>
            @endforeach
        </nav>
        @if($isDashboard && isset($odfs, $cabinets, $stats, $routes))
            <div class="mx-4 mb-4 mt-1 rounded-xl border border-white/10 p-3 text-[10px] xl:text-[11px]" style="background:rgba(255,255,255,.06)">
                <b class="mb-2 block text-blue-200 text-[9.5px] uppercase tracking-widest">Brzi pregled</b>
                @foreach([['ODF-ovi', $odfs->count()], ['ODO ormarići', $cabinets->count()], ['Trase', $routes->count()], ['Ukupna dužina', number_format($stats['routes_m'] / 1000, 2).' km']] as $quick)
                    <div class="flex justify-between py-1 text-blue-200"><span>{{ $quick[0] }}</span><b class="text-white">{{ $quick[1] }}</b></div>
                @endforeach
            </div>
        @endif
    </aside>

    <main class="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
        <header class="app-topbar relative z-1300 flex h-13 shrink-0 items-center justify-between border-b bg-white px-3 sm:px-5" style="box-shadow:0 1px 0 #e8edf3,0 4px 16px rgba(15,23,42,.06);">

            {{-- LEFT: logo + breadcrumb --}}
            <div class="flex min-w-0 items-center gap-3">
                <button type="button" data-header-action="mobile-menu" class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 lg:hidden" aria-label="Meni">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
                </button>
                <a href="{{ route('dashboard') }}" class="flex shrink-0 items-center" aria-label="FTTH Manager — početna">
                    <img src="{{ asset('images/logo.png') }}" alt="Media Sky Telekomunikacije" class="h-auto w-24 object-contain sm:w-28">
                </a>
                <div class="hidden min-w-0 items-center gap-2 sm:flex">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5 shrink-0 text-slate-300"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
                    <span class="truncate text-sm font-medium text-slate-600 max-w-55">@yield('title')</span>
                </div>
            </div>

            {{-- RIGHT: actions --}}
            <div class="flex items-center gap-0.5">

                {{-- Search --}}
                <button type="button" data-header-action="search" title="Pretraži (Ctrl+K)" aria-label="Pretraži aplikaciju" aria-haspopup="dialog"
                    class="hidden sm:flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs text-slate-400 transition-colors hover:border-slate-300 hover:bg-white hover:text-slate-600">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5 shrink-0"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>
                    <span class="hidden lg:inline">Pretraži</span>
                    <kbd class="hidden lg:inline rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[10px] font-medium text-slate-400 shadow-sm">Ctrl K</kbd>
                </button>

                {{-- Fullscreen --}}
                <button type="button" data-header-action="fullscreen" title="Cijeli ekran" aria-label="Uključi cijeli ekran"
                    class="hidden sm:flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700">
                    <svg id="icon-fs-enter" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4"><path fill-rule="evenodd" d="M3 4a1 1 0 011-1h4a1 1 0 010 2H6.414l2.293 2.293a1 1 0 01-1.414 1.414L5 6.414V8a1 1 0 01-2 0V4zm9 1a1 1 0 010-2h4a1 1 0 011 1v4a1 1 0 01-2 0V6.414l-2.293 2.293a1 1 0 11-1.414-1.414L13.586 5H12zm-9 7a1 1 0 012 0v1.586l2.293-2.293a1 1 0 011.414 1.414L6.414 15H8a1 1 0 010 2H4a1 1 0 01-1-1v-4zm13-1a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 010-2h1.586l-2.293-2.293a1 1 0 011.414-1.414L15 13.586V12a1 1 0 011-1z" clip-rule="evenodd"/></svg>
                    <svg id="icon-fs-exit" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4 hidden"><path fill-rule="evenodd" d="M4 10a1 1 0 011-1h3V6a1 1 0 012 0v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H5a1 1 0 01-1-1zm10-6a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0V5a1 1 0 011-1zM4 15a1 1 0 011-1h3v-3a1 1 0 112 0v3h3a1 1 0 110 2H5a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
                </button>

                <div class="mx-1.5 hidden h-5 w-px bg-slate-200 sm:block"></div>

                {{-- Notifications button --}}
                <button type="button" id="btn-notifications" title="Obavijesti" aria-label="Obavijesti" aria-controls="notification-menu" aria-expanded="false"
                    class="relative flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg>
                    <span id="notif-badge" class="absolute right-1 top-1 h-2.5 w-2.5 rounded-full border-2 border-white bg-red-500 {{ $headerNotifications->isEmpty() ? 'hidden' : '' }}"></span>
                </button>

                <div class="mx-1.5 h-5 w-px bg-slate-200"></div>

                {{-- Profile button --}}
                <button type="button" id="btn-profile" aria-label="Korisnički meni" aria-controls="profile-menu" aria-expanded="false"
                    class="flex items-center gap-2 rounded-lg py-1 pl-1 pr-2 transition-colors hover:bg-slate-100">
                    <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full text-[11px] font-bold text-white" style="background:linear-gradient(135deg,#308dcc,#004f7d)">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr(auth()->user()->name, 0, 2)) }}</span>
                    <span class="hidden sm:block text-left leading-tight">
                        <b class="block max-w-32 truncate text-xs font-semibold text-slate-800">{{ auth()->user()->name }}</b>
                        <small class="block text-[10px] font-normal text-slate-400">Administrator</small>
                    </span>
                    <svg viewBox="0 0 20 20" fill="currentColor" class="w-3 h-3 text-slate-400"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                </button>

            </div>
        </header>
        @unless($isDashboard)
            <div class="ftth-page-header">
                <div class="ftth-page-header-inner">
                    <h1>@yield('title')</h1>
                    <p>@yield('subtitle')</p>
                </div>
            </div>
        @endunless
        <div class="app-content {{ $isWide ? 'flex min-h-0 flex-1 flex-col overflow-hidden p-1.5 sm:p-2 xl:p-2.5' : 'flex-1 min-h-0 overflow-hidden flex flex-col w-full px-3 py-2 sm:px-4 sm:py-2' }}">
            @if (session('success'))<div class="flash-success"><svg viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4 shrink-0"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>{{ session('success') }}</div>@endif
            @if ($errors->any())<div class="flash-error"><svg viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4 shrink-0"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>{{ $errors->first() }}</div>@endif
            @yield('content')
        </div>
        @if($isDashboard)<footer class="flex min-h-8 shrink-0 flex-wrap items-center justify-center gap-x-3 border-t bg-white px-2 py-1 text-center text-[10px] text-slate-500">&copy; 2026 Mediasky · FTTH Manager <span>v1.0.0</span></footer>@endif
    </main>
</div>
<div id="mobile-sidebar-backdrop" class="fixed inset-0 z-1350 hidden bg-slate-950/50 lg:hidden"></div>
<aside id="mobile-sidebar" class="fixed inset-y-0 left-0 z-1400 hidden w-[min(280px,88vw)] overflow-y-auto bg-linear-to-b from-[#004f7d] to-[#003558] text-white shadow-2xl lg:hidden">
    <div class="flex h-11 items-center justify-between border-b border-white/10 px-4">
        <a href="{{ route('dashboard') }}" class="font-bold text-sm">FTTH Manager</a>
        <button type="button" data-header-action="close-mobile-menu" class="rounded-lg px-3 py-2 text-xs font-semibold hover:bg-white/10">Zatvori</button>
    </div>
    <nav class="grid gap-px p-2 text-[13px]">
        @foreach ($sidebarItems as [$route, $label, $iconKey])
            <a class="flex items-center gap-2.5 rounded-md px-3 py-2 transition-colors {{ request()->routeIs($route) ? 'sidebar-active font-semibold text-white' : 'text-blue-100/80 hover:bg-white/10 hover:text-white' }}" href="{{ route($route) }}">
                <span class="shrink-0 opacity-75">{!! $sidebarIcons[$iconKey] ?? '' !!}</span>
                {{ $label }}
            </a>
        @endforeach
    </nav>
</aside>
<div id="global-search" class="fixed inset-0 z-1300 hidden place-items-start bg-slate-950/50 px-4 pt-24 backdrop-blur-sm" role="dialog" aria-modal="true" aria-label="Pretraga aplikacije">
    <div class="mx-auto w-full max-w-xl rounded-2xl bg-white p-4 shadow-2xl border border-slate-200">
        <div class="flex gap-2">
            <div class="flex flex-1 items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3">
                <svg viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4 text-slate-400 shrink-0"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>
                <input id="global-search-input" class="min-w-0 flex-1 bg-transparent py-2.5 text-sm outline-none" placeholder="Pretraži meni...">
            </div>
            <button type="button" data-header-action="close-search" class="rounded-xl bg-slate-100 px-3 py-2 text-sm font-semibold hover:bg-slate-200">Zatvori</button>
        </div>
        <div id="global-search-results" class="mt-3 grid gap-0.5"></div>
    </div>
</div>
<div id="delete-confirmation-modal" class="fixed inset-0 z-1500 hidden place-items-center bg-slate-950/60 px-4 backdrop-blur-sm">
    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl border border-slate-200">
        <div class="mb-4 flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-red-100">
                <svg viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5 text-red-600"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
            </div>
            <div>
                <h2 class="text-base font-bold text-slate-950">Potvrdi brisanje</h2>
                <p id="delete-confirmation-message" class="text-sm text-slate-500">Sigurno obrisati ovaj zapis?</p>
            </div>
        </div>
        <div class="flex justify-end gap-2">
            <button type="button" data-delete-modal-action="cancel" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Poništi</button>
            <button type="button" data-delete-modal-action="confirm" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Obriši</button>
        </div>
    </div>
</div>
<script>
const ftthMenuItems = [
    ['Pregled', @json(route('dashboard'))], ['Projekti', @json(route('projects.index'))], ['Mapa', @json(route('map.dashboard'))],
    ['ODF-ovi', @json(route('odfs.index'))], ['ODO ormarići', @json(route('cabinets.index'))], ['Kuće', @json(route('houses.index'))],
    ['Trase', @json(route('routes.index'))], ['Krakovi', @json(route('branches.index'))], ['Izvještaji', @json(route('reports.index'))],
    ['Splitteri', @json(route('splitters.index'))], ['Fiber sema', @json(route('fiber-schema.index'))], ['Provjera projekta', @json(route('project-check.index'))],
    ['Postavke', @json(route('settings.index'))],
];
function closeHeaderMenus(except = '') {
    ['notification-menu', 'profile-menu'].forEach(id => {
        if (id === except) return;
        document.getElementById(id)?.classList.add('hidden');
    });
    if (except !== 'notification-menu') document.getElementById('btn-notifications')?.setAttribute('aria-expanded', 'false');
    if (except !== 'profile-menu') document.getElementById('btn-profile')?.setAttribute('aria-expanded', 'false');
}
function toggleHeaderMenu(id) {
    const menu = document.getElementById(id);
    if (!menu) return;
    const opening = menu.classList.contains('hidden');
    closeHeaderMenus(id);
    menu.classList.toggle('hidden', !opening);
    document.getElementById(id === 'notification-menu' ? 'btn-notifications' : 'btn-profile')?.setAttribute('aria-expanded', String(opening));
}
function renderGlobalSearch(value = '') {
    const results = ftthMenuItems.filter(([label]) => label.toLowerCase().includes(value.toLowerCase()));
    document.getElementById('global-search-results').innerHTML = results.map(([label, url]) =>
        `<a class="flex items-center gap-2 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50" href="${url}">${label}</a>`
    ).join('');
}
document.querySelector('[data-header-action="search"]')?.addEventListener('click', () => {
    const modal = document.getElementById('global-search');
    modal.classList.remove('hidden'); modal.classList.add('grid');
    renderGlobalSearch(); document.getElementById('global-search-input').focus();
});
document.querySelector('[data-header-action="close-search"]')?.addEventListener('click', () => document.getElementById('global-search').classList.add('hidden'));
document.getElementById('global-search-input')?.addEventListener('input', e => renderGlobalSearch(e.target.value));
document.querySelector('[data-header-action="fullscreen"]')?.addEventListener('click', () => document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen());
document.getElementById('btn-notifications')?.addEventListener('click', e => { e.stopPropagation(); toggleHeaderMenu('notification-menu'); });
document.getElementById('btn-profile')?.addEventListener('click', e => { e.stopPropagation(); toggleHeaderMenu('profile-menu'); });
document.getElementById('notification-menu')?.addEventListener('click', e => e.stopPropagation());
document.getElementById('profile-menu')?.addEventListener('click', e => e.stopPropagation());
document.addEventListener('click', () => closeHeaderMenus());
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeHeaderMenus();
        document.getElementById('global-search')?.classList.add('hidden');
        document.getElementById('global-search')?.classList.remove('grid');
        toggleMobileSidebar(false);
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        const modal = document.getElementById('global-search');
        modal.classList.remove('hidden'); modal.classList.add('grid');
        renderGlobalSearch(); document.getElementById('global-search-input').focus();
    }
});
document.addEventListener('fullscreenchange', () => {
    const fs = !!document.fullscreenElement;
    document.getElementById('icon-fs-enter')?.classList.toggle('hidden', fs);
    document.getElementById('icon-fs-exit')?.classList.toggle('hidden', !fs);
});
function toggleMobileSidebar(open) {
    document.getElementById('mobile-sidebar')?.classList.toggle('hidden', !open);
    document.getElementById('mobile-sidebar-backdrop')?.classList.toggle('hidden', !open);
}
document.querySelector('[data-header-action="mobile-menu"]')?.addEventListener('click', () => toggleMobileSidebar(true));
document.querySelector('[data-header-action="close-mobile-menu"]')?.addEventListener('click', () => toggleMobileSidebar(false));
document.getElementById('mobile-sidebar-backdrop')?.addEventListener('click', () => toggleMobileSidebar(false));
let pendingDeleteForm = null;
document.querySelectorAll('form[data-confirm-delete]').forEach(form => {
    form.addEventListener('submit', event => {
        event.preventDefault();
        pendingDeleteForm = form;
        document.getElementById('delete-confirmation-message').textContent = form.dataset.confirmDelete || 'Sigurno obrisati ovaj zapis?';
        const modal = document.getElementById('delete-confirmation-modal');
        modal.classList.remove('hidden'); modal.classList.add('grid');
    });
});
document.querySelector('[data-delete-modal-action="cancel"]')?.addEventListener('click', () => {
    pendingDeleteForm = null;
    document.getElementById('delete-confirmation-modal')?.classList.add('hidden');
});
document.querySelector('[data-delete-modal-action="confirm"]')?.addEventListener('click', () => {
    const form = pendingDeleteForm; pendingDeleteForm = null;
    document.getElementById('delete-confirmation-modal')?.classList.add('hidden');
    form?.submit();
});
// Drawer slide-over
let activeDrawerTrigger = null;
function closeDrawer(drawer, restoreFocus = true) {
    if (!drawer) return;
    drawer.classList.remove('open');
    drawer.setAttribute('aria-hidden', 'true');
    if (restoreFocus) activeDrawerTrigger?.focus();
    activeDrawerTrigger = null;
}
document.addEventListener('click', e => {
    const open = e.target.closest('[data-drawer-open]');
    if (open) {
        const drawer = document.getElementById(open.dataset.drawerOpen);
        if (!drawer) return;
        activeDrawerTrigger = open;
        drawer.classList.add('open');
        drawer.setAttribute('aria-hidden', 'false');
        const panel = drawer.querySelector('.app-drawer-panel');
        panel?.setAttribute('role', 'dialog');
        panel?.setAttribute('aria-modal', 'true');
        requestAnimationFrame(() => (drawer.querySelector('input:not([type="hidden"]), select, textarea') || panel)?.focus());
        return;
    }
    const close = e.target.closest('[data-drawer-close]');
    if (close) { closeDrawer(document.getElementById(close.dataset.drawerClose)); return; }
    if (e.target.closest('.app-drawer-backdrop')) closeDrawer(e.target.closest('.app-drawer'));
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.app-drawer.open').forEach(d => closeDrawer(d));
});
document.querySelectorAll('.app-drawer').forEach(drawer => drawer.setAttribute('aria-hidden', String(!drawer.classList.contains('open'))));
const savedFtthSettings = JSON.parse(localStorage.getItem('ftthSettings') || '{}');
document.documentElement.classList.toggle('compact-tables', Boolean(savedFtthSettings.compactTables));
document.documentElement.classList.toggle('small-markers', savedFtthSettings.smallMarkers !== false);
document.documentElement.classList.toggle('hide-header-notifications', savedFtthSettings.notifications === false);
(function pollNotifications() {
    const notificationsUrl = @json(route('api.notifications', $notificationProjectId > 0 ? ['project' => $notificationProjectId] : []));
    function refresh() {
        fetch(notificationsUrl).then(r => r.ok ? r.json() : null).then(d => {
            if (!d) return;
            const badge = document.getElementById('notif-badge');
            const count = document.getElementById('notification-count');
            const list  = document.getElementById('notification-list');
            if (badge) badge.classList.toggle('hidden', d.count === 0);
            if (count) { count.textContent = d.count; count.classList.toggle('hidden', d.count === 0); }
            if (list) {
                list.innerHTML = d.items.length
                    ? d.items.map(t => `<div class="flex items-start gap-3 border-b border-slate-50 px-4 py-3 last:border-0"><span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-amber-400"></span><span class="text-xs leading-5 text-slate-600">${t}</span></div>`).join('')
                    : '<div class="px-4 py-8 text-center text-sm text-slate-400">Nema novih obavijesti</div>';
            }
        }).catch(() => {});
    }
    setInterval(refresh, 60000);
})();
</script>
{{-- Header dropdowns — outside all stacking contexts so z-index works globally --}}
<div id="notification-menu" class="fixed right-3 top-14 hidden w-80 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl sm:right-5" style="z-index:99999">
    <div class="flex items-center justify-between border-b border-slate-100 px-4 py-2.5">
        <span class="text-sm font-semibold text-slate-900">Obavijesti</span>
        <span id="notification-count" class="rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-bold text-red-700 {{ $headerNotifications->isEmpty() ? 'hidden' : '' }}">{{ $headerNotifications->count() }}</span>
    </div>
    <div id="notification-list" class="max-h-64 overflow-y-auto">
        @forelse($headerNotifications as $notif)
        <div class="flex items-start gap-3 border-b border-slate-50 px-4 py-3 last:border-0">
            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-amber-400"></span>
            <span class="text-xs leading-5 text-slate-600">{{ $notif }}</span>
        </div>
        @empty
        <div class="px-4 py-8 text-center text-sm text-slate-400">Nema novih obavijesti</div>
        @endforelse
    </div>
    <div class="border-t border-slate-100 px-3 py-2">
        <a href="{{ route('project-check.index') }}" class="block rounded-lg px-3 py-2 text-center text-xs font-semibold text-indigo-600 transition-colors hover:bg-indigo-50">
            Otvori provjeru projekta →
        </a>
    </div>
</div>
<div id="profile-menu" class="fixed right-3 top-14 hidden w-56 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl sm:right-5" style="z-index:99999">
    <div class="flex items-center gap-3 border-b border-slate-100 px-4 py-3">
        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full text-sm font-bold text-white" style="background:linear-gradient(135deg,#308dcc,#004f7d)">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr(auth()->user()->name, 0, 2)) }}</span>
        <div class="min-w-0">
            <div class="truncate text-sm font-semibold text-slate-900">{{ auth()->user()->name }}</div>
            <div class="truncate text-xs text-slate-400">{{ auth()->user()->email }}</div>
        </div>
    </div>
    <div class="p-1.5">
        <a href="{{ route('settings.index') }}" class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900">
            <svg viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4 shrink-0 text-slate-400"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/></svg>
            Postavke
        </a>
        <a href="{{ route('reports.index') }}" class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900">
            <svg viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4 shrink-0 text-slate-400"><path fill-rule="evenodd" d="M6 2a2 2 0 00-2 2v12a2 2 0 002 2h8a2 2 0 002-2V7.414A2 2 0 0015.414 6L12 2.586A2 2 0 0010.586 2H6zm2 10a1 1 0 10-2 0v3a1 1 0 102 0v-3zm2-3a1 1 0 011 1v5a1 1 0 11-2 0v-5a1 1 0 011-1zm4-1a1 1 0 10-2 0v7a1 1 0 102 0V8z" clip-rule="evenodd"/></svg>
            Izvještaji
        </a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm text-red-600 transition-colors hover:bg-red-50">
                <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0"><path fill-rule="evenodd" d="M3 4a2 2 0 012-2h5a1 1 0 010 2H5v12h5a1 1 0 110 2H5a2 2 0 01-2-2V4zm10.293 2.293a1 1 0 011.414 0l3 3a1 1 0 010 1.414l-3 3a1 1 0 01-1.414-1.414L14.586 11H8a1 1 0 110-2h6.586l-1.293-1.293a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                Odjava
            </button>
        </form>
    </div>
</div>
@stack('scripts')
</body>
</html>
