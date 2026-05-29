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
<body class="{{ $isWide ? 'h-screen overflow-hidden' : 'min-h-screen' }} bg-zinc-100 text-zinc-950 antialiased">
    <div class="{{ $isWide ? 'flex h-screen flex-col overflow-hidden' : 'min-h-screen' }}">
        <header class="shrink-0 border-b border-zinc-800 bg-zinc-950 px-4 py-3 text-white sm:px-6 lg:px-8">
            <div class="mx-auto flex {{ $isWide ? 'max-w-none' : 'max-w-7xl' }} flex-wrap items-center gap-4">
                <a href="{{ route('dashboard') }}" class="mr-2 shrink-0">
                    <span class="block text-lg font-semibold">FTTH Manager</span>
                    <span class="block text-xs text-emerald-200">Planiranje opticke mreze</span>
                </a>

                <nav class="flex min-w-0 flex-1 flex-wrap items-center gap-1 text-sm">
                    @foreach ([
                        'dashboard' => 'Dashboard',
                        'map.index' => 'Mapa mreze',
                        'reports.index' => 'Izvjestaji',
                        'projects.index' => 'Projekti',
                        'odfs.index' => 'ODF lokacije',
                        'cabinets.index' => 'Zeleni ormarici',
                        'subscribers.index' => 'Korisnici',
                        'routes.index' => 'Trase',
                        'materials.index' => 'Materijali',
                    ] as $route => $label)
                        <a class="rounded-md px-3 py-2 transition {{ request()->routeIs($route) ? 'bg-emerald-500 text-zinc-950 font-semibold' : 'text-zinc-300 hover:bg-zinc-800 hover:text-white' }}" href="{{ route($route) }}">{{ $label }}</a>
                    @endforeach
                </nav>

                <div class="hidden rounded-md border border-zinc-800 bg-zinc-900 px-3 py-2 text-xs text-zinc-300 xl:block">
                    <span class="font-semibold text-white">Kapacitet:</span> 1 ormaric = 12 korisnika
                </div>
            </div>
        </header>

        <main class="{{ $isWide ? 'flex min-h-0 flex-1 flex-col overflow-hidden' : '' }} w-full">
            <div class="shrink-0 border-b border-zinc-200 bg-white {{ $isWide ? 'px-3 py-2' : 'px-4 py-3 sm:px-6 lg:px-8' }}">
                <header class="{{ $isWide ? 'w-full max-w-none' : 'mx-auto max-w-7xl' }} flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 class="{{ $isWide ? 'text-lg' : 'text-2xl' }} font-semibold tracking-normal">@yield('title')</h1>
                        <p class="{{ $isWide ? 'text-xs' : 'mt-1 text-sm' }} text-zinc-500">@yield('subtitle')</p>
                    </div>
                </header>
            </div>

            <div class="{{ $isWide ? 'flex min-h-0 flex-1 w-full max-w-none flex-col overflow-hidden px-2 py-2 sm:px-3' : 'mx-auto max-w-7xl px-3 py-6 sm:px-4 lg:px-5' }}">
                @if (session('success'))
                    <div class="mb-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
                @endif

                @if ($errors->any())
                    <div class="mb-5 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>
                @endif

                @yield('content')
            </div>
        </main>
    </div>
</body>
</html>
