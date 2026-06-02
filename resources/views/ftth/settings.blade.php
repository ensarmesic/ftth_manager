@extends('ftth.layout')

@section('title', 'Postavke')
@section('subtitle', 'Lokalne postavke prikaza FTTH Manager aplikacije.')

@section('content')
<section class="grid gap-5 lg:grid-cols-[minmax(0,560px)_1fr]">
    <form id="display-settings" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="font-bold">Prikaz aplikacije</h2>
        <p class="mt-1 text-sm text-slate-500">Postavke se cuvaju u ovom browseru.</p>
        <div class="mt-5 grid gap-4">
            <label class="flex items-center justify-between gap-4 rounded-md border border-slate-200 p-3 text-sm"><span>Kompaktni prikaz tabela</span><input type="checkbox" name="compactTables"></label>
            <label class="flex items-center justify-between gap-4 rounded-md border border-slate-200 p-3 text-sm"><span>Smanjene oznake na mapi</span><input type="checkbox" name="smallMarkers" checked></label>
            <label class="flex items-center justify-between gap-4 rounded-md border border-slate-200 p-3 text-sm"><span>Prikazi brojac obavjestenja u headeru</span><input type="checkbox" name="notifications" checked></label>
            <button class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Sacuvaj postavke</button>
            <div id="settings-status" class="text-sm font-semibold text-emerald-700"></div>
        </div>
    </form>
    <aside class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="font-bold">Sistem</h2>
        <dl class="mt-4 grid gap-y-2 text-sm sm:grid-cols-[130px_1fr] sm:gap-y-3"><dt class="text-slate-500">Aplikacija</dt><dd>FTTH Manager</dd><dt class="text-slate-500">Verzija</dt><dd>1.0.0</dd><dt class="text-slate-500">Mapa</dt><dd>Leaflet + Esri / OpenStreetMap</dd><dt class="text-slate-500">Okruzenje</dt><dd>{{ app()->environment() }}</dd></dl>
    </aside>
</section>
@endsection

@push('scripts')
<script>
const settingsForm = document.getElementById('display-settings');
const settings = JSON.parse(localStorage.getItem('ftthSettings') || '{}');
['compactTables', 'smallMarkers', 'notifications'].forEach(name => {
    if (settings[name] !== undefined) settingsForm.elements[name].checked = Boolean(settings[name]);
});
settingsForm.addEventListener('submit', event => {
    event.preventDefault();
    const next = Object.fromEntries(['compactTables', 'smallMarkers', 'notifications'].map(name => [name, settingsForm.elements[name].checked]));
    localStorage.setItem('ftthSettings', JSON.stringify(next));
    document.documentElement.classList.toggle('compact-tables', next.compactTables);
    document.documentElement.classList.toggle('small-markers', next.smallMarkers);
    document.documentElement.classList.toggle('hide-header-notifications', !next.notifications);
    document.getElementById('settings-status').textContent = 'Postavke su sacuvane.';
});
</script>
@endpush
