@extends('ftth.layout')
@section('title', 'Postavke')
@section('subtitle', 'Lokalne postavke prikaza FTTH Manager aplikacije.')
@section('content')

<section class="grid gap-5 xl:grid-cols-[480px_1fr]">

    {{-- Display settings --}}
    <form id="display-settings" class="page-form">
        <div class="page-form-header">
            <div class="page-form-icon">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/></svg>
            </div>
            <h2>Postavke prikaza</h2>
        </div>
        <div class="page-form-body">
            <p class="text-xs text-slate-400 -mt-1 mb-1">Postavke se čuvaju lokalno u ovom browseru i ne utječu na druge korisnike.</p>

            <div class="grid gap-2">
                @foreach([
                    ['compactTables', 'Kompaktni prikaz tabela', 'Smanjeni razmak redova u svim tabelama', false],
                    ['smallMarkers', 'Smanjene oznake na mapi', 'Manji markeri za ODF, ODO i kuće na mapi', true],
                    ['notifications', 'Brojač obavještenja u headeru', 'Prikazuje crveni badge na zvonu kada ima upozorenja', true],
                    ['showOccupancyColors', 'Boje ODO ormarića po zauzetosti', 'Zelena/žuta/crvena boja prema popunjenosti kapaciteta', true],
                ] as [$name, $label, $desc, $default])
                <label class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 cursor-pointer hover:bg-slate-100 transition-colors">
                    <div>
                        <div class="text-sm font-medium text-slate-700">{{ $label }}</div>
                        <div class="text-xs text-slate-400 mt-0.5">{{ $desc }}</div>
                    </div>
                    <input type="checkbox" name="{{ $name }}" {{ $default ? 'checked' : '' }} class="w-4 h-4 rounded accent-blue-600 shrink-0">
                </label>
                @endforeach
            </div>

            <button class="btn-save">Sačuvaj postavke</button>
            <div id="settings-status" class="text-sm font-semibold text-emerald-700 text-center -mt-2 min-h-5"></div>
        </div>
    </form>

    <div class="grid gap-5 content-start">
        {{-- System info --}}
        <article class="page-form">
            <div class="page-form-header">
                <div class="page-form-icon">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                </div>
                <h2>Sistem</h2>
            </div>
            <div class="page-form-body">
                <dl class="grid gap-2 text-sm">
                    @foreach ([
                        ['Aplikacija', 'FTTH Manager'],
                        ['Verzija', '1.8.0'],
                        ['Mapa', 'Leaflet + Esri / OpenStreetMap'],
                        ['Baza podataka', 'SQLite'],
                        ['Okruženje', app()->environment()],
                        ['PHP verzija', PHP_VERSION],
                        ['Laravel', app()->version()],
                    ] as [$k, $v])
                        <div class="flex items-center justify-between rounded-lg border border-slate-100 bg-slate-50 px-3 py-2">
                            <dt class="text-slate-500 font-medium text-xs uppercase tracking-wide">{{ $k }}</dt>
                            <dd class="font-semibold text-slate-900 text-xs">{{ $v }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </article>

        {{-- Quick links --}}
        <article class="page-form">
            <div class="page-form-header">
                <div class="page-form-icon">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.586 4.586a2 2 0 112.828 2.828l-3 3a2 2 0 01-2.828 0 1 1 0 00-1.414 1.414 4 4 0 005.656 0l3-3a4 4 0 00-5.656-5.656l-1.5 1.5a1 1 0 101.414 1.414l1.5-1.5zm-5 5a2 2 0 012.828 0 1 1 0 101.414-1.414 4 4 0 00-5.656 0l-3 3a4 4 0 105.656 5.656l1.5-1.5a1 1 0 10-1.414-1.414l-1.5 1.5a2 2 0 11-2.828-2.828l3-3z" clip-rule="evenodd"/></svg>
                </div>
                <h2>Brzi pristup</h2>
            </div>
            <div class="page-form-body">
                <div class="grid gap-2">
                    @foreach([
                        [route('project-check.index'), 'Provjera projekta', 'Provjeri tehničku ispravnost svih projekata'],
                        [route('reports.index'), 'Izvještaji', 'Pregled statusa, kapaciteta i materijala'],
                        [route('fiber-schema.index'), 'Fiber šema', 'Topološki prikaz optičke mreže'],
                        [route('map.dashboard'), 'Mapa', 'CAD editor i pregled mreže na mapi'],
                    ] as [$url, $lbl, $desc])
                    <a href="{{ $url }}" class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-700 transition-colors hover:bg-blue-50 hover:border-blue-200 hover:text-blue-800">
                        <div class="min-w-0 flex-1">
                            <div class="font-semibold">{{ $lbl }}</div>
                            <div class="text-xs text-slate-400 mt-0.5">{{ $desc }}</div>
                        </div>
                        <svg viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4 shrink-0 text-slate-300"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
                    </a>
                    @endforeach
                </div>
            </div>
        </article>

        {{-- Backup --}}
        <article class="page-form">
            <div class="page-form-header">
                <div class="page-form-icon">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path d="M3 12v3c0 1.657 3.134 3 7 3s7-1.343 7-3v-3c0 1.657-3.134 3-7 3s-7-1.343-7-3z"/><path d="M3 7v3c0 1.657 3.134 3 7 3s7-1.343 7-3V7c0 1.657-3.134 3-7 3S3 8.657 3 7z"/><path d="M17 5c0 1.657-3.134 3-7 3S3 6.657 3 5s3.134-3 7-3 7 1.343 7 3z"/></svg>
                </div>
                <h2>Backup baze</h2>
            </div>
            <div class="page-form-body">
                <dl class="grid gap-2 text-sm mb-4">
                    <div class="flex items-center justify-between rounded-lg border border-slate-100 bg-slate-50 px-3 py-2">
                        <dt class="text-slate-500 font-medium text-xs uppercase tracking-wide">Fajl</dt>
                        <dd class="font-semibold text-slate-900 text-xs">database.sqlite</dd>
                    </div>
                    <div class="flex items-center justify-between rounded-lg border border-slate-100 bg-slate-50 px-3 py-2">
                        <dt class="text-slate-500 font-medium text-xs uppercase tracking-wide">Veličina</dt>
                        <dd class="font-semibold text-slate-900 text-xs">{{ round(filesize(database_path('database.sqlite')) / 1024) }} KB</dd>
                    </div>
                    <div class="flex items-center justify-between rounded-lg border border-slate-100 bg-slate-50 px-3 py-2">
                        <dt class="text-slate-500 font-medium text-xs uppercase tracking-wide">Zadnja izmjena</dt>
                        <dd class="font-semibold text-slate-900 text-xs">{{ date('d.m.Y H:i', filemtime(database_path('database.sqlite'))) }}</dd>
                    </div>
                </dl>
                <a href="{{ route('settings.backup') }}" class="btn-save flex items-center justify-center gap-2 no-underline">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                    Preuzmi backup
                </a>
                <p class="text-xs text-slate-400 text-center -mt-1">Preuzima SQLite fajl s trenutnim timestampom. Čuvaj na sigurnom mjestu.</p>
            </div>
        </article>
    </div>
</section>

@push('scripts')
<script>
(function () {
    const form   = document.getElementById('display-settings');
    const fields = ['compactTables', 'smallMarkers', 'notifications', 'showOccupancyColors'];
    const saved  = JSON.parse(localStorage.getItem('ftthSettings') || '{}');
    fields.forEach(name => {
        const el = form.elements[name];
        if (el && saved[name] !== undefined) el.checked = Boolean(saved[name]);
    });
    form.addEventListener('submit', e => {
        e.preventDefault();
        const next = Object.fromEntries(fields.map(name => [name, form.elements[name]?.checked ?? false]));
        localStorage.setItem('ftthSettings', JSON.stringify(next));
        document.documentElement.classList.toggle('compact-tables', next.compactTables);
        document.documentElement.classList.toggle('small-markers', next.smallMarkers);
        document.documentElement.classList.toggle('hide-header-notifications', !next.notifications);
        const st = document.getElementById('settings-status');
        st.textContent = 'Postavke su sačuvane.';
        setTimeout(() => { st.textContent = ''; }, 3000);
    });
})();
</script>
@endpush
@endsection
