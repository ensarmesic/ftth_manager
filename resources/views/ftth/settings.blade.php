@extends('ftth.layout')
@section('title', 'Postavke')
@section('subtitle', 'Lokalne postavke prikaza FTTH Manager aplikacije.')
@section('content')

<section class="grid gap-5 lg:grid-cols-[minmax(0,540px)_1fr]">
    <form id="display-settings" class="page-form">
        <div class="page-form-header">
            <div class="page-form-icon">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/></svg>
            </div>
            <h2>Prikaz aplikacije</h2>
        </div>
        <div class="page-form-body">
            <p class="text-xs text-slate-400 -mt-1">Postavke se čuvaju u ovom browseru.</p>
            <label class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-700 cursor-pointer hover:bg-slate-100 transition-colors">
                <span>Kompaktni prikaz tabela</span>
                <input type="checkbox" name="compactTables" class="w-4 h-4 rounded accent-blue-600">
            </label>
            <label class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-700 cursor-pointer hover:bg-slate-100 transition-colors">
                <span>Smanjene oznake na mapi</span>
                <input type="checkbox" name="smallMarkers" checked class="w-4 h-4 rounded accent-blue-600">
            </label>
            <label class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-700 cursor-pointer hover:bg-slate-100 transition-colors">
                <span>Prikaži brojač obavještenja u headeru</span>
                <input type="checkbox" name="notifications" checked class="w-4 h-4 rounded accent-blue-600">
            </label>
            <button class="btn-save">Sačuvaj postavke</button>
            <div id="settings-status" class="text-sm font-semibold text-emerald-700 text-center -mt-2"></div>
        </div>
    </form>

    <article class="page-form">
        <div class="page-form-header">
            <div class="page-form-icon">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
            </div>
            <h2>Sistem</h2>
        </div>
        <div class="page-form-body">
            <dl class="grid gap-3 text-sm">
                @foreach ([
                    ['Aplikacija', 'FTTH Manager'],
                    ['Verzija', '1.0.0'],
                    ['Mapa', 'Leaflet + Esri / OpenStreetMap'],
                    ['Okruženje', app()->environment()],
                    ['PHP', PHP_VERSION],
                ] as [$k, $v])
                    <div class="flex items-center justify-between rounded-lg border border-slate-100 bg-slate-50 px-3 py-2">
                        <dt class="text-slate-500 font-medium text-xs uppercase tracking-wide">{{ $k }}</dt>
                        <dd class="font-semibold text-slate-900 text-xs">{{ $v }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </article>
</section>

@push('scripts')
<script>
(function () {
    const form = document.getElementById('display-settings');
    const fields = ['compactTables', 'smallMarkers', 'notifications'];
    const saved = JSON.parse(localStorage.getItem('ftthSettings') || '{}');
    fields.forEach(name => { if (saved[name] !== undefined) form.elements[name].checked = Boolean(saved[name]); });
    form.addEventListener('submit', e => {
        e.preventDefault();
        const next = Object.fromEntries(fields.map(name => [name, form.elements[name].checked]));
        localStorage.setItem('ftthSettings', JSON.stringify(next));
        document.documentElement.classList.toggle('compact-tables', next.compactTables);
        document.documentElement.classList.toggle('small-markers', next.smallMarkers);
        document.documentElement.classList.toggle('hide-header-notifications', !next.notifications);
        document.getElementById('settings-status').textContent = 'Postavke su sačuvane.';
    });
})();
</script>
@endpush
@endsection
