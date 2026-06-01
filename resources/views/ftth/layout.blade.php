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
</head>
@php($isWide = trim($__env->yieldContent('wide')))
@php($isDashboard = request()->routeIs('dashboard', 'map.dashboard'))
<body class="{{ $isWide ? 'min-h-screen xl:h-screen xl:overflow-hidden' : 'min-h-screen' }} bg-slate-100 font-sans text-slate-950 antialiased">
<div class="{{ $isWide ? 'flex min-h-screen xl:h-screen xl:overflow-hidden' : 'flex min-h-screen' }}">
    <aside class="hidden w-[204px] shrink-0 bg-gradient-to-b from-[#06182d] to-[#08233d] text-white lg:flex lg:flex-col">
        <a href="{{ route('dashboard') }}" class="flex h-14 items-center gap-3 border-b border-slate-700/60 px-5 xl:h-16 xl:px-6">
            <span class="text-3xl font-light text-blue-500">♆</span><span class="text-base font-bold">FTTH Manager</span>
        </a>
        <nav class="grid gap-0 p-2.5 text-[12px] xl:p-3 xl:text-[13px]">
            @foreach ([
                ['dashboard', 'Pregled', '⌂'],
                ['projects.index', 'Projekti', '▣'],
                ['map.dashboard', 'Mapa', '◇'],
                ['odfs.index', 'ODF-ovi', '▦'],
                ['cabinets.index', 'ODO ormarici', '▤'],
                ['subscribers.index', 'Korisnici', '♧'],
                ['routes.index', 'Trase', '⌁'],
                ['materials.index', 'Materijali', '▱'],
                ['reports.index', 'Izvjestaji', '▧'],
            ] as [$route, $label, $glyph])
                <a class="flex items-center gap-3 rounded-md px-3 py-1.5 xl:py-2 {{ request()->routeIs($route) ? 'bg-blue-600 font-semibold shadow' : 'text-slate-200 hover:bg-slate-800' }}" href="{{ route($route) }}"><span class="w-4 text-center text-base">{{ $glyph }}</span>{{ $label }}</a>
            @endforeach
            <span class="flex items-center gap-3 rounded-md px-3 py-1.5 xl:py-2 text-slate-200"><span class="w-4 text-center">⌘</span> Splitteri</span>
            <span class="flex items-center gap-3 rounded-md px-3 py-1.5 xl:py-2 text-slate-200"><span class="w-4 text-center">⌁</span> Fiber sema</span>
            <span class="flex items-center gap-3 rounded-md px-3 py-1.5 xl:py-2 text-slate-200"><span class="w-4 text-center">✓</span> Provjera projekta</span>
            <span class="flex items-center gap-3 rounded-md px-3 py-1.5 xl:py-2 text-slate-200"><span class="w-4 text-center">⚙</span> Postavke</span>
        </nav>
        @if($isDashboard)
            <div class="mx-5 mt-1 border-t border-slate-700/70 pt-2 text-[10px] xl:pt-3 xl:text-[11px]">
                <b class="mb-2 block text-slate-300">BRZI PREGLED</b>
                @foreach([['ODF-ovi', $odfs->count()], ['ODO ormarici', $cabinets->count()], ['Korisnici', $stats['subscribers']], ['Trase', $routes->count()], ['Ukupna duzina', number_format($stats['routes_m'] / 1000, 2).' km']] as $quick)
                    <div class="flex justify-between py-1 text-slate-300 xl:py-1.5"><span>{{ $quick[0] }}</span><b class="text-white">{{ $quick[1] }}</b></div>
                @endforeach
            </div>
        @endif
        <div class="mt-auto px-7 pb-7 text-slate-300"><b class="text-lg tracking-wider text-white">mediasky</b><small class="block tracking-[.35em] text-blue-400">telecom</small></div>
    </aside>

    <main class="{{ $isWide ? 'flex min-h-0 flex-1 flex-col xl:overflow-hidden' : '' }} min-w-0 flex-1">
        <header class="flex h-[60px] shrink-0 items-center justify-between border-b bg-white px-5 shadow-sm">
            <div class="flex min-w-0 items-center gap-3 text-sm xl:gap-5">
                <span class="text-xl">☰</span>
                <div class="flex min-w-0 max-w-[315px] items-center gap-3 rounded-md border border-slate-200 px-3 py-2 sm:min-w-[280px] xl:min-w-[315px]"><b>Projekat:</b><span class="truncate">@yield('subtitle')</span><span class="ml-auto">⌄</span></div>
            </div>
            <div class="flex items-center gap-3 text-sm xl:gap-5"><span class="hidden text-lg sm:inline">⌕</span><span class="hidden sm:inline">⛶</span><span class="relative">♧<b class="absolute -right-2 -top-2 rounded-full bg-red-500 px-1 text-[9px] text-white">3</b></span><span class="grid h-9 w-9 place-items-center rounded-full bg-slate-200 font-bold">EM</span><span class="hidden xl:inline"><b>Ensar Mesic</b><small class="block text-[11px] text-slate-500">Administrator</small></span><span class="hidden sm:inline">⌄</span></div>
        </header>
        @unless($isDashboard)
            <div class="shrink-0 border-b bg-white px-5 py-3"><h1 class="text-xl font-semibold">@yield('title')</h1><p class="mt-1 text-xs text-slate-500">@yield('subtitle')</p></div>
        @endunless
        <div class="{{ $isWide ? 'flex h-full min-h-0 flex-1 flex-col p-2 xl:overflow-hidden xl:p-2.5' : 'mx-auto max-w-7xl px-4 py-6' }}">
            @if (session('success'))<div class="mb-3 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>@endif
            @if ($errors->any())<div class="mb-3 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>@endif
            @yield('content')
        </div>
        @if($isDashboard)<footer class="flex h-8 shrink-0 items-center justify-center border-t bg-white text-[10px] text-slate-500">© 2025 Mediasky Telecom - FTTH Manager <span class="absolute right-5">Verzija 1.0.0</span></footer>@endif
    </main>
</div>
</body>
</html>
