<!DOCTYPE html>
<html lang="bs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>FTTH Manager</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <script src="https://cdn.tailwindcss.com"></script>
    @endif
    <link rel="stylesheet" href="{{ asset('css/ftth-app.css') }}">
    <style>
        body { line-height: 1.4; }
        .app-content table { font-size: .875rem; }
        .app-content table th, .app-content table td { line-height: 1.25; }
        .leaflet-popup-content, .leaflet-tooltip { font-size: .78rem; line-height: 1.2; }
    </style>
</head>
@php
    $isWide = trim($__env->yieldContent('wide'));
    $isDashboard = request()->routeIs('dashboard', 'map.dashboard');
    $sidebarItems = [
        ['dashboard',           'Pregled',            'squares'],
        ['projects.index',      'Projekti',           'folder'],
        ['map.dashboard',       'Mapa',               'map'],
        ['odfs.index',          'ODF-ovi',            'server'],
        ['cabinets.index',      'ODO ormarici',       'archive'],
        ['houses.index',        'Kuce',               'home'],
        ['subscribers.index',   'Korisnici',          'users'],
        ['routes.index',        'Trase',              'route'],
        ['materials.index',     'Materijali',         'cube'],
        ['reports.index',       'Izvjestaji',         'chart'],
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
        'cube'    => '<svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path d="M11 17a1 1 0 001.447.894l4-2A1 1 0 0017 15V9.236a1 1 0 00-1.447-.894l-4 2a1 1 0 00-.553.894V17zM15.211 6.276a1 1 0 000-1.788l-4.764-2.382a1 1 0 00-.894 0L4.789 4.488a1 1 0 000 1.788l4.764 2.382a1 1 0 00.894 0l4.764-2.382zM4.447 8.342A1 1 0 003 9.236V15a1 1 0 00.553.894l4 2A1 1 0 009 17v-5.764a1 1 0 00-.553-.894l-4-2z"/></svg>',
        'chart'   => '<svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6 2a2 2 0 00-2 2v12a2 2 0 002 2h8a2 2 0 002-2V7.414A2 2 0 0015.414 6L12 2.586A2 2 0 0010.586 2H6zm2 10a1 1 0 10-2 0v3a1 1 0 102 0v-3zm2-3a1 1 0 011 1v5a1 1 0 11-2 0v-5a1 1 0 011-1zm4-1a1 1 0 10-2 0v7a1 1 0 102 0V8z" clip-rule="evenodd"/></svg>',
        'split'   => '<svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/><path fill-rule="evenodd" d="M3 10a7 7 0 1114 0 7 7 0 01-14 0zm7-5a5 5 0 100 10A5 5 0 0010 5z" clip-rule="evenodd"/></svg>',
        'chip'    => '<svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2 5a2 2 0 012-2h8a2 2 0 012 2v10a2 2 0 002 2H4a2 2 0 01-2-2V5zm3 1h6v4H5V6zm6 6H5v2h6v-2z" clip-rule="evenodd"/><path d="M15 7h1a2 2 0 012 2v5.5a1.5 1.5 0 01-3 0V7z"/></svg>',
        'shield'  => '<svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>',
        'cog'     => '<svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/></svg>',
    ];
    $headerNotifications = collect();
    $unlinkedHouses = \App\Models\House::whereNull('cabinet_id')->count();
    $unlinkedCabinets = \App\Models\Cabinet::whereNull('odf_id')->count();
    $incompleteRoutes = \App\Models\NetworkRoute::where(function ($query) { $query->whereNull('microduct_type')->orWhereNull('fiber_count'); })->count();
    if ($unlinkedHouses) $headerNotifications->push("$unlinkedHouses kuca nema dodijeljeni ODO.");
    if ($unlinkedCabinets) $headerNotifications->push("$unlinkedCabinets ODO ormarica nema povezani ODF.");
    if ($incompleteRoutes) $headerNotifications->push("$incompleteRoutes trasa nema kompletne tehnicke podatke.");
@endphp
<body class="{{ $isWide ? 'min-h-screen lg:h-screen lg:overflow-hidden' : 'min-h-screen' }} overflow-x-hidden bg-slate-100 font-sans text-slate-950 antialiased">
<div class="{{ $isWide ? 'flex min-h-screen lg:h-screen lg:overflow-hidden' : 'flex min-h-screen' }}">
    <aside class="hidden w-52 shrink-0 bg-linear-to-b from-[#004f7d] to-[#003558] text-white lg:flex lg:flex-col" style="box-shadow: 4px 0 24px rgba(0,0,0,.18);">
        <a href="{{ route('dashboard') }}" class="flex h-14 items-center gap-3 border-b border-white/10 px-5 xl:h-16 xl:px-6">
            <span class="grid h-7 w-7 place-items-center rounded-lg text-[10px] font-black" style="background:linear-gradient(135deg,#81C342,#4f8934)">FT</span>
            <span class="text-sm font-bold tracking-wide">FTTH Manager</span>
        </a>
        <nav class="flex flex-col p-2 text-[12px] flex-1 overflow-y-auto" style="gap:1px">
            @foreach ($sidebarItems as [$route, $label, $iconKey])
                <a class="flex items-center gap-2 rounded px-2.5 py-1.25 leading-none transition-colors {{ request()->routeIs($route) ? 'bg-white/15 font-semibold text-white' : 'text-blue-100/75 hover:bg-white/10 hover:text-white' }}" href="{{ route($route) }}">
                    <span class="shrink-0 opacity-70">{!! $sidebarIcons[$iconKey] ?? '' !!}</span>
                    {{ $label }}
                </a>
            @endforeach
        </nav>
        @if($isDashboard && isset($odfs, $cabinets, $stats, $routes))
            <div class="mx-4 mb-4 mt-1 rounded-xl border border-white/10 p-3 text-[10px] xl:text-[11px]" style="background:rgba(255,255,255,.06)">
                <b class="mb-2 block text-blue-200 text-[9.5px] uppercase tracking-widest">Brzi pregled</b>
                @foreach([['ODF-ovi', $odfs->count()], ['ODO ormarici', $cabinets->count()], ['Korisnici', $stats['subscribers']], ['Trase', $routes->count()], ['Ukupna duzina', number_format($stats['routes_m'] / 1000, 2).' km']] as $quick)
                    <div class="flex justify-between py-1 text-blue-200"><span>{{ $quick[0] }}</span><b class="text-white">{{ $quick[1] }}</b></div>
                @endforeach
            </div>
        @endif
    </aside>

    <main class="{{ $isWide ? 'flex min-h-0 flex-1 flex-col lg:overflow-hidden' : '' }} min-w-0 flex-1">
        <header class="relative z-1300 flex h-14 shrink-0 items-center justify-between gap-2 border-b bg-white px-2 shadow-sm sm:h-15 sm:px-4 xl:px-5">
            <div class="flex min-w-0 items-center gap-2 text-sm xl:gap-4">
                <button type="button" data-header-action="mobile-menu" class="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden" aria-label="Otvori meni">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
                </button>
                <span class="text-sm font-black text-blue-600 hidden sm:inline">FT</span>
                <div class="hidden min-w-0 max-w-75 items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 sm:flex">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5 text-slate-400 shrink-0"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
                    <span class="truncate text-xs font-semibold text-slate-700">@yield('title')</span>
                </div>
            </div>
            <div class="relative flex items-center gap-1 text-sm xl:gap-2">
                <button type="button" data-header-action="search" class="flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>
                    <span class="hidden sm:inline">Pretraži</span>
                </button>
                <button type="button" data-header-action="fullscreen" class="hidden rounded-lg p-2 text-slate-600 hover:bg-slate-100 sm:inline-flex items-center">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4"><path fill-rule="evenodd" d="M3 4a1 1 0 011-1h4a1 1 0 010 2H6.414l2.293 2.293a1 1 0 01-1.414 1.414L5 6.414V8a1 1 0 01-2 0V4zm9 1a1 1 0 010-2h4a1 1 0 011 1v4a1 1 0 01-2 0V6.414l-2.293 2.293a1 1 0 11-1.414-1.414L13.586 5H12zm-9 7a1 1 0 012 0v1.586l2.293-2.293a1 1 0 011.414 1.414L6.414 15H8a1 1 0 010 2H4a1 1 0 01-1-1v-4zm13-1a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 010-2h1.586l-2.293-2.293a1 1 0 011.414-1.414L15 13.586V12a1 1 0 011-1z" clip-rule="evenodd"/></svg>
                </button>
                <a href="{{ route('project-check.index') }}" data-header-action="notifications" class="relative flex items-center gap-1.5 rounded-lg px-2 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg>
                    <span class="hidden sm:inline">Obavijesti</span>
                    @if($headerNotifications->count())<span data-notification-badge class="absolute right-1 top-1 h-4 w-4 rounded-full bg-red-500 text-[9px] font-black text-white flex items-center justify-center">{{ $headerNotifications->count() }}</span>@endif
                </a>
                <button type="button" data-header-action="profile" class="flex items-center gap-2 rounded-lg p-1 hover:bg-slate-100">
                    <span class="grid h-8 w-8 place-items-center rounded-full font-bold text-sm text-white" style="background:linear-gradient(135deg,#308dcc,#004f7d)">EM</span>
                    <span class="hidden xl:block text-left"><b class="block text-xs text-slate-800">Ensar Mešić</b><small class="text-[10px] text-slate-500">Administrator</small></span>
                </button>
                <div id="profile-menu" class="fixed right-2 top-14 z-2200 hidden w-52 rounded-xl border border-slate-200 bg-white p-2 shadow-2xl sm:right-4 sm:top-16">
                    <div class="px-3 py-2 text-xs text-slate-400 font-semibold uppercase tracking-wider">Administrator</div>
                    <a href="{{ route('settings.index') }}" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
                        <svg viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4 text-slate-400"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/></svg>
                        Postavke
                    </a>
                    <a href="{{ route('reports.index') }}" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
                        <svg viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4 text-slate-400"><path fill-rule="evenodd" d="M6 2a2 2 0 00-2 2v12a2 2 0 002 2h8a2 2 0 002-2V7.414A2 2 0 0015.414 6L12 2.586A2 2 0 0010.586 2H6zm2 10a1 1 0 10-2 0v3a1 1 0 102 0v-3zm2-3a1 1 0 011 1v5a1 1 0 11-2 0v-5a1 1 0 011-1zm4-1a1 1 0 10-2 0v7a1 1 0 102 0V8z" clip-rule="evenodd"/></svg>
                        Izvještaji
                    </a>
                </div>
            </div>
        </header>
        @unless($isDashboard)
            <div class="ftth-page-header">
                <h1>@yield('title')</h1>
                <p>@yield('subtitle')</p>
            </div>
        @endunless
        <div class="app-content {{ $isWide ? 'flex h-full min-h-0 flex-1 flex-col p-1.5 sm:p-2 lg:overflow-hidden xl:p-2.5' : 'mx-auto w-full max-w-[min(1880px,calc(100vw-2rem))] px-3 py-5 sm:px-5 sm:py-6 xl:px-7' }}">
            @if (session('success'))<div class="flash-success"><svg viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4 shrink-0"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>{{ session('success') }}</div>@endif
            @if ($errors->any())<div class="flash-error"><svg viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4 shrink-0"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>{{ $errors->first() }}</div>@endif
            @yield('content')
        </div>
        @if($isDashboard)<footer class="flex min-h-8 shrink-0 flex-wrap items-center justify-center gap-x-3 border-t bg-white px-2 py-1 text-center text-[10px] text-slate-500">&copy; 2026 Mediasky · FTTH Manager <span>v1.0.0</span></footer>@endif
    </main>
</div>
<div id="mobile-sidebar-backdrop" class="fixed inset-0 z-1350 hidden bg-slate-950/50 lg:hidden"></div>
<aside id="mobile-sidebar" class="fixed inset-y-0 left-0 z-1400 hidden w-[min(280px,88vw)] overflow-y-auto bg-linear-to-b from-[#004f7d] to-[#003558] text-white shadow-2xl lg:hidden">
    <div class="flex h-14 items-center justify-between border-b border-white/10 px-4">
        <a href="{{ route('dashboard') }}" class="font-bold text-sm">FTTH Manager</a>
        <button type="button" data-header-action="close-mobile-menu" class="rounded-lg px-3 py-2 text-xs font-semibold hover:bg-white/10">Zatvori</button>
    </div>
    <nav class="grid gap-px p-2 text-[13px]">
        @foreach ($sidebarItems as [$route, $label, $iconKey])
            <a class="flex items-center gap-2.5 rounded-md px-3 py-2 transition-colors {{ request()->routeIs($route) ? 'bg-white/15 font-semibold text-white' : 'text-blue-100/80 hover:bg-white/10 hover:text-white' }}" href="{{ route($route) }}">
                <span class="shrink-0 opacity-75">{!! $sidebarIcons[$iconKey] ?? '' !!}</span>
                {{ $label }}
            </a>
        @endforeach
    </nav>
</aside>
<div id="global-search" class="fixed inset-0 z-1300 hidden place-items-start bg-slate-950/50 px-4 pt-24 backdrop-blur-sm">
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
    ['ODF-ovi', @json(route('odfs.index'))], ['ODO ormarici', @json(route('cabinets.index'))], ['Kuce', @json(route('houses.index'))], ['Korisnici', @json(route('subscribers.index'))],
    ['Trase', @json(route('routes.index'))], ['Materijali', @json(route('materials.index'))], ['Izvjestaji', @json(route('reports.index'))],
    ['Splitteri', @json(route('splitters.index'))], ['Fiber sema', @json(route('fiber-schema.index'))], ['Provjera projekta', @json(route('project-check.index'))],
    ['Postavke', @json(route('settings.index'))],
];
function closeHeaderMenus(except = '') {
    ['notification-menu', 'profile-menu'].forEach(id => {
        if (id === except) return;
        document.getElementById(id)?.classList.add('hidden');
    });
    document.querySelector('[data-header-action="notifications"]')?.setAttribute('aria-expanded', 'false');
}
function toggleHeaderMenu(id) {
    const menu = document.getElementById(id);
    if (!menu) return;
    const opening = menu.classList.contains('hidden');
    closeHeaderMenus(id);
    menu.classList.toggle('hidden', !opening);
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
document.querySelector('[data-header-action="profile"]')?.addEventListener('click', e => { e.stopPropagation(); toggleHeaderMenu('profile-menu'); });
document.getElementById('profile-menu')?.addEventListener('click', e => e.stopPropagation());
document.addEventListener('click', () => closeHeaderMenus());
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
const savedFtthSettings = JSON.parse(localStorage.getItem('ftthSettings') || '{}');
document.documentElement.classList.toggle('compact-tables', Boolean(savedFtthSettings.compactTables));
document.documentElement.classList.toggle('small-markers', savedFtthSettings.smallMarkers !== false);
document.documentElement.classList.toggle('hide-header-notifications', savedFtthSettings.notifications === false);
</script>
@stack('scripts')
</body>
</html>
