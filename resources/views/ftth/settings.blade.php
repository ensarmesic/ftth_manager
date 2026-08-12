@extends('ftth.layout')
@section('title', 'Postavke')
@section('subtitle', 'Lokalne postavke prikaza FTTH Manager aplikacije.')
@section('content')

<section class="settings-shell">

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

    <div class="settings-stack">
        <article class="page-form">
            <div class="page-form-header">
                <div class="page-form-icon">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 2a4 4 0 00-4 4v2H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-1V6a4 4 0 00-4-4zm2 6V6a2 2 0 10-4 0v2h4z" clip-rule="evenodd"/></svg>
                </div>
                <h2>Sigurnost računa</h2>
            </div>
            <form method="POST" action="{{ route('password.update') }}" class="page-form-body account-security-form">
                @csrf
                @method('PUT')
                <div class="account-security-note">
                    <div>
                        <b>Zaštiti administratorski pristup</b>
                        <span>Koristi jedinstvenu lozinku koju ne koristiš na drugim servisima.</span>
                    </div>
                    <span class="account-security-badge">12+ znakova</span>
                </div>
                <label class="grid gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Trenutna lozinka
                    <input type="password" name="current_password" autocomplete="current-password" class="field-input normal-case tracking-normal" required>
                </label>
                <label class="grid gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Nova lozinka
                    <input type="password" name="password" autocomplete="new-password" class="field-input normal-case tracking-normal" required>
                </label>
                <label class="grid gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Ponovi novu lozinku
                    <input type="password" name="password_confirmation" autocomplete="new-password" class="field-input normal-case tracking-normal" required>
                </label>
                <button class="btn-save">Promijeni lozinku</button>
                <p class="text-center text-xs text-slate-400">Najmanje 12 znakova, velika i mala slova te broj.</p>
            </form>
        </article>

        <article class="page-form">
            <div class="page-form-header">
                <div class="page-form-icon">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9.69 18.933l.003.001C9.89 19.02 10 19 10 19s.11.02.308-.066l.002-.001.006-.003.018-.008a5.741 5.741 0 00.281-.145c.186-.1.446-.25.757-.448.62-.394 1.445-1.012 2.274-1.84C15.302 14.833 17 12.352 17 9A7 7 0 103 9c0 3.352 1.698 5.833 3.354 7.489a15.31 15.31 0 002.274 1.84 11.78 11.78 0 00.976.544l.066.03.018.008.006.003zM10 11.5A2.5 2.5 0 1010 6a2.5 2.5 0 000 5.5z" clip-rule="evenodd"/></svg>
                </div>
                <h2>GIS import cesta</h2>
            </div>
            <form method="POST" action="{{ route('gis.import') }}" enctype="multipart/form-data" class="page-form-body">
                @csrf
                <div class="grid gap-2">
                    <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Projekat</label>
                    <select name="project_id" id="gis-project-select" class="field-input" required>
                        @foreach(\App\Models\Project::orderBy('name')->get() as $project)
                            <option value="{{ $project->id }}">{{ $project->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid gap-2">
                    <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">GeoJSON fajl</label>
                    <input type="file" name="geojson" accept=".geojson,.json,application/geo+json,application/json" class="field-input" required>
                </div>
                <div class="grid gap-2 sm:grid-cols-2">
                    <label class="grid gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Tip sloja
                        <select name="segment_type" class="field-input normal-case tracking-normal">
                            <option value="road">Ceste / putevi</option>
                            <option value="corridor">Dozvoljeni koridor</option>
                            <option value="sidewalk">Trotoar / ivica puta</option>
                            <option value="restricted">Zabranjena zona</option>
                        </select>
                    </label>
                    <label class="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700">
                        <input type="checkbox" name="replace_existing" value="1" class="w-4 h-4 rounded accent-blue-600">
                        Zamijeni postojeći sloj
                    </label>
                </div>
                <button class="btn-save">Učitaj GIS sloj</button>
                <p class="text-xs text-slate-400 text-center -mt-1">Podržani su LineString/MultiLineString za ceste i Polygon/MultiPolygon za zabranjene zone. Interni GIS graf koristi ove slojeve za automatsko praćenje ceste bez prelaska preko privatnih parcela.</p>
            </form>
            <div class="page-form-body pt-0">
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Uvezeni slojevi (odabrani projekat)</label>
                <div id="gis-layers-list" class="grid gap-1.5 text-sm"></div>
            </div>
        </article>

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

    <article class="page-form lg:col-span-2">
        <div class="page-form-header"><div class="page-form-icon">↺</div><div><h2>Audit promjena</h2><p class="text-xs text-slate-400">Posljednjih 50 uspješnih izmjena u aplikaciji.</p></div></div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-3">Vrijeme</th><th class="px-4 py-3">Korisnik</th><th class="px-4 py-3">Akcija</th><th class="px-4 py-3">Ruta</th><th class="px-4 py-3">Status</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($activityLogs as $log)
                        <tr><td class="px-4 py-3 text-slate-500">{{ $log->created_at->format('d.m.Y H:i:s') }}</td><td class="px-4 py-3 font-semibold text-slate-700">{{ $log->user?->name ?? 'Sistem' }}</td><td class="px-4 py-3"><span class="rounded bg-slate-100 px-2 py-1 font-bold">{{ $log->method }}</span></td><td class="px-4 py-3 text-slate-600">{{ $log->route_name ?? $log->path }}</td><td class="px-4 py-3 text-emerald-700">{{ $log->status_code }}</td></tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">Još nema zabilježenih promjena.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </article>
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

(function () {
    const select = document.getElementById('gis-project-select');
    const list = document.getElementById('gis-layers-list');
    if (!select || !list) return;

    const typeLabels = {
        road: 'Ceste / putevi',
        corridor: 'Dozvoljeni koridor',
        sidewalk: 'Trotoar / ivica puta',
        restricted: 'Zabranjena zona (linije)',
        restricted_areas: 'Zabranjena zona (poligoni)',
    };
    const layersUrlBase = @json(url('/postavke/gis/__ID__/slojevi'));

    async function loadLayers() {
        const projectId = select.value;
        if (!projectId) { list.innerHTML = ''; return; }
        list.innerHTML = '<div class="text-xs text-slate-400">Učitavanje...</div>';
        try {
            const response = await fetch(layersUrlBase.replace('__ID__', projectId), { headers: { Accept: 'application/json' } });
            const data = await response.json();
            renderLayers(data.layers || []);
        } catch (e) {
            list.innerHTML = '<div class="text-xs text-red-600">Greška pri učitavanju slojeva.</div>';
        }
    }

    function renderLayers(layers) {
        if (!layers.length) {
            list.innerHTML = '<div class="text-xs text-slate-400">Nema uvezenih slojeva za ovaj projekat.</div>';
            return;
        }
        list.innerHTML = layers.map(layer => `
            <div class="flex items-center justify-between gap-3 rounded-lg border border-slate-100 bg-slate-50 px-3 py-2">
                <div class="min-w-0">
                    <div class="text-xs font-semibold text-slate-700">${typeLabels[layer.type] || layer.type}</div>
                    <div class="text-xs text-slate-400">${layer.count} objekata${layer.length_m !== null ? ' · ' + layer.length_m + ' m' : ''}</div>
                </div>
                <button type="button" data-delete-layer="${layer.type}" class="shrink-0 rounded-lg border border-red-200 bg-red-50 px-2.5 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">Obriši</button>
            </div>
        `).join('');
    }

    list.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-delete-layer]');
        if (!button) return;
        const type = button.dataset.deleteLayer;
        if (!confirm(`Obrisati sloj "${typeLabels[type] || type}" za odabrani projekat?`)) return;
        button.disabled = true;
        try {
            const response = await fetch(layersUrlBase.replace('__ID__', select.value) + '/' + type, {
                method: 'DELETE',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (!response.ok) throw new Error('Brisanje nije uspjelo.');
            await loadLayers();
        } catch (e) {
            alert(e.message);
            button.disabled = false;
        }
    });

    select.addEventListener('change', loadLayers);
    loadLayers();
})();
</script>
@endpush
@endsection
