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
        ['dashboard', 'Pregled', 'P'], ['projects.index', 'Projekti', 'PR'], ['map.dashboard', 'Mapa', 'M'],
        ['odfs.index', 'ODF-ovi', 'OF'], ['cabinets.index', 'ODO ormarici', 'OO'], ['houses.index', 'Kuce', 'KU'], ['subscribers.index', 'Korisnici', 'K'],
        ['routes.index', 'Trase', 'T'], ['materials.index', 'Materijali', 'MT'], ['reports.index', 'Izvjestaji', 'IZ'],
        ['splitters.index', 'Splitteri', 'S'], ['fiber-schema.index', 'Fiber sema', 'FS'],
        ['project-check.index', 'Provjera projekta', 'OK'], ['settings.index', 'Postavke', 'PS'],
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
    <aside class="hidden w-[204px] shrink-0 bg-gradient-to-b from-[#00659E] to-[#004f7d] text-white lg:flex lg:flex-col">
        <a href="{{ route('dashboard') }}" class="flex h-14 items-center gap-3 border-b border-slate-700/60 px-5 xl:h-16 xl:px-6">
            <span class="grid h-7 w-7 place-items-center rounded bg-[#81C342] text-[10px] font-black">FT</span><span class="text-base font-bold">FTTH Manager</span>
        </a>
        <nav class="grid gap-0 p-2.5 text-[12px] xl:p-3 xl:text-[13px]">
            @foreach ($sidebarItems as [$route, $label, $glyph])
                <a class="flex items-center gap-3 rounded-md px-3 py-1.5 xl:py-2 {{ request()->routeIs($route) ? 'bg-blue-600 font-semibold shadow' : 'text-slate-200 hover:bg-slate-800' }}" href="{{ route($route) }}"><span class="w-4 text-center text-[10px] font-bold">{{ $glyph }}</span>{{ $label }}</a>
            @endforeach
        </nav>
        @if($isDashboard && isset($odfs, $cabinets, $stats, $routes))
            <div class="mx-5 mt-1 border-t border-slate-700/70 pt-2 text-[10px] xl:pt-3 xl:text-[11px]">
                <b class="mb-2 block text-slate-300">BRZI PREGLED</b>
                @foreach([['ODF-ovi', $odfs->count()], ['ODO ormarici', $cabinets->count()], ['Korisnici', $stats['subscribers']], ['Trase', $routes->count()], ['Ukupna duzina', number_format($stats['routes_m'] / 1000, 2).' km']] as $quick)
                    <div class="flex justify-between py-1 text-slate-300 xl:py-1.5"><span>{{ $quick[0] }}</span><b class="text-white">{{ $quick[1] }}</b></div>
                @endforeach
            </div>
        @endif
    </aside>

    <main class="{{ $isWide ? 'flex min-h-0 flex-1 flex-col lg:overflow-hidden' : '' }} min-w-0 flex-1">
        <header class="relative z-[1300] flex h-[56px] shrink-0 items-center justify-between gap-2 border-b bg-white px-2 shadow-sm sm:h-[60px] sm:px-4 xl:px-5">
            <div class="flex min-w-0 items-center gap-2 text-sm xl:gap-5">
                <button type="button" data-header-action="mobile-menu" class="rounded-md p-2 text-slate-700 hover:bg-slate-100 lg:hidden" aria-label="Otvori meni">Meni</button>
                <span class="text-sm font-black text-blue-600">FT</span>
                <div class="hidden min-w-0 max-w-[315px] items-center gap-3 rounded-md border border-slate-200 px-3 py-2 sm:flex sm:min-w-[220px] xl:min-w-[315px]"><b>Stranica:</b><span class="truncate">@yield('title')</span></div>
            </div>
            <div class="relative flex items-center gap-1 text-sm xl:gap-2">
                <button type="button" data-header-action="search" class="rounded-md p-2 text-slate-600 hover:bg-slate-100">Trazi</button>
                <button type="button" data-header-action="fullscreen" class="hidden rounded-md p-2 text-slate-600 hover:bg-slate-100 sm:inline">Ekran</button>
                <a href="{{ route('project-check.index') }}" data-header-action="notifications" class="relative rounded-md p-2 text-slate-600 hover:bg-slate-100"><span class="hidden sm:inline">Obavijesti</span><span class="sm:hidden">Obav.</span> @if($headerNotifications->count())<b data-notification-badge class="absolute -right-1 -top-1 rounded-full bg-red-500 px-1 text-[9px] text-white">{{ $headerNotifications->count() }}</b>@endif</a>
                <button type="button" data-header-action="profile" class="flex items-center gap-2 rounded-md p-1 hover:bg-slate-100"><span class="grid h-9 w-9 place-items-center rounded-full bg-slate-200 font-bold">EM</span><span class="hidden xl:inline text-left"><b>Ensar Mesic</b><small class="block text-[11px] text-slate-500">Administrator</small></span></button>
                <div id="profile-menu" class="fixed right-2 top-14 z-[2200] hidden w-52 rounded-lg border border-slate-200 bg-white p-2 shadow-xl sm:right-4 sm:top-16"><div class="px-3 py-2 text-xs text-slate-500">Administrator</div><a href="{{ route('settings.index') }}" class="block rounded px-3 py-2 text-sm hover:bg-slate-100">Postavke</a><a href="{{ route('reports.index') }}" class="block rounded px-3 py-2 text-sm hover:bg-slate-100">Izvjestaji</a></div>
            </div>
        </header>
        @unless($isDashboard)
            <div class="shrink-0 border-b bg-white px-3 py-3 sm:px-5"><h1 class="text-lg font-semibold sm:text-xl">@yield('title')</h1><p class="mt-1 text-xs text-slate-500">@yield('subtitle')</p></div>
        @endunless
        <div class="app-content {{ $isWide ? 'flex h-full min-h-0 flex-1 flex-col p-1.5 sm:p-2 lg:overflow-hidden xl:p-2.5' : 'mx-auto w-full max-w-[min(1880px,calc(100vw-2rem))] px-3 py-4 sm:px-5 sm:py-6 xl:px-7' }}">
            @if (session('success'))<div class="mb-3 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>@endif
            @if ($errors->any())<div class="mb-3 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>@endif
            @yield('content')
        </div>
        @if($isDashboard)<footer class="flex min-h-8 shrink-0 flex-wrap items-center justify-center gap-x-3 border-t bg-white px-2 py-1 text-center text-[10px] text-slate-500">&copy; 2026 Mediasky · FTTH Manager <span>v1.0.0</span></footer>@endif
    </main>
</div>
<div id="mobile-sidebar-backdrop" class="fixed inset-0 z-[1350] hidden bg-slate-950/50 lg:hidden"></div>
<aside id="mobile-sidebar" class="fixed inset-y-0 left-0 z-[1400] hidden w-[min(280px,88vw)] overflow-y-auto bg-gradient-to-b from-[#00659E] to-[#004f7d] text-white shadow-2xl lg:hidden">
    <div class="flex h-14 items-center justify-between border-b border-slate-700/60 px-4"><a href="{{ route('dashboard') }}" class="font-bold">FTTH Manager</a><button type="button" data-header-action="close-mobile-menu" class="rounded px-3 py-2 text-sm hover:bg-slate-800">Zatvori</button></div>
    <nav class="grid gap-1 p-3 text-sm">
        @foreach ($sidebarItems as [$route, $label, $glyph])
            <a class="flex items-center gap-3 rounded-md px-3 py-2.5 {{ request()->routeIs($route) ? 'bg-blue-600 font-semibold shadow' : 'text-slate-200 hover:bg-slate-800' }}" href="{{ route($route) }}"><span class="w-5 text-center text-[10px] font-bold">{{ $glyph }}</span>{{ $label }}</a>
        @endforeach
    </nav>
</aside>
<div id="global-search" class="fixed inset-0 z-[1300] hidden place-items-start bg-slate-950/40 px-4 pt-24">
    <div class="mx-auto w-full max-w-xl rounded-xl bg-white p-4 shadow-2xl">
        <div class="flex gap-2"><input id="global-search-input" class="min-w-0 flex-1 rounded-md border border-slate-300 px-3 py-2" placeholder="Pretrazi meni..."><button type="button" data-header-action="close-search" class="rounded-md bg-slate-100 px-3 py-2 text-sm font-semibold">Zatvori</button></div>
        <div id="global-search-results" class="mt-3 grid gap-1"></div>
    </div>
</div>
<div id="delete-confirmation-modal" class="fixed inset-0 z-[1500] hidden place-items-center bg-slate-950/50 px-4">
    <div class="w-full max-w-md rounded-md bg-white p-5 shadow-2xl">
        <h2 class="text-base font-semibold text-slate-950">Potvrdi brisanje</h2>
        <p id="delete-confirmation-message" class="mt-2 text-sm text-slate-600">Sigurno obrisati ovaj zapis?</p>
        <div class="mt-5 flex justify-end gap-2">
            <button type="button" data-delete-modal-action="cancel" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Ponisti</button>
            <button type="button" data-delete-modal-action="confirm" class="rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-700">Obrisi</button>
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
    if (id === 'notification-menu') {
        document.querySelector('[data-header-action="notifications"]')?.setAttribute('aria-expanded', opening ? 'true' : 'false');
    }
}
function renderGlobalSearch(value = '') { const results = ftthMenuItems.filter(([label]) => label.toLowerCase().includes(value.toLowerCase())); document.getElementById('global-search-results').innerHTML = results.map(([label, url]) => `<a class="rounded-md px-3 py-2 text-sm hover:bg-slate-100" href="${url}">${label}</a>`).join(''); }
document.querySelector('[data-header-action="search"]')?.addEventListener('click', () => { const modal = document.getElementById('global-search'); modal.classList.remove('hidden'); modal.classList.add('grid'); renderGlobalSearch(); document.getElementById('global-search-input').focus(); });
document.querySelector('[data-header-action="close-search"]')?.addEventListener('click', () => document.getElementById('global-search').classList.add('hidden'));
document.getElementById('global-search-input')?.addEventListener('input', event => renderGlobalSearch(event.target.value));
document.querySelector('[data-header-action="fullscreen"]')?.addEventListener('click', () => document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen());
document.querySelector('[data-header-action="profile"]')?.addEventListener('click', event => { event.stopPropagation(); toggleHeaderMenu('profile-menu'); });
document.getElementById('profile-menu')?.addEventListener('click', event => event.stopPropagation());
document.addEventListener('click', () => closeHeaderMenus());
function toggleMobileSidebar(open) { document.getElementById('mobile-sidebar')?.classList.toggle('hidden', !open); document.getElementById('mobile-sidebar-backdrop')?.classList.toggle('hidden', !open); }
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
        modal.classList.remove('hidden');
        modal.classList.add('grid');
    });
});
document.querySelector('[data-delete-modal-action="cancel"]')?.addEventListener('click', () => {
    pendingDeleteForm = null;
    document.getElementById('delete-confirmation-modal')?.classList.add('hidden');
});
document.querySelector('[data-delete-modal-action="confirm"]')?.addEventListener('click', () => {
    const form = pendingDeleteForm;
    pendingDeleteForm = null;
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
