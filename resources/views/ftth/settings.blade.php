@extends('ftth.layout')
@section('title', 'Postavke')
@section('subtitle', 'Upravljanje prikazom, sigurnošću, GIS podacima i održavanjem sistema.')
@section('content')

<section class="settings-hub">
    <header class="settings-overview">
        <div class="settings-overview-copy">
            <span class="settings-eyebrow">Kontrolni centar sistema</span>
            <h2>Sve postavke na jednom mjestu</h2>
            <p>Prilagodi radno okruženje, zaštiti račun i upravljaj tehničkim podacima aplikacije.</p>
        </div>
        <div class="settings-overview-stats">
            <div><b>{{ $projects->count() }}</b><span>projekata</span></div>
            <div><b>{{ $activityLogs->count() }}</b><span>zadnjih izmjena</span></div>
            <div><b class="text-emerald-600">Aktivan</b><span>{{ app()->environment() }} sistem</span></div>
        </div>
    </header>

    <div class="settings-layout">
        <aside class="settings-nav" aria-label="Sekcije postavki">
            <div class="settings-nav-title">Postavke</div>
            @foreach ([
                ['display', 'Prikaz aplikacije', 'Izgled i ponašanje'],
                ['security', 'Sigurnost računa', 'Promjena lozinke'],
                ['gis', 'GIS slojevi', 'Uvoz prostornih podataka'],
                ['maintenance', 'Sistem i backup', 'Status i sigurnosna kopija'],
                ['audit', 'Audit promjena', 'Evidencija aktivnosti'],
            ] as [$anchor, $label, $description])
                <a href="#settings-{{ $anchor }}" class="settings-nav-link {{ $loop->first ? 'is-active' : '' }}">
                    <span class="settings-nav-index">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                    <span><b>{{ $label }}</b><small>{{ $description }}</small></span>
                </a>
            @endforeach
            <div class="settings-nav-note"><b>Lokalne postavke</b><span>Opcije prikaza vrijede samo u ovom browseru.</span></div>
        </aside>

        <div class="settings-content">
            <form id="settings-display" class="settings-panel" data-settings-section>
                <x-settings-heading title="Prikaz aplikacije" description="Odaberi kako će podaci i mrežni elementi biti prikazani." icon="display" />
                <div class="settings-panel-body">
                    <div class="settings-toggle-grid">
                        @foreach([
                            ['compactTables', 'Kompaktne tabele', 'Više podataka na ekranu uz smanjenu visinu redova.', false],
                            ['smallMarkers', 'Manje oznake na mapi', 'Kompaktniji ODF, ODO i korisnički markeri.', true],
                            ['notifications', 'Brojač obavještenja', 'Prikaži upozorenja na zvonu u gornjoj traci.', true],
                            ['showOccupancyColors', 'Boje zauzetosti ODO-a', 'Zelena, žuta i crvena prema popunjenosti.', true],
                        ] as [$name, $label, $desc, $default])
                            <label class="settings-toggle-card">
                                <span><b>{{ $label }}</b><small>{{ $desc }}</small></span>
                                <input type="checkbox" name="{{ $name }}" {{ $default ? 'checked' : '' }}>
                                <i aria-hidden="true"></i>
                            </label>
                        @endforeach
                    </div>
                    <div class="settings-actions">
                        <button class="btn-save" type="submit">Sačuvaj prikaz</button>
                        <span id="settings-status" class="settings-save-status" role="status"></span>
                    </div>
                </div>
            </form>

            <article class="settings-panel" data-settings-section id="settings-security">
                <x-settings-heading title="Sigurnost računa" description="Ažuriraj administratorsku lozinku i zaštiti pristup podacima." icon="security" />
                <form method="POST" action="{{ route('password.update') }}" class="settings-panel-body">
                    @csrf @method('PUT')
                    <div class="settings-security-banner"><div><b>Preporučena jaka lozinka</b><span>Najmanje 12 znakova, velika i mala slova te broj.</span></div><span>12+ znakova</span></div>
                    <div class="settings-fields-grid">
                        <label><span>Trenutna lozinka</span><input type="password" name="current_password" autocomplete="current-password" class="field-input" required></label>
                        <label><span>Nova lozinka</span><input type="password" name="password" autocomplete="new-password" class="field-input" required></label>
                        <label><span>Ponovi novu lozinku</span><input type="password" name="password_confirmation" autocomplete="new-password" class="field-input" required></label>
                    </div>
                    <div class="settings-actions"><button class="btn-save">Promijeni lozinku</button></div>
                </form>
                <div class="settings-panel-body" style="border-top:1px solid #e2e8f0">
                    <div class="settings-security-banner">
                        <div><b>Dvofaktorska autentifikacija (2FA)</b><span>Dodatni šestocifreni kod štiti administratorski račun i ako lozinka procuri.</span></div>
                        <span>{{ auth()->user()->two_factor_confirmed_at ? 'Uključena' : 'Isključena' }}</span>
                    </div>
                    @if (auth()->user()->two_factor_confirmed_at)
                        <form method="POST" action="{{ route('two-factor.destroy') }}" class="settings-fields-grid">
                            @csrf @method('DELETE')
                            <label><span>Trenutna lozinka za isključivanje</span><input type="password" name="current_password" autocomplete="current-password" class="field-input" required></label>
                            <div class="settings-actions"><button class="btn-save" style="background:#b91c1c">Isključi 2FA</button></div>
                        </form>
                    @else
                        <div class="settings-actions"><a href="{{ route('two-factor.setup') }}" class="btn-save">Postavi authenticator</a></div>
                    @endif
                </div>
            </article>

            <article class="settings-panel" data-settings-section id="settings-gis">
                <x-settings-heading title="GIS slojevi" description="Uvezi cestovne koridore i ograničenja koja vode automatsko trasiranje." icon="gis" />
                <form method="POST" action="{{ route('gis.import') }}" enctype="multipart/form-data" class="settings-panel-body">
                    @csrf
                    <div class="settings-fields-grid settings-fields-grid-gis">
                        <label><span>Projekat</span><select name="project_id" id="gis-project-select" class="field-input" required>@forelse($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@empty<option value="">Nema dostupnih projekata</option>@endforelse</select></label>
                        <label><span>Tip sloja</span><select name="segment_type" class="field-input"><option value="road">Ceste / putevi</option><option value="corridor">Dozvoljeni koridor</option><option value="sidewalk">Trotoar / ivica puta</option><option value="restricted">Zabranjena zona</option></select></label>
                        <label class="settings-file-field"><span>GeoJSON fajl</span><input type="file" name="geojson" accept=".geojson,.json,application/geo+json,application/json" class="field-input" required></label>
                    </div>
                    <label class="settings-check"><input type="checkbox" name="replace_existing" value="1"><span><b>Zamijeni postojeći sloj</b><small>Prethodni podaci istog tipa bit će uklonjeni.</small></span></label>
                    <div class="settings-actions"><button class="btn-save" {{ $projects->isEmpty() ? 'disabled' : '' }}>Učitaj GIS sloj</button><small>GeoJSON · LineString, MultiLineString i Polygon</small></div>
                </form>
                <div class="settings-layers"><div><b>Uvezeni slojevi</b><span>Za trenutno odabrani projekat</span></div><div id="gis-layers-list" class="settings-layer-list"></div></div>
            </article>

            <div class="settings-maintenance-grid" data-settings-section id="settings-maintenance">
                <article class="settings-panel">
                    <x-settings-heading title="Status sistema" description="Tehničke informacije trenutne instalacije." icon="system" />
                    <div class="settings-panel-body"><dl class="settings-facts">
                        @foreach ([['Aplikacija', 'FTTH Manager'], ['Verzija', '1.8.0'], ['Mapa', 'Leaflet + Esri / OSM'], ['Baza', 'SQLite'], ['Okruženje', app()->environment()], ['PHP', PHP_VERSION], ['Laravel', app()->version()]] as [$key, $value])
                            <div><dt>{{ $key }}</dt><dd>{{ $value }}</dd></div>
                        @endforeach
                    </dl></div>
                </article>
                <article class="settings-panel settings-backup-panel">
                    <x-settings-heading title="Sigurnosna kopija" description="Preuzmi kompletnu kopiju baze podataka." icon="backup" />
                    <div class="settings-panel-body">
                        <div class="settings-backup-visual"><span>DB</span><div><b>database.sqlite</b><small>{{ $databaseInfo['exists'] ? number_format($databaseInfo['size'] / 1024, 0, ',', '.') . ' KB' : 'Baza nije pronađena' }}</small></div></div>
                        @if($databaseInfo['modifiedAt'])<p>Zadnja izmjena: <b>{{ date('d.m.Y. H:i', $databaseInfo['modifiedAt']) }}</b></p>@endif
                        <a href="{{ route('settings.backup') }}" class="btn-save {{ $databaseInfo['exists'] ? '' : 'pointer-events-none opacity-50' }}">Preuzmi backup</a>
                    </div>
                </article>
            </div>

            <article class="settings-panel" data-settings-section id="settings-audit">
                <x-settings-heading title="Audit promjena" description="Posljednjih 50 uspješnih izmjena u aplikaciji." icon="audit" />
                <div class="settings-audit-wrap"><table class="settings-audit-table"><thead><tr><th>Vrijeme</th><th>Korisnik</th><th>Akcija</th><th>Ruta</th><th>Status</th></tr></thead><tbody>
                    @forelse($activityLogs as $log)<tr><td>{{ $log->created_at->format('d.m.Y H:i:s') }}</td><td><b>{{ $log->user?->name ?? 'Sistem' }}</b></td><td><span class="settings-method">{{ $log->method }}</span></td><td>{{ $log->route_name ?? $log->path }}</td><td><span class="settings-status-dot"></span>{{ $log->status_code }}</td></tr>@empty<tr><td colspan="5" class="settings-empty">Još nema zabilježenih promjena.</td></tr>@endforelse
                </tbody></table></div>
            </article>
        </div>
    </div>
</section>

@push('scripts')
<script>
(function () {
    const form = document.getElementById('settings-display');
    const fields = ['compactTables', 'smallMarkers', 'notifications', 'showOccupancyColors'];
    let saved = {};
    try { saved = JSON.parse(localStorage.getItem('ftthSettings') || '{}'); } catch (_) {}
    fields.forEach(name => { if (saved[name] !== undefined) form.elements[name].checked = Boolean(saved[name]); });
    form.addEventListener('submit', event => {
        event.preventDefault();
        const next = Object.fromEntries(fields.map(name => [name, form.elements[name]?.checked ?? false]));
        localStorage.setItem('ftthSettings', JSON.stringify(next));
        document.documentElement.classList.toggle('compact-tables', next.compactTables);
        document.documentElement.classList.toggle('small-markers', next.smallMarkers);
        document.documentElement.classList.toggle('hide-header-notifications', !next.notifications);
        const status = document.getElementById('settings-status');
        status.textContent = '✓ Postavke su sačuvane';
        setTimeout(() => { status.textContent = ''; }, 3000);
    });
})();

(function () {
    const select = document.getElementById('gis-project-select');
    const list = document.getElementById('gis-layers-list');
    if (!select || !list || !select.value) { if (list) list.innerHTML = '<div class="settings-empty-state">Nema dostupnih projekata.</div>'; return; }
    const labels = {road:'Ceste / putevi', corridor:'Dozvoljeni koridor', sidewalk:'Trotoar / ivica puta', restricted:'Zabranjena zona (linije)', restricted_areas:'Zabranjena zona (poligoni)'};
    const base = @json(url('/postavke/gis/__ID__/slojevi'));
    async function load() {
        list.innerHTML = '<div class="settings-empty-state">Učitavanje slojeva...</div>';
        try { const response = await fetch(base.replace('__ID__', select.value), {headers:{Accept:'application/json'}}); if (!response.ok) throw new Error(); const data = await response.json(); render(data.layers || []); }
        catch (_) { list.innerHTML = '<div class="settings-empty-state is-error">Slojevi trenutno nisu dostupni.</div>'; }
    }
    function render(layers) {
        if (!layers.length) { list.innerHTML = '<div class="settings-empty-state">Nema uvezenih slojeva za ovaj projekat.</div>'; return; }
        list.innerHTML = layers.map(layer => `<div class="settings-layer"><span><b>${labels[layer.type] || layer.type}</b><small>${layer.count} objekata${layer.length_m !== null ? ' · '+layer.length_m+' m' : ''}</small></span><button type="button" data-delete-layer="${layer.type}">Obriši</button></div>`).join('');
    }
    list.addEventListener('click', async event => {
        const button = event.target.closest('[data-delete-layer]'); if (!button || !await window.ftthConfirm(`Obrisati sloj "${labels[button.dataset.deleteLayer] || button.dataset.deleteLayer}"?`, {title:'Brisanje GIS sloja',detail:'Automatsko GIS planiranje više neće koristiti podatke tog sloja.',confirmLabel:'Obriši sloj',danger:true})) return;
        button.disabled = true;
        try { const response = await fetch(`${base.replace('__ID__', select.value)}/${button.dataset.deleteLayer}`, {method:'DELETE', headers:{Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content || '','X-Requested-With':'XMLHttpRequest'}}); if (!response.ok) throw new Error('Brisanje nije uspjelo.'); await load(); }
        catch (error) { alert(error.message); button.disabled = false; }
    });
    select.addEventListener('change', load); load();
})();

(function () {
    const links = [...document.querySelectorAll('.settings-nav-link')];
    const sections = links.map(link => document.querySelector(link.hash)).filter(Boolean);
    if (!('IntersectionObserver' in window)) return;
    const observer = new IntersectionObserver(entries => entries.forEach(entry => { if (entry.isIntersecting) { links.forEach(link => link.classList.toggle('is-active', link.hash === `#${entry.target.id}`)); } }), {root:document.querySelector('.app-content'), rootMargin:'-15% 0px -70% 0px'});
    sections.forEach(section => observer.observe(section));
})();
</script>
@endpush
@endsection
