@extends('ftth.layout')

@section('title', 'Uključi 2FA')

@section('content')
<div class="mx-auto max-w-xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
    <h1 class="text-xl font-black text-slate-900">Uključi dvofaktorsku autentifikaciju</h1>
    <p class="mt-2 text-sm text-slate-600">Skeniraj QR kod u Google Authenticator, Microsoft Authenticator ili kompatibilnoj TOTP aplikaciji.</p>
    <img src="{{ $qrDataUri }}" alt="QR kod za dvofaktorsku autentifikaciju" class="mx-auto my-5 h-60 w-60">
    <div class="rounded-lg bg-slate-100 p-3 text-center font-mono text-sm font-bold tracking-wider text-slate-800">{{ $secret }}</div>
    <form method="POST" action="{{ route('two-factor.confirm') }}" class="mt-5 space-y-4">
        @csrf
        <label class="block"><span class="mb-1 block text-sm font-bold">Kod iz aplikacije</span><input name="code" inputmode="numeric" maxlength="6" required autocomplete="one-time-code" class="field-input w-full"></label>
        @error('code')<div class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ $message }}</div>@enderror
        <button class="btn-save">Potvrdi i uključi 2FA</button>
        <a href="{{ route('settings.index') }}" class="ml-3 text-sm font-bold text-slate-500">Odustani</a>
    </form>
</div>
@endsection
