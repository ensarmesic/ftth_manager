@php
    $title = $title ?? 'Nema podataka';
    $message = $message ?? 'Dodaj prvi zapis kroz formu ili mapu.';
@endphp
<div class="empty-state-wrap">
    <div class="empty-state-ico">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
        </svg>
    </div>
    <p class="empty-state-ttl">{{ $title }}</p>
    <p class="empty-state-msg">{{ $message }}</p>
</div>
