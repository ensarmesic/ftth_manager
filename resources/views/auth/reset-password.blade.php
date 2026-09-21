<!DOCTYPE html>
<html lang="bs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Nova lozinka · FTTH Manager</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-950 antialiased">
<main class="grid min-h-screen place-items-center px-4 py-10">
    <section class="w-full max-w-md overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl">
        <div class="bg-linear-to-br from-[#00659e] to-[#003558] px-8 py-7 text-white"><h1 class="text-2xl font-bold">Postavi novu lozinku</h1><p class="mt-1 text-sm text-blue-100">Link vrijedi 60 minuta i može se iskoristiti samo jednom.</p></div>
        <form method="POST" action="{{ route('password.store') }}" class="space-y-5 p-8">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <label class="block"><span class="mb-1.5 block text-sm font-semibold text-slate-700">Email</span><input name="email" type="email" value="{{ old('email', $email) }}" required autocomplete="email" class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 outline-none focus:border-sky-500 focus:ring-3 focus:ring-sky-100"></label>
            <label class="block"><span class="mb-1.5 block text-sm font-semibold text-slate-700">Nova lozinka</span><input name="password" type="password" required autocomplete="new-password" class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 outline-none focus:border-sky-500 focus:ring-3 focus:ring-sky-100"></label>
            <label class="block"><span class="mb-1.5 block text-sm font-semibold text-slate-700">Ponovi novu lozinku</span><input name="password_confirmation" type="password" required autocomplete="new-password" class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 outline-none focus:border-sky-500 focus:ring-3 focus:ring-sky-100"></label>
            @if($errors->any())<div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif
            <button class="w-full rounded-xl bg-[#00659e] px-4 py-2.5 font-semibold text-white hover:bg-[#004f7d]">Sačuvaj novu lozinku</button>
        </form>
    </section>
</main>
</body>
</html>
