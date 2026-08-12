<!DOCTYPE html>
<html lang="bs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Prijava · FTTH Manager</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-950 antialiased">
    <main class="grid min-h-screen place-items-center px-4 py-10">
        <section class="w-full max-w-md overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl">
            <div class="bg-linear-to-br from-[#00659e] to-[#003558] px-8 py-7 text-white">
                <div class="mb-5 inline-flex rounded-xl bg-white/95 px-4 py-3 shadow-sm">
                    <img src="{{ asset('images/logo.png') }}" alt="Media Sky Telekomunikacije" class="h-auto w-52 object-contain">
                </div>
                <h1 class="text-2xl font-bold">FTTH Manager</h1>
                <p class="mt-1 text-sm text-blue-100">Prijavi se za pristup projektima i mrežnim podacima.</p>
            </div>

            <form method="POST" action="{{ route('login.store') }}" class="space-y-5 p-8">
                @csrf

                <label class="block">
                    <span class="mb-1.5 block text-sm font-semibold text-slate-700">Korisničko ime</span>
                    <input name="username" type="text" value="{{ old('username') }}" required autofocus autocomplete="username"
                        class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 outline-none transition focus:border-sky-500 focus:ring-3 focus:ring-sky-100">
                </label>

                <label class="block">
                    <span class="mb-1.5 block text-sm font-semibold text-slate-700">Lozinka</span>
                    <input name="password" type="password" required autocomplete="current-password"
                        class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 outline-none transition focus:border-sky-500 focus:ring-3 focus:ring-sky-100">
                </label>

                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input name="remember" type="checkbox" value="1" class="rounded border-slate-300">
                    Zapamti prijavu
                </label>

                @if ($errors->any())
                    <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
                @endif

                <button type="submit" class="w-full rounded-xl bg-[#00659e] px-4 py-2.5 font-semibold text-white transition hover:bg-[#004f7d]">
                    Prijavi se
                </button>
            </form>
        </section>
    </main>
</body>
</html>
