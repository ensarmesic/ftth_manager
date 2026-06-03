@php
    $title = $title ?? 'Nema podataka';
    $message = $message ?? 'Dodaj prvi zapis kroz formu ili mapu.';
@endphp

<div class="rounded-md border border-dashed border-slate-300 bg-white px-6 py-10 text-center">
    <h3 class="text-sm font-semibold text-slate-900">{{ $title }}</h3>
    <p class="mx-auto mt-2 max-w-md text-sm text-slate-500">{{ $message }}</p>
</div>
